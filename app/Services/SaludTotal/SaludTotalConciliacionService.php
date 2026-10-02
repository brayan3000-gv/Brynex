<?php

namespace App\Services\SaludTotal;

use App\Models\Radicado;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pone al día los radicados de EPS de Salud Total con lo que dice el portal.
 *
 * De paso baja el grupo familiar de quien quede afiliado: Salud Total solo lo
 * muestra cuando la persona ya está ligada a la empresa, así que al radicar la
 * novedad viene vacío y es aquí —con la afiliación ya aprobada— donde se puede
 * guardar en BryNex.
 *
 * Por empresa, una sola consulta al seguimiento de novedades de inicio laboral:
 * a cada radicado abierto le pone el número de formulario y el estado (Aprobado
 * → ok con el certificado, En Validación → trámite con el Formulario Único, con
 * inconsistencias → error con el motivo). Si no hay novedad pero la persona ya
 * está activa con la empresa (grupo familiar con contrato vigente), lo cierra.
 *
 * Solo lee en Salud Total. Solo dependientes.
 */
class SaludTotalConciliacionService
{
    private const ESTADOS = [Radicado::ESTADO_PENDIENTE, Radicado::ESTADO_TRAMITE, Radicado::ESTADO_ERROR];

    public function __construct(private SaludTotalNovedadService $novedad) {}

    /** @return Collection<int, Radicado> */
    public function pendientes(int $aliadoId, ?string $nit = null): Collection
    {
        $nit = $nit ? preg_replace('/\D/', '', $nit) : null;

        return Radicado::query()
            ->where('aliado_id', $aliadoId)
            ->where('tipo', Radicado::TIPO_EPS)
            ->whereIn('estado', self::ESTADOS)
            ->whereHas('contrato', fn ($c) => $c
                ->where('aliado_id', $aliadoId)
                ->where('estado', 'vigente')
                ->whereHas('eps', fn ($e) => $e->where('codigo', SaludTotalNovedadService::CODIGO_EPS))
                ->whereHas('razonSocial', fn ($rs) => $rs
                    ->where('es_independiente', false)
                    ->when($nit, fn ($q) => $q->where('nit', $nit))))
            ->with(['contrato.cliente', 'contrato.razonSocial'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  callable|null  $progreso  fn(string $mensaje, array $parcial)
     */
    public function conciliar(int $aliadoId, ?string $nit = null, bool $simular = false, ?int $usuarioId = null, ?callable $progreso = null): array
    {
        $avisar  = $progreso ?? fn () => null;
        $detalle = [];

        $porEmpresa = $this->pendientes($aliadoId, $nit)
            ->groupBy(fn (Radicado $r) => preg_replace('/\D/', '', (string) $r->contrato->razonSocial->nit));

        $total = $porEmpresa->flatten()->count();
        $avisar("{$total} radicados de Salud Total por revisar en {$porEmpresa->count()} empresas.", $detalle);

        foreach ($porEmpresa as $nitEmpresa => $radicados) {
            $empresa = $radicados->first()->contrato->razonSocial->razon_social;
            $avisar("{$empresa}: consultando novedades en Salud Total…", $detalle);

            try {
                $st = $this->novedad->sesion((string) $nitEmpresa);
                $desde = $radicados->map(fn (Radicado $r) => $r->contrato->fecha_ingreso ?? today())->min()->copy()->subDays(45);
                $lista = $st->seguimiento($desde->toDateString(), today()->addDay()->toDateString());
            } catch (Throwable $e) {
                foreach ($radicados as $r) {
                    $detalle[] = $this->fila($r, 'error', $e->getMessage());
                }
                $avisar("{$empresa}: {$e->getMessage()}", $detalle);

                continue;
            }

            foreach ($radicados as $r) {
                try {
                    $detalle[] = $this->conciliarUno($st, $r, $lista, $simular, $usuarioId);
                } catch (Throwable $e) {
                    $detalle[] = $this->fila($r, 'error', $e->getMessage());
                }
            }

            $avisar("{$empresa}: listo.", $detalle);
        }

        $cuenta = collect($detalle)->countBy('accion');

        return [
            'total'    => $total,
            'cerrados' => $cuenta->get('cerrado', 0) + $cuenta->get('cerraria', 0),
            'tramite'  => $cuenta->get('tramite', 0),
            'faltan'   => $cuenta->get('falta', 0),
            'revisar'  => $cuenta->get('revisar', 0),
            'errores'  => $cuenta->get('error', 0),
            'simulado' => $simular,
            'detalle'  => $detalle,
        ];
    }

    private function conciliarUno(SaludTotalCliente $st, Radicado $r, array $lista, bool $simular, ?int $usuarioId): array
    {
        $c = $r->contrato;
        $documento = preg_replace('/\D/', '', (string) $c->cedula);
        $tipoDoc = strtoupper((string) $c->cliente?->tipo_doc);
        $n = $this->novedad->novedadExistente($st, $documento, $c->fecha_ingreso, $lista);

        if ($n) {
            $mensaje = "Salud Total: formulario {$n['numero']} ({$n['estado']}, ingreso {$n['fecha_ingreso']}).";
            $estado  = strtolower($n['estado']);

            if ($simular) {
                $accion = match (true) {
                    str_contains($estado, 'aprobad')                           => 'cerraria',
                    $n['inconsistencia'] || str_contains($estado, 'rechaz')    => 'revisar',
                    default                                                    => 'tramite',
                };

                return $this->fila($r, $accion, $mensaje);
            }

            $aplicado = $this->novedad->aplicarNovedad($st, $c, $r, $n, $usuarioId, 'Conciliación con Salud Total');

            // Aprobada: ya está ligada a la empresa, así que ahora sí hay grupo
            // familiar que bajar. Las que siguen en validación todavía no lo
            // tienen, y volverán a pasar por aquí en la próxima conciliación.
            if ($aplicado === 'ok' && isset(SaludTotalCliente::TIPOS_DOC[$tipoDoc])) {
                $mensaje .= $this->bajarFamilia($st, $c, $tipoDoc, $documento, $simular);
            }

            return $this->fila($r, ['ok' => 'cerrado', 'error' => 'revisar'][$aplicado] ?? 'tramite', $mensaje);
        }

        if (! isset(SaludTotalCliente::TIPOS_DOC[$tipoDoc])) {
            return $this->fila($r, 'revisar', "Tipo de documento '{$tipoDoc}' sin equivalencia en Salud Total.");
        }

        $grupo = $st->grupoFamiliar($tipoDoc, $documento);
        $titular = collect($grupo)->first(fn ($g) => (string) ($g['BeneficiarioId'] ?? '') === $documento);

        $activo = $titular && ($titular['TieneContratoVigente'] ?? false)
            && str_starts_with(strtolower((string) ($titular['EstadoGeneral'] ?? '')), 'activo');

        if (! $activo) {
            return $this->fila($r, 'falta', 'Sin novedad de inicio laboral ni afiliación activa con la empresa en Salud Total: falta radicarla.');
        }

        $desde   = substr((string) ($titular['FechaAfiliacion'] ?? ''), 0, 10);
        $mensaje = "Activo en Salud Total con la empresa desde {$desde}, sin novedad reciente.";

        if (! $simular) {
            $this->novedad->marcarRadicado($r, null, Radicado::ESTADO_OK, null, "Conciliación con Salud Total: {$mensaje}", $usuarioId);
        }

        // El grupo familiar ya está consultado: guardarlo no cuesta otra vuelta al portal.
        $mensaje .= $this->guardados($simular ? ['nuevos' => 0, 'total' => 0] : $this->novedad->guardarBeneficiarios($c, $grupo), $simular, count($grupo) - 1);

        return $this->fila($r, $simular ? 'cerraria' : 'cerrado', $mensaje);
    }

    /** Baja el grupo familiar de quien ya quedó afiliado y cuenta qué se guardó. */
    private function bajarFamilia(SaludTotalCliente $st, $contrato, string $tipoDoc, string $documento, bool $simular): string
    {
        try {
            $grupo = $st->grupoFamiliar($tipoDoc, $documento);
        } catch (Throwable $e) {
            // El grupo familiar es un extra: que falle no puede tumbar el cierre
            // del radicado, que es lo que de verdad importa de la conciliación.
            return '';
        }

        return $this->guardados(
            $simular ? ['nuevos' => 0, 'total' => 0] : $this->novedad->guardarBeneficiarios($contrato, $grupo),
            $simular,
            max(0, count($grupo) - 1)
        );
    }

    /** La frase del grupo familiar para el detalle, o nada si no tiene beneficiarios. */
    private function guardados(array $r, bool $simular, int $enElPortal): string
    {
        if ($simular) {
            return $enElPortal ? " Grupo familiar: {$enElPortal} beneficiarios en el portal." : '';
        }

        if (! $r['total']) {
            return '';
        }

        return " Grupo familiar: {$r['total']} beneficiarios".($r['nuevos'] ? ", {$r['nuevos']} nuevos en BryNex." : ', ya estaban en BryNex.');
    }

    private function fila(Radicado $r, string $accion, string $mensaje): array
    {
        $c = $r->contrato;

        return [
            'radicado_id'  => $r->id,
            'contrato_id'  => $c->id,
            'cedula'       => (string) $c->cedula,
            'nombre'       => trim(($c->cliente?->primer_nombre ?? '').' '.($c->cliente?->primer_apellido ?? '')),
            'empresa'      => $c->razonSocial?->razon_social,
            'estado_antes' => $r->estado,
            'accion'       => $accion,
            'mensaje'      => $mensaje,
        ];
    }
}
