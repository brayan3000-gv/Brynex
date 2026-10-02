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

            // Todas las empresas del usuario van en la misma corrida; el límite
            // por proceso se reparte entre ellas para no eternizar un Chrome.
            foreach ($this->repartir($grupo['empresas']) as $tanda) {
                $salida = $this->consultarPortal($credencial, $tanda);
                $porCedula = collect($salida['resultados'] ?? [])->keyBy(fn ($x) => ($x['nit'] ?? '').':'.($x['numero'] ?? ''));

                if (($salida['paso'] ?? null) === 'login') {
                    $credencial->update(['ultimo_error' => mb_substr((string) ($salida['error'] ?? ''), 0, 300)]);

                    // Con el login caído, lo que falte de este usuario fallaría
                    // igual y sumaría intentos fallidos contra él.
                    foreach ($tanda as $nitEmpresa => $radicados) {
                        foreach ($radicados as $r) {
                            $detalle[] = $this->fila($r, 'error', 'No se pudo entrar al portal: '.($salida['error'] ?? 'login fallido'));
                        }
                    }
                    $avisar("{$nombres}: falló el login.", $detalle);

                    continue 2;
                }

                foreach ($tanda as $nitEmpresa => $radicados) {
                    foreach ($radicados as $r) {
                        $cedula = preg_replace('/\D/', '', (string) $r->contrato->cedula);
                        $res = $porCedula->get($nitEmpresa.':'.$cedula);

                        if (! $res) {
                            $detalle[] = $this->fila($r, 'error', $salida['error'] ?? 'El portal no devolvió respuesta para esta cédula.');

                            continue;
                        }

                        $detalle[] = $this->resolver($r, $res, $simular, $usuarioId);
                    }
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
    private function resolver(Radicado $r, array $res, bool $simular, ?int $usuarioId): array
    {
        if (! ($res['encontrado'] ?? false)) {
            // "No existe como cotizante de la empresa" es la única respuesta
            // que prueba que falta el trámite; cualquier otra cosa es un error.
            return str_contains((string) ($res['mensaje'] ?? ''), 'no existe como cotizante')
                ? $this->fila($r, 'falta', 'No es cotizante de la empresa en EPS SURA: falta el trámite.')
                : $this->fila($r, 'error', $res['mensaje'] ?? 'Respuesta no reconocida del portal.');
        }

        $estado = trim((string) ($res['estado'] ?? ''));

        // "NO TIENE DERECHO POR FIN DE VIGENCIA" también contiene "TIENE DERECHO".
        if (! preg_match('/^TIENE DERECHO/i', $estado) || ($res['cotiza'] ?? null) === 'N') {
            return $this->fila($r, 'revisar', "En Sura figura, pero: {$estado}".(($res['cotiza'] ?? null) === 'N' ? ' (no cotiza)' : ''), $res);
        }

        if (self::normalizar((string) $r->contrato->cliente?->primer_apellido) === '') {
            return $this->fila($r, 'revisar', "Vigente en Sura como {$res['nombre']}, pero el contrato no tiene cliente en BryNex con qué comparar el nombre.", $res);
        }

        if (! $this->mismoApellido($r, (string) ($res['nombre'] ?? ''))) {
            return $this->fila($r, 'revisar', "El nombre en Sura ({$res['nombre']}) no coincide con BryNex.", $res);
        }

        $observacion = sprintf(
            'Ya vigente en EPS SURA con %s al %s (conciliación automática: %s%s).',
            $r->contrato->razonSocial->razon_social,
            now()->format('d/m/Y'),
            Str::lower($estado),
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
     * Parte las empresas de un usuario en tandas que quepan en un Chrome.
     *
     * El login cuesta, así que conviene meter cuantas más cédulas mejor en cada
     * proceso; pero un proceso eterno se cae y se pierde todo, por eso el tope.
     * Una empresa nunca se parte entre tandas: cambiar de empresa y volver
     * costaría más que lo que se ahorra.
     *
     * @param  array<string, array{nombre:string, radicados:Collection}>  $empresas
     * @return array<int, array<string, Collection>>
     */
    private function repartir(array $empresas): array
    {
        $tandas = [];
        $actual = [];
        $cuenta = 0;

        foreach ($empresas as $nit => $empresa) {
            foreach ($empresa['radicados']->chunk(self::POR_LOTE) as $trozo) {
                if ($cuenta && $cuenta + $trozo->count() > self::POR_LOTE) {
                    $tandas[] = $actual;
                    $actual = [];
                    $cuenta = 0;
                }

                $actual[(string) $nit] = isset($actual[(string) $nit])
                    ? $actual[(string) $nit]->concat($trozo)
                    : $trozo;
                $cuenta += $trozo->count();
            }
        }

        if ($actual) {
            $tandas[] = $actual;
        }

        return $tandas;
    }

    /**
     * Una corrida del robot: un login y todas las empresas de esa tanda.
     *
     * @param  array<string, Collection>  $tanda  NIT => radicados
     */
    private function consultarPortal($credencial, array $tanda): array
    {
        $cuantos = collect($tanda)->sum(fn (Collection $c) => $c->count());

        $entrada = json_encode([
            'tipoDocumento' => $credencial->tipo_documento,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'empresas'      => collect($tanda)->map(fn (Collection $radicados, $nit) => [
                'nit'        => (string) $nit,
                'documentos' => $radicados->map(fn (Radicado $r) => [
                    'tipo'   => $r->contrato->cliente?->tipo_doc ?: 'CC',
                    'numero' => (string) $r->contrato->cedula,
                ])->values()->all(),
            ])->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        $resultado = Process::path(base_path())
            // Login ~40 s, unos 12 s por cédula y el cambio de empresa; con holgura.
            ->timeout(180 + 25 * $cuantos + 30 * count($tanda))
            ->input($entrada)
            ->run(ArlSuraSesionService::binarioNode().' scripts/eps-sura-consultar.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! ($salida['ok'] ?? false)) {
            // El mensaje nunca trae la clave: el script no la imprime.
            Log::warning('EPS SURA: la consulta del portal falló', [
                'nit'   => implode(',', array_keys($tanda)),
                'paso'  => $salida['paso'] ?? null,
                'error' => $salida['error'] ?? trim($resultado->errorOutput()),
            ]);

            $salida['error'] ??= trim($resultado->errorOutput()) ?: 'El proceso del portal no devolvió respuesta.';
        }

        return $salida;
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
