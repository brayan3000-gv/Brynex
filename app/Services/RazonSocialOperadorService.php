<?php

namespace App\Services;

use App\Models\OperadorCredencial;
use App\Models\RazonSocial;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trae del operador de planilla (ARUS o Simple) la ficha de la empresa y la
 * guarda en la razón social.
 *
 * La ficha es lo que quedó registrado allá —representante legal, dirección,
 * municipio, exoneración— y es lo que imprime el soporte del operador. BryNex
 * la tenía a medias y el PDF rellenaba el resto con los datos de Brygar.
 *
 * Qué se escribe y qué no:
 *   - Representante (nombre, documento y tipo): se sobrescribe. Es un dato de
 *     la empresa y el del operador es el registrado.
 *   - Dirección, teléfono, correo, DV y forma de presentación: solo si están
 *     vacíos. Los usan los portales de ARL y el TXT de la planilla, y lo que
 *     alguien escribió a mano suele ser mejor que el "5555555" que muchas
 *     empresas tienen allá.
 *   - Actividad económica: nunca. El TXT la pone en cada cotizante con los 7
 *     dígitos del código de la ARL; el CIIU de 4 del operador la dañaría.
 *   - Departamento, municipio, tipo de persona y exoneración: columnas nuevas,
 *     se sobrescriben.
 *   - `datos_operador`: la ficha completa. De ahí lee el PDF para salir igual.
 *   - Sucursal (código y nombre): nunca. Una empresa tiene varias y la que va en
 *     el TXT la decide quien liquida.
 */
class RazonSocialOperadorService
{
    /** Sesiones abiertas por credencial, para no relogear por cada empresa. */
    private array $sesiones = [];

    /** Credenciales cuyo login ya falló en esta corrida: no se reintentan por cada empresa. */
    private array $loginsFallidos = [];

    /**
     * @return array{success: bool, cambios?: array<string, array{0: mixed, 1: mixed}>, operador?: string, message?: string}
     */
    public function sincronizar(RazonSocial $rs, bool $aplicar = true): array
    {
        $nit = preg_replace('/\D/', '', (string) $rs->nit);

        if ($rs->es_independiente || strlen($nit) < 9) {
            return ['success' => false, 'message' => 'No es una empresa con NIT.'];
        }

        $motivos = [];

        foreach ($this->credencialesPara($rs) as [$cred, $operador]) {
            try {
                $api = $this->sesion($cred, $operador->codigo);

                $aportante = $api->consultarAportante('NI', $nit);
                if (! $aportante['success']) {
                    $motivos[] = "{$operador->nombre}: no existe el aportante.";
                    continue;
                }

                $autorizacion = $api->autorizar($aportante['id'], 'NI', $nit);
                if (! $autorizacion['success']) {
                    $motivos[] = "{$operador->nombre}: sin permisos sobre el NIT.";
                    continue;
                }

                $ficha = $api->consultarFichaAportante($aportante['id']);
                if (! $ficha['success']) {
                    $motivos[] = "{$operador->nombre}: {$ficha['message']}";
                    continue;
                }

                $cambios = $this->cambios($rs, $ficha['ficha']);

                if ($aplicar) {
                    RazonSocial::where('id', $rs->id)->update(array_map(
                        fn ($par) => is_array($par[1]) ? json_encode($par[1], JSON_UNESCAPED_UNICODE) : $par[1],
                        $cambios
                    ));
                }

                return ['success' => true, 'cambios' => $cambios, 'operador' => $operador->nombre];
            } catch (Throwable $e) {
                $motivos[] = "{$operador->nombre}: {$e->getMessage()}";
            }
        }

        return ['success' => false, 'message' => $motivos ? implode(' ', $motivos) : 'Sin credenciales de Enlace ni Simple.'];
    }

    /**
     * Columna => [antes, después], solo lo que cambia.
     */
    public function cambios(RazonSocial $rs, array $ficha): array
    {
        $rep = $ficha['representanteLegal'] ?? [];
        $contacto = $ficha['informacionContacto'] ?? [];
        $texto = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : mb_strtoupper($v);

        $nombreRep = $texto(implode(' ', array_filter([
            $rep['primerNombre'] ?? null, $rep['segundoNombre'] ?? null,
            $rep['primerApellido'] ?? null, $rep['segundoApellido'] ?? null,
        ])));

        $tipoPersona = $ficha['tipoPersonaCodigo']
            ?? match ((int) ($ficha['tipoPersonaId'] ?? 0)) { 1 => 'N', 2 => 'J', default => null };

        $exonerado = match ($ficha['validacionExtra']['exoneradoPagoParafiscal'] ?? null) {
            'S' => true, 'N' => false, default => null,
        };

        $formaPresentacion = match ((int) ($ficha['formaPresentacionId'] ?? 0)) { 1 => 'U', 3 => 'S', default => null };

        // El nombre del representante se cambia solo si cambió la persona (otra
        // cédula) o si BryNex no lo tiene: con la misma cédula, el del operador
        // suele venir recortado o mal escrito ("YESENIA VIDAL" por "YESSENIA
        // AMPARO VIDAL MOSQUERA"). El PDF usa la copia del operador de todos modos.
        $cedulaRep = $texto($rep['numeroIdentificacion'] ?? null);
        $mismaPersona = $cedulaRep !== null && preg_replace('/\D/', '', (string) $rs->cedula_rep) === preg_replace('/\D/', '', $cedulaRep);

        $sobrescribir = [
            'nombre_rep'             => ($mismaPersona && trim((string) $rs->nombre_rep) !== '') ? null : $nombreRep,
            'cedula_rep'             => $cedulaRep,
            'rep_tipo_doc'           => $texto($rep['tipoIdentificacion'] ?? null),
            'cod_departamento'       => $texto($contacto['codigoDepartamento'] ?? null),
            'cod_municipio'          => $texto($contacto['codigoMunicipio'] ?? null),
            'tipo_persona'           => $tipoPersona,
            'exonerado_parafiscales' => $exonerado,
        ];

        $siVacio = [
            'direccion'           => $texto($contacto['datosDireccion']['direccionCompleta'] ?? null),
            'telefonos'           => $this->telefonoReal($contacto['numeroTelefono'] ?? null) ?? $this->telefonoReal($contacto['numeroCelular'] ?? null),
            'correos'             => isset($contacto['correoElectronico']) ? mb_strtolower(trim($contacto['correoElectronico'])) : null,
            'dv'                  => isset($ficha['digitoVerificacion']) ? (string) $ficha['digitoVerificacion'] : null,
            'forma_presentacion'  => $formaPresentacion,
        ];

        $cambios = [];

        foreach ($sobrescribir as $columna => $nuevo) {
            // Un vacío del operador no borra lo que BryNex ya tiene.
            if ($nuevo !== null && $this->distinto($rs->{$columna}, $nuevo)) {
                $cambios[$columna] = [$rs->{$columna}, $nuevo];
            }
        }

        foreach ($siVacio as $columna => $nuevo) {
            if ($nuevo !== null && trim((string) $rs->{$columna}) === '') {
                $cambios[$columna] = [$rs->{$columna}, $nuevo];
            }
        }

        // La copia completa siempre se renueva. `usuario` trae datos de la
        // cuenta del portal que no tienen nada que hacer aquí.
        unset($ficha['usuario']);
        $cambios['datos_operador'] = [null, $ficha];
        $cambios['datos_operador_at'] = [$rs->datos_operador_at, now()];

        return $cambios;
    }

    /** Descarta los teléfonos de relleno que el portal exige ("5555555", "0000000"). */
    private function telefonoReal($numero): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $numero);

        return (strlen($digitos) >= 7 && count(array_unique(str_split($digitos))) > 2) ? $digitos : null;
    }

    private function distinto($actual, $nuevo): bool
    {
        if (is_bool($nuevo)) {
            return $actual === null || (bool) $actual !== $nuevo;
        }

        return mb_strtoupper(trim((string) $actual)) !== mb_strtoupper(trim((string) $nuevo));
    }

    /**
     * Credenciales con las que probar, primero las del aliado de la razón
     * social. Después las de otros aliados: la ficha es de la empresa, no del
     * aliado, y el operador solo la entrega a un usuario autorizado sobre ese
     * NIT, así que no se lee nada que ese usuario no pueda ver en el portal.
     *
     * @return iterable<array{0: OperadorCredencial, 1: object}>
     */
    private function credencialesPara(RazonSocial $rs): iterable
    {
        $operadores = DB::table('operadores_planilla')
            ->whereIn('codigo', array_keys(SuaporteApiService::HOSTS))
            ->get(['id', 'codigo', 'nombre'])
            ->keyBy('id');

        $credenciales = OperadorCredencial::whereIn('operador_planilla_id', $operadores->keys())
            ->where(fn ($q) => $q->whereNull('razon_social_id')->orWhere('razon_social_id', $rs->id))
            ->get()
            ->sortBy(fn ($c) => ((int) $c->aliado_id === (int) $rs->aliado_id ? 0 : 1).'-'.$c->id);

        $vistas = [];

        foreach ($credenciales as $cred) {
            // Varios aliados comparten el mismo usuario del portal: con uno basta.
            $llave = $cred->operador_planilla_id.'|'.$cred->usuario;
            if (isset($vistas[$llave])) {
                continue;
            }
            $vistas[$llave] = true;

            yield [$cred, $operadores[$cred->operador_planilla_id]];
        }
    }

    private function sesion(OperadorCredencial $cred, string $codigoOperador): SuaporteApiService
    {
        if (isset($this->sesiones[$cred->id])) {
            return $this->sesiones[$cred->id];
        }

        if (isset($this->loginsFallidos[$cred->id])) {
            throw new \RuntimeException($this->loginsFallidos[$cred->id]);
        }

        $api = new SuaporteApiService([
            'operador'      => $codigoOperador,
            'usuario'       => $cred->usuario,
            'contrasena'    => $cred->contrasena,
            'clave_secreta' => $cred->clave_secreta,
            'timeout'       => 60,
        ]);

        $auth = $api->autenticar();
        if (! $auth['success']) {
            throw new \RuntimeException($this->loginsFallidos[$cred->id] = $auth['message']);
        }

        return $this->sesiones[$cred->id] = $api;
    }
}
