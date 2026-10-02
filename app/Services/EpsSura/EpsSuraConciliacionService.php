<?php

namespace App\Services\EpsSura;

use App\Models\Radicado;
use App\Models\RadicadoMovimiento;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Cierra los radicados de EPS SURA que siguen pendientes en BryNex pero que en
 * el portal ya están hechos.
 *
 * El 14-sep-2026 los cuatro pendientes de EPS SURA de ELITES CREACIONES ya eran
 * cotizantes vigentes en Sura: alguien los afilió y nadie marcó el radicado. Por
 * eso, antes de automatizar reingresos, conviene preguntarle al portal qué falta
 * de verdad. Esta conciliación solo LEE en Sura; lo único que escribe es el
 * radicado en BryNex, y solo cuando el portal confirma la vigencia con esa
 * empresa y el apellido coincide.
 *
 * Solo dependientes: los independientes entran al portal con su propio usuario
 * y por ahora no se gestionan desde aquí.
 */
class EpsSuraConciliacionService
{
    /** Código oficial de EPS SURA en la tabla `eps`. */
    public const CODIGO_EPS = 'EPS010';

    /** Estados del radicado que todavía esperan gestión. */
    private const ESTADOS = [Radicado::ESTADO_PENDIENTE, Radicado::ESTADO_TRAMITE, Radicado::ESTADO_ERROR];

    /** Cédulas por cada proceso de Chrome: el login cuesta, pero un proceso eterno se cae. */
    private const POR_LOTE = 20;

    /**
     * Los radicados de EPS SURA abiertos de un aliado, de contratos vigentes de
     * dependientes.
     *
     * @return Collection<int, Radicado>
     */
    public function pendientes(int $aliadoId, ?string $nit = null): Collection
    {
        $nit = $nit ? preg_replace('/\D/', '', $nit) : null;

        return Radicado::query()
            ->where('radicados.aliado_id', $aliadoId)
            ->where('radicados.tipo', Radicado::TIPO_EPS)
            ->whereIn('radicados.estado', self::ESTADOS)
            ->whereHas('contrato', fn ($c) => $c
                ->where('aliado_id', $aliadoId)
                ->where('estado', 'vigente')
                ->whereHas('eps', fn ($e) => $e->where('codigo', self::CODIGO_EPS))
                ->whereHas('razonSocial', fn ($rs) => $rs
                    ->where('es_independiente', false)
                    ->when($nit, fn ($q) => $q->where('nit', $nit))))
            ->with(['contrato.cliente', 'contrato.razonSocial'])
            ->orderBy('radicados.id')
            ->get();
    }

    /**
     * Consulta cada pendiente en el portal y cierra los que ya estén vigentes.
     *
     * @param  bool  $simular  Consulta pero no toca ningún radicado.
     * @param  callable|null  $progreso  fn(string $mensaje, array $parcial)
     * @return array{total:int, cerrados:int, faltan:int, revisar:int, errores:int, detalle:array}
     */
    public function conciliar(int $aliadoId, ?string $nit = null, bool $simular = false, ?int $usuarioId = null, ?callable $progreso = null): array
    {
        $avisar  = $progreso ?? fn () => null;
        $detalle = [];

        $porEmpresa = $this->pendientes($aliadoId, $nit)
            ->groupBy(fn (Radicado $r) => preg_replace('/\D/', '', (string) $r->contrato->razonSocial->nit));

        $total = $porEmpresa->flatten()->count();
        $avisar("{$total} radicados de EPS SURA por revisar en {$porEmpresa->count()} empresas.", $detalle);

        // Las empresas se agrupan por el usuario del portal, no por NIT: SURA
        // admite una sola sesión por usuario y la deja viva un rato después de
        // cerrar el navegador, así que entrar una vez por empresa dejaba fuera a
        // la segunda del mismo representante —LALA CONFECCIONES y LALA GROUP
        // comparten el 1130666712, y el 2-oct-2026 la primera se concilió y la
        // segunda falló un minuto después con «Usted no tiene acceso a los
        // recursos de esta Aplicación»—. Con una sola sesión por usuario, el
        // robot cambia de empresa por dentro y el problema desaparece.
        $porUsuario = [];

        foreach ($porEmpresa as $nitEmpresa => $radicados) {
            $empresa = $radicados->first()->contrato->razonSocial->razon_social;
            $credencial = ArlSuraSesionService::credencialPara($aliadoId, '', (string) $nitEmpresa);

            // Solo usuarios registrados. La cascada también ofrece claves del
            // módulo de claves, pero muchas son relleno ("3000") y Sura bloquea
            // al usuario tras varios intentos fallidos: reintentarlas en cada
            // corrida es la forma de bloquearlo.
            if (! $credencial?->exists) {
                foreach ($radicados as $r) {
                    $detalle[] = $this->fila($r, 'error', 'Esta empresa no tiene usuario del portal de Sura registrado en BryNex.');
                }
                $avisar("{$empresa}: sin usuario del portal.", $detalle);

                continue;
            }

            $llave = $credencial->getKey() ?: $credencial->usuario;
            $porUsuario[$llave] ??= ['credencial' => $credencial, 'empresas' => []];
            $porUsuario[$llave]['empresas'][(string) $nitEmpresa] = ['nombre' => $empresa, 'radicados' => $radicados];
        }

        foreach ($porUsuario as $grupo) {
            $credencial = $grupo['credencial'];
            $nombres = collect($grupo['empresas'])->pluck('nombre')->implode(', ');
            $cuantos = collect($grupo['empresas'])->sum(fn ($e) => $e['radicados']->count());
            $avisar("{$nombres}: consultando {$cuantos} en el portal…", $detalle);

            // Se lee el listado de afiliados de cada empresa, no una consulta por
            // cédula: el listado trae a todos de una vez —incluidos los que la
            // consulta individual niega con «no existe como cotizante», que el
            // 2-oct-2026 eran 35 de LALA GROUP con el reingreso ya aplicado— y
            // además dice la cobertura, que es lo que decide si está vigente.
            $salida = $this->listadoPortal($credencial, array_keys($grupo['empresas']));

            if (! ($salida['ok'] ?? false)) {
                $credencial->update(['ultimo_error' => mb_substr((string) ($salida['error'] ?? ''), 0, 300)]);

                foreach ($grupo['empresas'] as $empresa) {
                    foreach ($empresa['radicados'] as $r) {
                        $detalle[] = $this->fila($r, 'error', 'No se pudo leer el listado del portal: '.($salida['error'] ?? 'sin detalle'));
                    }
                }
                $avisar("{$nombres}: no se pudo leer el listado.", $detalle);

                continue;
            }

            foreach ($grupo['empresas'] as $nitEmpresa => $empresa) {
                $informe = $salida['por_nit'][$nitEmpresa] ?? null;

                if (! $informe) {
                    foreach ($empresa['radicados'] as $r) {
                        $detalle[] = $this->fila($r, 'error', 'El portal no devolvió el listado de esta empresa.');
                    }

                    continue;
                }

                // Un listado corto haría pasar por «no radicado» a quien sí está:
                // mejor no decidir nada que decidir mal.
                if ($informe['incompleto'] ?? false) {
                    foreach ($empresa['radicados'] as $r) {
                        $detalle[] = $this->fila($r, 'error', 'El listado del portal salió incompleto; se revisa en la próxima corrida.');
                    }

                    continue;
                }

                $porCedula = collect($informe['afiliados'] ?? [])
                    ->keyBy(fn ($a) => preg_replace('/\D/', '', (string) ($a['numero'] ?? '')));

                foreach ($empresa['radicados'] as $r) {
                    $cedula = preg_replace('/\D/', '', (string) $r->contrato->cedula);
                    $detalle[] = $this->resolver($r, $porCedula->get($cedula), $simular, $usuarioId);
                }
            }

            $avisar("{$nombres}: listo.", $detalle);
        }

        $cuenta = collect($detalle)->countBy('accion');

        return [
            'total'    => $total,
            'cerrados' => $cuenta->get('cerrado', 0) + $cuenta->get('cerraria', 0),
            'faltan'   => $cuenta->get('falta', 0),
            'revisar'  => $cuenta->get('revisar', 0),
            'errores'  => $cuenta->get('error', 0),
            'simulado' => $simular,
            'detalle'  => $detalle,
        ];
    }

    /**
     * Decide qué hacer con un radicado según lo que respondió el portal.
     */
    private function resolver(Radicado $r, ?array $res, bool $simular, ?int $usuarioId): array
    {
        // Quien no sale en el listado de la empresa no quedó radicado.
        if (! $res) {
            return $this->fila($r, 'falta', 'No aparece en el listado de afiliados de la empresa en EPS SURA: no quedó radicado.');
        }

        $cobertura = self::normalizar((string) ($res['cobertura'] ?? ''));
        $estado = trim((string) ($res['estado'] ?? ''));

        // Solo la cobertura integral es estar vigente. «Protección laboral»
        // —que el portal cuenta como «tiene derecho»— y «sin empleador
        // vigente» son trámite: la novedad está puesta y falta que el aporte
        // la active.
        if (! str_contains($cobertura, 'COBERTURA INTEGRAL')) {
            return $this->fila($r, 'revisar', 'En el listado de EPS SURA aparece como «'
                .Str::lower(trim((string) ($res['cobertura'] ?? $estado ?: 'sin cobertura')))
                .'»: el trámite está hecho pero todavía no está vigente.', $res);
        }

        if (self::normalizar((string) $r->contrato->cliente?->primer_apellido) === '') {
            return $this->fila($r, 'revisar', 'Vigente en Sura, pero el contrato no tiene cliente en BryNex con qué comparar el nombre.', $res);
        }

        $nombreSura = trim(implode(' ', array_filter([$res['nombres'] ?? null, $res['apellido1'] ?? null, $res['apellido2'] ?? null])));

        if (! $this->mismoApellido($r, $nombreSura)) {
            return $this->fila($r, 'revisar', "El nombre en Sura ({$nombreSura}) no coincide con BryNex.", $res);
        }

        $observacion = sprintf(
            'Ya vigente en EPS SURA con %s al %s (conciliación automática: cobertura integral%s).',
            $r->contrato->razonSocial->razon_social,
            now()->format('d/m/Y'),
            ! empty($res['parentesco']) && Str::upper($res['parentesco']) !== 'TITULAR' ? ', en Sura figura como '.Str::lower($res['parentesco']) : ''
        );

        if ($simular) {
            return $this->fila($r, 'cerraria', $observacion, $res);
        }

        $cerrado = DB::transaction(function () use ($r, $observacion, $usuarioId) {
            $fresco = Radicado::whereKey($r->id)->lockForUpdate()->first();

            // Alguien pudo cerrarlo a mano mientras el portal respondía.
            if (! $fresco || ! in_array($fresco->estado, self::ESTADOS, true)) {
                return false;
            }

            $anterior = $fresco->estado;
            $fresco->update([
                'estado'             => Radicado::ESTADO_OK,
                'canal_envio'        => 'portal',
                'fecha_confirmacion' => now(),
                'user_id'            => $usuarioId,
                'observacion'        => trim(($fresco->observacion ? $fresco->observacion.' | ' : '').$observacion),
            ] + $fresco->datosConfirmacion('eps_sura'));

            RadicadoMovimiento::create([
                'radicado_id'     => $fresco->id,
                'contrato_id'     => $fresco->contrato_id,
                'tipo_proceso'    => 'afiliacion',
                'entidad'         => Radicado::TIPO_EPS,
                'user_id'         => $usuarioId,
                'estado_anterior' => $anterior,
                'estado_nuevo'    => Radicado::ESTADO_OK,
                'observacion'     => $observacion,
            ]);

            return true;
        });

        return $cerrado
            ? $this->fila($r, 'cerrado', $observacion, $res)
            : $this->fila($r, 'revisar', 'El radicado cambió de estado mientras se consultaba; no se tocó.', $res);
    }

    /**
     * Corre el Chrome headless que consulta el portal.
     *
     * @return array{ok:bool, empresa?:string, resultados?:array, error?:string}
     */
    /**
     * El listado de afiliados de cada empresa de un usuario, en una sola sesión.
     *
     * @param  array<int, string>  $nits
     * @return array{ok:bool, por_nit?:array<string, array>, error?:string}
     */
    private function listadoPortal($credencial, array $nits): array
    {
        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'empresas'      => array_values($nits),
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            // El informe pagina de a 10 por ajax: una empresa grande son varios
            // minutos, y cambiar de empresa cuesta otro tanto.
            ->timeout(240 + 240 * count($nits))
            ->input($entrada)
            ->run(ArlSuraSesionService::binarioNode().' scripts/eps-sura-afiliados.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            // El mensaje nunca trae la clave: el script no la imprime.
            Log::warning('EPS SURA: no se pudo leer el listado de afiliados', [
                'nits'  => $nits,
                'paso'  => $salida['paso'] ?? null,
                'error' => $salida['error'] ?? trim($resultado->errorOutput()),
            ]);

            return ['ok' => false, 'error' => $salida['error'] ?? (trim($resultado->errorOutput()) ?: 'El proceso del portal no devolvió respuesta.')];
        }

        // Una empresa sola responde en la raíz; varias, en «empresas».
        $informes = $salida['empresas'] ?? [['nit' => (string) reset($nits)] + $salida];

        return [
            'ok' => true,
            'por_nit' => collect($informes)
                ->keyBy(fn ($i) => preg_replace('/\D/', '', (string) ($i['nit'] ?? '')))
                ->all(),
        ];
    }

    /** Protección contra cruzar la cédula con otra persona: el primer apellido de BryNex debe estar en el nombre de Sura. */
    private function mismoApellido(Radicado $r, string $nombreSura): bool
    {
        $apellido = self::normalizar((string) $r->contrato->cliente?->primer_apellido);

        return $apellido !== '' && str_contains(self::normalizar($nombreSura), $apellido);
    }

    private static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::upper(Str::ascii($texto))));
    }

    private function fila(Radicado $r, string $accion, string $mensaje, array $sura = []): array
    {
        $c = $r->contrato;

        return [
            'radicado_id' => $r->id,
            'contrato_id' => $c->id,
            'cedula'      => (string) $c->cedula,
            'nombre'      => trim(($c->cliente?->primer_nombre ?? '').' '.($c->cliente?->primer_apellido ?? '')),
            'empresa'     => $c->razonSocial?->razon_social,
            'estado_antes' => $r->estado,
            'accion'      => $accion,
            'mensaje'     => $mensaje,
            'sura_estado' => $sura['estado'] ?? null,
        ];
    }
}
