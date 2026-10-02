<?php

namespace App\Services\Caja;

use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Los subsidios retenidos por Comfenalco Valle, leídos por un Chrome del servidor.
 *
 * Hace el mismo papel que ComfandiSubsidiosHeadless pero con una diferencia que
 * lo cambia todo: la Sucursal Virtual tiene la consulta de morosos e inexactos
 * **por empresa**, así que una sola pantalla cubre la nómina entera en vez de
 * diez segundos por trabajador.
 *
 * De ahí que todos los candidatos vuelvan como `revisados` cuando la consulta
 * responde: a quien no aparece en las tablas se le miró igual, y su tarea se
 * puede cerrar.
 */
class ComfenalcoSubsidiosHeadless
{
    // Por el proxy residencial cada paso tarda: el login solo puede llevarse
    // tres minutos.
    private const SEGUNDOS = 600;

    /**
     * @param  array<string>  $documentos  cédulas de esa empresa que interesan
     * @return array{ok:bool, empresa?:?string, movimientos?:array, revisados?:array, errores?:array, error?:string}
     */
    public function bloqueos(string $nit, array $documentos, int $meses = 4, ?bool $conVentana = null): array
    {
        $documentos = array_values(array_filter(array_map(fn ($d) => preg_replace('/\D/', '', (string) $d), $documentos)));

        if (! $documentos) {
            return ['ok' => false, 'error' => 'No llegó ninguna cédula para consultar.'];
        }

        $clave = $this->credencial($nit);

        if (! $clave) {
            return ['ok' => false, 'error' => "La empresa {$nit} no tiene la clave de Comfenalco en el módulo de claves."];
        }

        $salida = $this->correr([
            'usuario' => $clave['usuario'],
            'contrasena' => $clave['contrasena'],
        ], $conVentana);

        if (! ($salida['ok'] ?? false)) {
            $error = $salida['error'] ?? 'El portal no respondió.';

            Log::warning('Comfenalco subsidios: no se pudo leer el portal', [
                'nit' => $nit,
                'error' => $error,
                'url' => $salida['url'] ?? null,
            ]);

            return ['ok' => false, 'error' => $error];
        }

        return [
            'ok' => true,
            'empresa' => $salida['empresa'] ?? null,
            'movimientos' => self::traducir($salida['movimientos'] ?? [], $documentos),
            // La consulta es de la empresa entera: todos quedan mirados, también
            // los que no salieron en ninguna tabla.
            'revisados' => $documentos,
            'errores' => [],
        ];
    }

    /**
     * Los afiliados que la caja tiene de esa empresa, para conciliar radicados.
     *
     * Misma entrada al portal que los morosos —login, salida colombiana— pero
     * otra consulta. Si la lista viene incompleta el script no la entrega: con
     * media empresa, la conciliación marcaría como "falta afiliar" a gente que
     * sí está.
     *
     * @return array{ok:bool, nit?:string, empresa?:?string, filas?:array, error?:string}
     */
    public function trabajadores(string $nit): array
    {
        $clave = $this->credencial($nit);

        if (! $clave) {
            return ['ok' => false, 'error' => "La empresa {$nit} no tiene la clave de Comfenalco en el módulo de claves."];
        }

        $salida = $this->correr([
            'usuario' => $clave['usuario'],
            'contrasena' => $clave['contrasena'],
            'modo' => 'trabajadores',
        ]);

        if (! ($salida['ok'] ?? false)) {
            Log::warning('Comfenalco: no se pudo leer el listado de afiliados', [
                'nit' => $nit,
                'error' => $salida['error'] ?? null,
            ]);
        }

        return $salida;
    }

    /**
     * Las filas del portal, en el formato que entiende SubsidioTareasService.
     *
     * El motivo se redacta aquí y no en el portal: de él depende si la tarea
     * sale como de subsidios o de documentos, y las dos tablas de Comfenalco
     * —mora e inexactitud— se trabajan pagando o corrigiendo el aporte.
     *
     * Es estática porque las filas llegan por dos caminos —este Chrome y la
     * extensión, que las manda crudas— y el motivo tiene que redactarse igual
     * en los dos.
     */
    public static function traducir(array $filas, array $documentos): array
    {
        $movimientos = [];

        foreach ($filas as $fila) {
            $documento = ltrim(preg_replace('/\D/', '', (string) ($fila['documento'] ?? '')), '0');

            if (! $documento || ! in_array($documento, array_map(fn ($d) => ltrim($d, '0'), $documentos), true)) {
                continue;
            }

            // La columna "Clase" suele repetir la tabla de la que sale ("Mora",
            // "Inexactitud"): solo se añade cuando dice algo más.
            $clase = trim((string) ($fila['clase'] ?? ''));
            $clase = preg_match('/^(mora|inexactitud)$/i', $clase) ? '' : $clase;

            $movimientos[] = [
                'documento' => $documento,
                'tipo' => 'BLOQUEO',
                'periodo' => trim((string) ($fila['periodo'] ?? '')),
                'fecha' => null,
                'valor' => (string) ($fila['valor'] ?? '0'),
                'motivo' => ($fila['origen'] ?? 'mora') === 'inexactitud'
                    ? 'Inexactitud en el aporte reportado'.($clase ? " ({$clase})" : '')
                    : 'Mora en el aporte'.($clase ? " ({$clase})" : ''),
            ];
        }

        return $movimientos;
    }

    /** ¿Están abiertos los dos puertos del túnel de la oficina? */
    private function tunelEnPie(): bool
    {
        foreach (['tunel', 'tunel_auth'] as $cual) {
            $destino = config("services.comfenalco.{$cual}");

            if (! $destino || ! $this->escucha($destino)) {
                return false;
            }
        }

        return true;
    }

    /** ¿Hay algo escuchando en `host:puerto`? */
    private function escucha(string $destino): bool
    {
        [$host, $puerto] = array_pad(explode(':', $destino, 2), 2, null);
        $socket = @fsockopen($host, (int) $puerto, $e, $m, 1.5);

        if (! $socket) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Lanza el script del portal con la salida colombiana que corresponda.
     *
     * Hay dos y basta con una. Manda el proxy: no depende de que nadie deje un
     * PC encendido, y el tráfico que gasta esto —unas pocas páginas al día— es
     * despreciable frente a lo contratado. El túnel de la oficina es el
     * respaldo. Todo va por stdin para que las claves no queden en `ps`.
     */
    private function correr(array $entrada, ?bool $conVentana = null): array
    {
        $proxy = config('services.proxy_colombia.url');
        $tunelListo = ! $proxy && $this->tunelEnPie();

        if (! $proxy && ! $tunelListo) {
            return ['ok' => false, 'error' => 'No hay salida colombiana: ni PROXY_COLOMBIA ni el túnel de la oficina.'];
        }

        $resultado = Process::path(base_path())
            ->timeout(self::SEGUNDOS)
            ->input(json_encode($entrada + [
                // Con ventana siempre que se pueda: el portal atiende peor a un
                // Chrome sin pantalla. En el servidor la pone Xvfb.
                'visible' => $conVentana ?? is_executable('/usr/bin/xvfb-run'),
                'proxy' => $proxy,
                'tunel' => $tunelListo ? config('services.comfenalco.tunel') : null,
                'tunel_auth' => $tunelListo ? config('services.comfenalco.tunel_auth') : null,
            ], JSON_UNESCAPED_UNICODE))
            ->run($this->comando());

        $salida = json_decode(trim($resultado->output()), true) ?: [];

        if (! $salida && ($err = trim($resultado->errorOutput()))) {
            return ['ok' => false, 'error' => mb_substr($err, 0, 300)];
        }

        return $salida ?: ['ok' => false, 'error' => 'El portal no respondió.'];
    }

    private function comando(): string
    {
        $node = ArlSuraSesionService::binarioNode().' scripts/comfenalco-subsidios.mjs';

        return is_executable('/usr/bin/xvfb-run')
            ? 'xvfb-run -a --server-args="-screen 0 1400x900x24" '.$node
            : $node;
    }

    /**
     * La clave de la **caja** Comfenalco de esa empresa, en cualquier aliado.
     *
     * El tipo importa: Comfenalco es EPS y caja a la vez, con claves distintas,
     * y la de la EPS no entra al portal de afiliaciones. El nombre también: hay
     * Comfenalco en varias ciudades y son cajas independientes.
     *
     * @return array{usuario:string, contrasena:string}|null
     */
    public function credencial(string $nit): ?array
    {
        $filas = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('rs.nit', preg_replace('/\D/', '', $nit))
            ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, ComfenalcoCajaService::ENTIDAD, 'CAJA', '%COMFENALCO%'))
            ->where('c.activo', true)
            ->whereNotNull('c.usuario')->where('c.usuario', '<>', '')
            ->whereNotNull('c.contrasena')->where('c.contrasena', '<>', '')
            ->orderByDesc('c.updated_at')
            ->get(['c.entidad', 'c.entidad_tipo', 'c.usuario', 'c.contrasena']);

        // Comfenalco Cartagena no abre este portal: mejor quedarse sin clave que
        // gastar intentos —y arriesgar un bloqueo— con la de otra ciudad. Las
        // clasificadas ya son de la del Valle.
        $fila = $filas->first(fn ($f) => $f->entidad_tipo || ComfenalcoCajaService::esDelValle((string) $f->entidad));

        return $fila ? ['usuario' => trim($fila->usuario), 'contrasena' => (string) $fila->contrasena] : null;
    }
}
