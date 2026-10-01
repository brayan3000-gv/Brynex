<?php

namespace App\Services;

use App\Models\Eps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consulta BDUA/RUAF a través del operador de planilla (ARUS / Simple).
 *
 * Vivía dentro de Admin\ClienteController, que era su único consumidor. Salió
 * de ahí cuando apareció el segundo: la API que consume Cuenta_facil para
 * precargar la ficha del contratista. El código es el mismo, movido sin
 * cambios de comportamiento; lo único que cambió es que el punto de entrada
 * dejó de ser privado.
 *
 * Nunca lanza: si el aliado no tiene credenciales de operador, o ninguno de
 * los operadores responde, devuelve null y lo deja en el log. Las dos
 * pantallas que lo usan tienen que seguir funcionando con el registro oficial
 * caído.
 *
 * Un operador caído tampoco alcanza para eso: la consulta recorre todos los
 * operadores con credenciales hasta que uno conteste. Ver
 * candidatosParaRuaf() para el orden.
 */
class RegistroOficialService
{
    /**
     * Operadores (ARUS/Simple) con credenciales para consultar BDUA/RUAF, en
     * el orden en que hay que intentarlos.
     *
     * Consultar la afiliación de una cédula es una operación de solo lectura
     * sin autorización por aportante: cualquier cuenta de operador sirve para
     * cualquier persona. Eso es lo que permite encadenar varios intentos —y
     * lo que permitía, antes, caer a las credenciales del aliado BryNex
     * cuando el aliado activo no tiene cuenta propia—.
     *
     * Esto NO aplica a liquidar planillas (PlanillaApiController), que sí
     * exige la autorización real del aliado sobre ese aportante específico.
     *
     * El orden es:
     *   1. credencial propia del aliado, y dentro de eso
     *      a. los operadores que el aliado tiene activos (por `orden`),
     *      b. los que desactivó en su configuración;
     *   2. lo mismo con la credencial del aliado de respaldo (BryNex).
     *
     * El punto 1b es deliberado. `aliado_operadores_planilla` dice por dónde
     * paga el aliado sus planillas, no qué cuenta puede hacer una consulta de
     * solo lectura; si no se mirara, el aliado 2 —que desactivó Simple el
     * 2026-08-01— se quedaría sin ningún operador vivo cuando ARUS falla, que
     * es exactamente el caso que dejó la consulta caída. Un operador global
     * desactivado (`operadores_planilla.activo = 0`) sí se respeta: ese lo
     * apaga BryNex, no el aliado.
     *
     * @return array<int, array{0: \App\Models\OperadorPlanilla, 1: \App\Models\OperadorCredencial}>
     */
    private function candidatosParaRuaf(int $aliadoId): array
    {
        $codigos = array_keys(\App\Services\SuaporteApiService::HOSTS);

        $preferidos = \App\Models\OperadorPlanilla::paraAliado($aliadoId)
            ->whereIn('codigo', $codigos)
            ->get();

        $resto = \App\Models\OperadorPlanilla::query()
            ->whereIn('codigo', $codigos)
            ->where('activo', true)
            ->where(function ($q) use ($aliadoId) {
                $q->whereNull('aliado_id')->orWhere('aliado_id', $aliadoId);
            })
            ->whereNotIn('id', $preferidos->pluck('id')->all())
            ->orderBy('orden')
            ->get();

        $operadores = $preferidos->concat($resto);

        $aliadosCredencial = [$aliadoId];
        $fallbackId = (int) config('services.suaporte.aliado_fallback_ruaf');
        if ($fallbackId && $fallbackId !== $aliadoId) {
            $aliadosCredencial[] = $fallbackId;
        }

        $candidatos = [];

        foreach ($aliadosCredencial as $idCredencial) {
            foreach ($operadores as $operador) {
                $credencial = \App\Models\OperadorCredencial::paraOperador($idCredencial, $operador->id)->first();

                if ($credencial) {
                    $candidatos[] = [$operador, $credencial];
                }
            }
        }

        return $candidatos;
    }

    /**
     * Consulta BDUA/RUAF a través de la API del operador de planilla para
     * traer los datos oficiales de la persona: nombres, EPS, fondo de
     * pensión, régimen y estado.
     *
     * Devuelve null si el aliado no tiene credenciales de operador cargadas,
     * de modo que el formulario siga funcionando igual que siempre.
     *
     * El registro responde por tipo + número, y son espacios independientes:
     * el mismo número existe como CC de una persona y como CE de otra. Con el
     * tipo equivocado la respuesta llega vacía, sin error, e indistinguible de
     * "esta persona no está registrada" — por eso el tipo lo escoge el asesor
     * y no se asume.
     */
    public function consultar(int $aliadoId, string $cedula, string $tipoDoc = 'CC'): ?array
    {
        $cedula = preg_replace('/\D/', '', $cedula);

        if (strlen($cedula) < 4) {
            return null;
        }

        // El asesor puede entrar y salir del campo varias veces: se cachea
        // para no repetir el login contra el operador en cada blur.
        //
        // La caché es una optimización, NO un requisito: se lee y se escribe
        // dentro de try/catch para que un fallo del driver no tumbe la
        // consulta. Con CACHE_DRIVER=file basta un directorio de
        // storage/framework/cache que Apache no pueda escribir (lo deja
        // cualquier artisan corrido como root) para que Cache::remember
        // lance ErrorException, y el modal muestre "No se pudo consultar"
        // aunque el operador haya respondido bien.
        //
        // Por eso NO se usa Cache::remember: si la escritura falla después de
        // consultar, se devuelve igual el resultado en vez de perderlo.
        //
        // El tipo entra en la llave: CC 104915 y CE 104915 son dos personas
        // distintas y no pueden compartir entrada de caché.
        $llave = "ruaf_{$aliadoId}_{$tipoDoc}_{$cedula}";

        try {
            $cacheado = Cache::get($llave);
        } catch (\Throwable $e) {
            Log::warning('RUAF: no se pudo leer la caché', ['llave' => $llave, 'message' => $e->getMessage()]);
            $cacheado = null;
        }

        if ($cacheado !== null) {
            return $cacheado;
        }

        $resultado = $this->consultarEnOperador($aliadoId, $cedula, $tipoDoc);

        // Un null (sin credenciales, o el operador falló) no se cachea a
        // propósito: el siguiente intento vuelve a preguntar.
        if ($resultado !== null) {
            try {
                Cache::put($llave, $resultado, 600);
            } catch (\Throwable $e) {
                Log::warning('RUAF: no se pudo guardar en caché', ['llave' => $llave, 'message' => $e->getMessage()]);
            }
        }

        return $resultado;
    }

    /**
     * La consulta real al operador, sin caché. Separada de
     * consultar() solo para que el manejo de la caché quede
     * legible; no llamar directamente.
     *
     * Recorre los candidatos de candidatosParaRuaf() y se queda con el primero
     * que responda. Que un operador se caiga es normal —el 2026-08-31 ARUS
     * dejó de publicar todo su árbol /auth y devolvía 404 hasta en el login—,
     * y como cualquier cuenta sirve para cualquier cédula, no hay razón para
     * devolver null teniendo otra cuenta viva.
     *
     * "No encontrado" NO es un fallo: si el operador responde bien y la
     * persona no figura, esa es la respuesta y se devuelve tal cual. Solo se
     * pasa al siguiente cuando la consulta misma falló.
     */
    private function consultarEnOperador(int $aliadoId, string $cedula, string $tipoDoc = 'CC'): ?array
    {
        $candidatos = $this->candidatosParaRuaf($aliadoId);

        if (empty($candidatos)) {
            return null;
        }

        $ultimo = count($candidatos) - 1;
        $resultado = null;
        $operador = null;

        foreach ($candidatos as $i => [$operador, $credencial]) {
            $api = new \App\Services\SuaporteApiService([
                'operador' => $operador->codigo,
                'usuario' => $credencial->usuario,
                'contrasena' => $credencial->contrasena,
                'clave_secreta' => $credencial->clave_secreta,
                // El timeout normal (120s) es para liquidar planillas. Aquí hay
                // un asesor esperando frente al formulario y, encadenando
                // intentos, un operador colgado se los comería todos: se acota
                // mientras queden candidatos y se deja completo en el último.
                'timeout' => $i < $ultimo ? 20 : null,
            ]);

            $resultado = $api->consultarAfiliacion($tipoDoc, $cedula);

            if ($resultado['success']) {
                break;
            }

            Log::warning('RUAF: el operador no respondió la consulta', [
                'aliado_id' => $aliadoId,
                'operador' => $operador->codigo,
                'credencial_aliado_id' => $credencial->aliado_id,
                'tipo_doc' => $tipoDoc,
                'message' => $resultado['message'] ?? null,
                'quedan_operadores' => $ultimo - $i,
            ]);
        }

        if (! $resultado['success']) {
            return null;
        }

        $d = $resultado['afiliacion'];

        // Los códigos que devuelve el registro son los mismos que usan
        // las tablas de referencia de Brynex. Los de movilidad (ESSC24,
        // EPS041) se traducen a la EPS de contributivo.
        $epsId = Eps::idPorCodigo($d['administradoraBDUA'] ?? null);

        $pensionId = ! empty($d['administradoraRUAF'])
            ? DB::table('pensiones')->where('codigo', $d['administradoraRUAF'])->value('id')
            : null;

        return [
            'encontrado' => $resultado['registrado'],
            'operador' => $operador->nombre,
            'tipo_doc' => $tipoDoc,
            'primer_nombre' => $d['primerNombre'] ?? '',
            'segundo_nombre' => $d['segundoNombre'] ?? '',
            'primer_apellido' => $d['primerApellido'] ?? '',
            'segundo_apellido' => $d['segundoApellido'] ?? '',
            'eps_id' => $epsId,
            'eps_nombre' => $epsId ? DB::table('eps')->where('id', $epsId)->value('nombre') : null,
            'eps_codigo' => $d['administradoraBDUA'] ?? null,
            'pension_id' => $pensionId,
            'pension_nombre' => $pensionId ? DB::table('pensiones')->where('id', $pensionId)->value('razon_social') : null,
            'pension_codigo' => $d['administradoraRUAF'] ?? null,
            'estado' => $d['estado'] ?? null,
            'regimen' => $d['regimen'] ?? null,
            // Figurar en RUAF (aunque hoy no tenga fondo activo) es lo
            // que impide declarar el subtipo 03 "no obligado por edad".
            'en_ruaf' => ! empty($d['fechaAfiliacionRUAF']),
            'ruaf_desde' => $d['fechaAfiliacionRUAF'] ?? null,
            // Payload crudo del operador, sin filtrar: incluye campos
            // que hoy no se usan (valorUPC, coincidencia, fechas sin
            // formatear) para que el modal pueda mostrarlos todos.
            'raw' => $d,
        ];
    }
}
