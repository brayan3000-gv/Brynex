<?php

namespace App\Services\Afiliaciones;

use App\Models\ArlCredencial;
use App\Models\ClaveAcceso;
use App\Models\RazonSocial;
use App\Models\User;
use App\Services\ClavePortalSincronizador;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los portales de entidades de una empresa: qué EPS, ARL y caja tienen clave,
 * cuáles se trabajan por correo con el asesor y cuáles faltan.
 *
 * Todo va por NIT y no por la fila de la razón social: la misma empresa existe
 * una vez por aliado (Brygar presta las suyas a Fecop y a Luis Lopez), pero
 * ante la entidad la clave es una sola. Ver ClaveAcceso::visiblesPara. El
 * aliado 1 (BryNex) es de pruebas y no se mezcla con los demás.
 *
 * Las claves viejas tienen la entidad en texto libre; `clasificar()` la
 * traduce al catálogo, y al guardar desde aquí se deja el nombre que los
 * robots buscan con LIKE (p. ej. «SOS», no «S.O.S.»).
 */
class PortalesEntidades
{
    public const TIPOS = ['EPS', 'ARL', 'CAJA'];

    public const ARL_SURA = 3;

    private const ALIADO_PRUEBAS = 1;

    /**
     * Nombre que se escribe en `clave_accesos.entidad`, por código de EPS:
     * el que encuentran los LIKE de los robots. Si no está aquí, el nombre del
     * catálogo sin puntos.
     */
    private const NOMBRE_EPS = [
        'EPS010' => 'SURA',
        'EPS018' => 'SOS',
        'EPS037' => 'NUEVA EPS',
        'EPS002' => 'SALUD TOTAL',
        'EPS005' => 'SANITAS',
        'EPS012' => 'COMFENALCO VALLE',
        'ESSC18' => 'EMSSANAR',
        'EPS042' => 'COOSALUD',
        'ESSC62' => 'ASMET SALUD',
        'EPS040' => 'SAVIA SALUD',
        'EPS008' => 'COMPENSAR',
        'ESSC07' => 'MUTUAL SER',
    ];

    /**
     * Cómo aparece escrita cada entidad en las claves viejas (en mayúsculas y
     * sin nada que no sea letra o número) → código del catálogo.
     */
    private const ALIAS_EPS = [
        'SURA' => 'EPS010', 'SURAEPS' => 'EPS010', 'EPSSURA' => 'EPS010',
        'SANITAS' => 'EPS005',
        'SALUDTOTAL' => 'EPS002',
        'NUEVAEPS' => 'EPS037',
        'SOS' => 'EPS018',
        'COMFENALCO' => 'EPS012', 'COMFENALCOVALLE' => 'EPS012',
        'EMSSANAR' => 'ESSC18', 'EMSANAR' => 'ESSC18',
        'COOSALUD' => 'EPS042',
        'ASMETSALUD' => 'ESSC62', 'ASMET' => 'ESSC62',
        'FAMISANAR' => 'EPS017',
        'COMPENSAR' => 'EPS008', 'COMPENSAREPS' => 'EPS008',
        'AIC' => 'EPSIC3',
        'MUTUALSER' => 'ESSC07', 'EPSMUTUALSER' => 'ESSC07',
        'SAVIASALUD' => 'EPS040', 'SAVIASALUDEPS' => 'EPS040',
        'ALIANSALUD' => 'EPS001',
        'MALLAMAS' => 'EPSIC5',
        'CAJACOPI' => 'CCFC55',
    ];

    private const ALIAS_ARL = [
        'SURA' => 3, 'ARLSURA' => 3, 'SURAARL' => 3, 'SURAMAQUITEXTIL' => 3,
        'COLMENA' => 2, 'ARLCOLMENA' => 2,
        'POSITIVA' => 9, 'POSITIVAARL' => 9, 'ARLPOSITIVA' => 9,
        'COLPATRIA' => 6, 'AXACOLPATRIA' => 6,
        'EQUIDAD' => 4, 'LAEQUIDAD' => 4,
        'BOLIVAR' => 7, 'MAPFRE' => 5, 'LIBERTY' => 8,
    ];

    private const ALIAS_CAJA = [
        'COMFANDI' => 22, 'CAJACOMFANDI' => 22, 'COMFANDICAJA' => 22,
        'COMFENALCO' => 21, 'COMFENALCOVALLE' => 21,
        'COMFENALCOCARTAGENA' => 23,
        'COMPENSAR' => 10, 'CAJACOMPENSAR' => 10,
        'CAFAM' => 8,
        'COLSUBSIDIO' => 7, 'COLSUBSIDIOS' => 7,
        'COMFACAUCA' => 19,
        'COMFAMA' => 31,
        'CAJACOPI' => 14,
        'CONFA' => 29,
    ];

    /** Portales que no son de una EPS aunque estén tipificados así. */
    private const OTROS = ['SAT'];

    // ─── Acceso ────────────────────────────────────────────────────────

    /**
     * La razón social, si el usuario puede verla desde el aliado activo: es
     * suya, comparte el NIT con una suya, o es BryNex con acceso a ese aliado
     * (la vista de afiliaciones gestionadas lista empresas de varios aliados).
     */
    public static function razonSocialVisible(int $id, int $aliadoActivo, ?User $user): ?RazonSocial
    {
        $rs = RazonSocial::find($id);

        if (! $rs) {
            return null;
        }

        if ((int) $rs->aliado_id === $aliadoActivo) {
            return $rs;
        }

        if ($user?->es_brynex && $user->puedeAccederAliado((int) $rs->aliado_id)) {
            return $rs;
        }

        $nit = self::nit($rs->nit);

        $comparte = strlen($nit) >= 6 && (int) $rs->aliado_id !== self::ALIADO_PRUEBAS
            && RazonSocial::where('aliado_id', $aliadoActivo)->where('nit', $nit)->exists();

        return $comparte ? $rs : null;
    }

    // ─── Lectura ───────────────────────────────────────────────────────

    /**
     * La tabla de la pestaña: una fila por entidad con su estado.
     *
     * @param  array{eps?: ?int, arl?: ?int, caja?: ?int}  $delContrato  entidades del contrato desde
     *                                                                   el que se abrió, para resaltarlas
     */
    public static function tabla(RazonSocial $rs, bool $verContrasena, int $aliadoActivo, array $delContrato = []): array
    {
        $claves = self::clavesDeNit($rs);
        $afiliados = self::afiliadosPorEps($rs);
        $credSura = ArlCredencial::deEmpresa($rs->nit);

        $filas = [];

        // ARL: la configurada en la empresa, y la del contrato si es otra.
        $arls = DB::table('arls')->get(['id', 'nit', 'nombre_arl'])->keyBy('id');
        $arlConfigurada = $arls->first(fn ($a) => (string) $a->nit !== '0' && (string) $a->nit === (string) $rs->arl_nit);
        foreach (array_unique(array_filter([$arlConfigurada?->id, $delContrato['arl'] ?? null])) as $id) {
            if (($a = $arls->get($id)) && (string) $a->nit !== '0') {
                $filas[] = self::fila('ARL', (int) $a->id, $a->nombre_arl, null, (int) $a->id === (int) $arlConfigurada?->id);
            }
        }

        // Caja: igual.
        $cajas = DB::table('cajas')->get(['id', 'nit', 'nombre'])->keyBy('id');
        $cajaConfigurada = $cajas->first(fn ($c) => (string) $c->nit !== '0' && (string) $c->nit === (string) $rs->caja_nit);
        foreach (array_unique(array_filter([$cajaConfigurada?->id, $delContrato['caja'] ?? null])) as $id) {
            if (($c = $cajas->get($id)) && (string) $c->nit !== '0') {
                $filas[] = self::fila('CAJA', (int) $c->id, $c->nombre, null, (int) $c->id === (int) $cajaConfigurada?->id);
            }
        }

        // EPS: las vigentes, más cualquiera con afiliados o con clave guardada
        // aunque no esté marcada vigente (que no se esconda lo que ya hay).
        $conClave = $claves->where('entidad_tipo', 'EPS')->pluck('entidad_id')->map(fn ($v) => (int) $v)->all();
        $eps = DB::table('eps')
            ->whereNull('reemplazada_por_id')
            ->where(fn ($q) => $q->where('vigente', true)
                ->orWhereIn('id', array_keys($afiliados) ?: [0])
                ->orWhereIn('id', $conClave ?: [0])
                ->orWhere('id', (int) ($delContrato['eps'] ?? 0)))
            ->where('codigo', '<>', 'N/A')
            ->get(['id', 'codigo', 'nombre']);

        $filasEps = $eps->map(fn ($e) => self::fila('EPS', (int) $e->id, $e->nombre, $afiliados[(int) $e->id] ?? 0))
            ->sortBy([['afiliados', 'desc'], ['nombre', 'asc']])
            ->values()->all();

        $filas = array_merge($filas, $filasEps);

        foreach ($filas as &$f) {
            $suyas = $claves->filter(fn ($c) => $c->entidad_tipo === $f['tipo'] && (int) $c->entidad_id === $f['id']);
            $principal = self::principal($suyas);

            $f['clave'] = $principal ? self::claveJson($principal, $verContrasena, $aliadoActivo) : null;
            $f['otras'] = max(0, $suyas->count() - 1);

            // ARL Sura: la clave es del usuario del portal, no de la empresa.
            if ($f['tipo'] === 'ARL' && $f['id'] === self::ARL_SURA && $credSura?->usuarioPortal) {
                $f['sura'] = [
                    'usuario' => $credSura->usuario,
                    'contrasena' => $verContrasena ? $credSura->contrasena : ($credSura->contrasena ? '__oculta__' : null),
                    'error' => $credSura->usuarioPortal->ultimo_error,
                ];
            }

            $f['del_contrato'] = (int) ($delContrato[strtolower($f['tipo'])] ?? 0) === $f['id'];
            $f['estado'] = self::estado($f);
        }
        unset($f);

        $sinClasificar = $claves
            ->filter(fn ($c) => ! $c->entidad_id && $c->entidad_tipo !== 'OTRO'
                && in_array(strtoupper((string) $c->tipo), self::TIPOS, true))
            ->map(fn ($c) => self::claveJson($c, $verContrasena, $aliadoActivo))
            ->values()->all();

        $faltan = collect($filas)->where('estado', 'falta');

        return [
            'razon_social' => ['id' => $rs->id, 'nombre' => $rs->razon_social, 'nit' => $rs->nit],
            'sin_arl' => ! $arlConfigurada,
            'sin_caja' => ! $cajaConfigurada,
            'filas' => $filas,
            'sin_clasificar' => $sinClasificar,
            'resumen' => [
                'total' => count($filas),
                'faltan' => $faltan->count(),
                'faltan_con_afiliados' => $faltan->filter(fn ($f) => $f['tipo'] !== 'EPS' || $f['afiliados'] > 0)->count(),
            ],
        ];
    }

    private static function fila(string $tipo, int $id, string $nombre, ?int $afiliados, bool $configurada = false): array
    {
        return [
            'tipo' => $tipo,
            'id' => $id,
            'nombre' => $nombre,
            'afiliados' => (int) $afiliados,
            'configurada' => $configurada,
        ];
    }

    /** portal | portal_correo | correo | no_aplica | falta */
    private static function estado(array $f): string
    {
        $c = $f['clave'];

        if ($c && $c['no_aplica']) {
            return 'no_aplica';
        }

        $portal = ($c && $c['usuario'] && $c['contrasena']) || isset($f['sura']);
        $correo = $c && $c['asesor_correo'];

        return match (true) {
            $portal && $correo => 'portal_correo',
            $portal => 'portal',
            $correo => 'correo',
            default => 'falta',
        };
    }

    /** Si hay varias para la misma entidad, la que usan los robots: con clave y la más reciente. */
    private static function principal(Collection $claves): ?ClaveAcceso
    {
        return $claves
            ->sortByDesc(fn ($c) => [($c->usuario && $c->contrasena) ? 1 : 0, (string) $c->updated_at])
            ->first();
    }

    private static function claveJson(ClaveAcceso $c, bool $verContrasena, int $aliadoActivo): array
    {
        return [
            'id' => (int) $c->id,
            'tipo' => $c->tipo,
            'entidad' => $c->entidad,
            'usuario' => $c->usuario,
            'contrasena' => $verContrasena ? $c->contrasena : ($c->contrasena ? '__oculta__' : null),
            'link_acceso' => $c->link_acceso,
            'correo_entidad' => $c->correo_entidad,
            'observacion' => $c->observacion,
            'sin_portal' => (bool) $c->sin_portal,
            'no_aplica' => (bool) $c->no_aplica,
            'asesor_nombre' => $c->asesor_nombre,
            'asesor_correo' => $c->asesor_correo,
            'asesor_telefono' => $c->asesor_telefono,
            'asesor2_nombre' => $c->asesor2_nombre,
            'asesor2_correo' => $c->asesor2_correo,
            'asesor2_telefono' => $c->asesor2_telefono,
            'cargada_por' => $c->esDeOtroAliado($aliadoActivo) ? ($c->aliado?->nombre ?? 'otro aliado') : null,
            'actualizada' => $c->updated_at?->format('d/m/Y'),
        ];
    }

    /** Las filas de la misma empresa en todos los aliados (el de pruebas, aparte). */
    public static function filasDeNit(RazonSocial $rs): Collection
    {
        $nit = self::nit($rs->nit);

        if (strlen($nit) < 6) {
            return collect([$rs]);
        }

        $pruebas = (int) $rs->aliado_id === self::ALIADO_PRUEBAS;

        return RazonSocial::whereRaw("REPLACE(REPLACE(REPLACE(ISNULL(nit,''),'-',''),'.',''),' ','') = ?", [$nit])
            ->when($pruebas,
                fn ($q) => $q->where('aliado_id', self::ALIADO_PRUEBAS),
                fn ($q) => $q->where('aliado_id', '<>', self::ALIADO_PRUEBAS))
            ->get(['id', 'aliado_id', 'nit', 'razon_social']);
    }

    private static function clavesDeNit(RazonSocial $rs): Collection
    {
        return ClaveAcceso::with('aliado:id,nombre')
            ->whereIn('razon_social_id', self::filasDeNit($rs)->pluck('id'))
            ->where('activo', true)
            ->get();
    }

    /** Contratos vigentes de la empresa por EPS, en todos los aliados que la comparten. */
    private static function afiliadosPorEps(RazonSocial $rs): array
    {
        return DB::table('contratos')
            ->whereIn('razon_social_id', self::filasDeNit($rs)->pluck('id'))
            ->where('estado', 'vigente')
            ->whereNotNull('eps_id')
            ->groupBy('eps_id')
            ->selectRaw('eps_id, COUNT(*) as n')
            ->pluck('n', 'eps_id')
            ->mapWithKeys(fn ($n, $id) => [(int) $id => (int) $n])
            ->all();
    }

    // ─── Escritura ─────────────────────────────────────────────────────

    /**
     * Guarda los datos de una entidad para la empresa.
     *
     * Edita la clave que se ve en la tabla aunque la haya cargado otro aliado
     * (la clave es de la empresa, y la bitácora dice quién la cambió). Si no
     * hay, la crea en la fila del aliado activo cuando la tiene, y si no en la
     * que se está viendo.
     *
     * @return array{clave: ClaveAcceso, aviso: string}
     */
    public static function guardar(RazonSocial $rs, string $tipo, int $entidadId, array $datos, int $aliadoActivo): array
    {
        $nombre = self::nombreEntidad($tipo, $entidadId);
        abort_if($nombre === null, 422, 'Entidad desconocida.');

        $filas = self::filasDeNit($rs);
        $suyas = ClaveAcceso::whereIn('razon_social_id', $filas->pluck('id'))
            ->where('activo', true)
            ->where('entidad_tipo', $tipo)->where('entidad_id', $entidadId)
            ->get();
        $clave = self::principal($suyas);

        if (! $clave) {
            $propia = $filas->first(fn ($f) => (int) $f->aliado_id === $aliadoActivo) ?? $rs;
            $clave = new ClaveAcceso([
                'aliado_id' => (int) $propia->aliado_id,
                'razon_social_id' => $propia->id,
                'activo' => true,
            ]);
        }

        // La contraseña tapada vuelve como '__oculta__' y la vacía no borra:
        // para quitarla está el módulo de claves.
        if (! isset($datos['contrasena']) || $datos['contrasena'] === '' || $datos['contrasena'] === '__oculta__') {
            unset($datos['contrasena']);
        }

        $clave->fill($datos);
        $clave->entidad_tipo = $tipo;
        $clave->entidad_id = $entidadId;
        $clave->entidadFijada = true;
        $clave->tipo = $tipo;

        // El texto que buscan los robots, salvo que el que ya tiene la
        // traduzca a esta misma entidad («Sura ARL» se queda como está).
        if (self::clasificarTexto($tipo, (string) $clave->entidad) !== $entidadId) {
            $clave->entidad = $nombre;
        }

        $clave->save();

        $aviso = '';
        if ($clave->usuario && $clave->contrasena) {
            $r = ClavePortalSincronizador::propagar($clave);
            if ($r['filas'] > 1) {
                $aviso = ' Se actualizó también en las otras '.($r['filas'] - 1)." entradas del usuario {$clave->usuario}.";
            }

            if ($tipo === 'ARL' && $entidadId === self::ARL_SURA && strlen(self::nit($rs->nit)) >= 6) {
                ArlCredencial::registrar($aliadoActivo, self::nit($rs->nit), 'C', trim($clave->usuario), (string) $clave->contrasena);
            }
        }

        return ['clave' => $clave, 'aviso' => $aviso];
    }

    /** Liga una clave vieja a su entidad del catálogo, sin tocar nada más. */
    public static function asignar(RazonSocial $rs, int $claveId, string $tipo, ?int $entidadId): ClaveAcceso
    {
        $clave = ClaveAcceso::whereIn('razon_social_id', self::filasDeNit($rs)->pluck('id'))
            ->findOrFail($claveId);

        $clave->entidadFijada = true;

        if ($tipo === 'OTRO') {
            $clave->update(['entidad_tipo' => 'OTRO', 'entidad_id' => null]);

            return $clave;
        }

        abort_if(self::nombreEntidad($tipo, (int) $entidadId) === null, 422, 'Entidad desconocida.');

        $clave->update(['entidad_tipo' => $tipo, 'entidad_id' => $entidadId]);

        return $clave;
    }

    // ─── Catálogo ──────────────────────────────────────────────────────

    /** El nombre con que se guarda la entidad en la clave, o null si no existe. */
    public static function nombreEntidad(string $tipo, int $id): ?string
    {
        return match ($tipo) {
            'EPS' => ($e = DB::table('eps')->where('id', $id)->first(['codigo', 'nombre']))
                ? (self::NOMBRE_EPS[$e->codigo] ?? trim(str_replace('.', '', $e->nombre)))
                : null,
            'ARL' => DB::table('arls')->where('id', $id)->value('nombre_arl'),
            'CAJA' => DB::table('cajas')->where('id', $id)->value('nombre'),
            default => null,
        };
    }

    /** Para el selector de «Sin clasificar». */
    public static function catalogo(): array
    {
        return [
            'EPS' => DB::table('eps')->whereNull('reemplazada_por_id')->where('codigo', '<>', 'N/A')
                ->orderByDesc('vigente')->orderBy('nombre')->get(['id', 'nombre', 'vigente']),
            'ARL' => DB::table('arls')->where('nit', '<>', '0')->orderBy('nombre_arl')->get(['id', 'nombre_arl as nombre']),
            'CAJA' => DB::table('cajas')->where('nit', '<>', '0')->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }

    /**
     * A qué entidad del catálogo corresponde el texto de una clave vieja.
     * Devuelve el id, o null si el texto no alcanza («EPS», «Caja»…).
     */
    public static function clasificarTexto(string $tipo, string $entidad): ?int
    {
        $llave = self::llave($entidad);

        return match ($tipo) {
            'EPS' => isset(self::ALIAS_EPS[$llave])
                ? (int) DB::table('eps')->where('codigo', self::ALIAS_EPS[$llave])->value('id') ?: null
                : null,
            'ARL' => self::ALIAS_ARL[$llave] ?? null,
            'CAJA' => self::ALIAS_CAJA[$llave] ?? null,
            default => null,
        };
    }

    /**
     * Clasificación de una clave vieja: [entidad_tipo, entidad_id, cómo].
     *
     * Los textos genéricos de ARL y caja («ARL», «Caja») se resuelven con la
     * ARL o la caja configurada en la empresa; los de EPS no, porque una
     * empresa trabaja con muchas EPS.
     *
     * @return array{0: ?string, 1: ?int, 2: string}
     */
    public static function clasificar(ClaveAcceso $c, ?RazonSocial $rs): array
    {
        $tipo = strtoupper(trim((string) $c->tipo));
        $llave = self::llave((string) $c->entidad);

        if (in_array($llave, self::OTROS, true)) {
            return ['OTRO', null, 'portal que no es de una EPS'];
        }

        if (in_array($tipo, self::TIPOS, true)) {
            if ($id = self::clasificarTexto($tipo, (string) $c->entidad)) {
                return [$tipo, $id, 'por nombre'];
            }

            $nitConfigurado = $tipo === 'ARL' ? $rs?->arl_nit : ($tipo === 'CAJA' ? $rs?->caja_nit : null);
            if ($nitConfigurado && (string) $nitConfigurado !== '0') {
                $id = DB::table($tipo === 'ARL' ? 'arls' : 'cajas')->where('nit', $nitConfigurado)->value('id');
                if ($id) {
                    return [$tipo, (int) $id, 'la configurada en la empresa'];
                }
            }

            return [null, null, 'sin clasificar'];
        }

        // «Portal» u «Otro» con un nombre que solo puede ser de una EPS o una caja.
        if (in_array($tipo, ['PORTAL', 'OTRO'], true)) {
            if ($id = self::clasificarTexto('EPS', (string) $c->entidad)) {
                // Sura es ARL y EPS a la vez: con el tipo «Portal» no se sabe.
                return $llave === 'SURA' ? [null, null, 'sin clasificar'] : ['EPS', $id, 'por nombre (tipo '.$c->tipo.')'];
            }
            if ($id = self::clasificarTexto('CAJA', (string) $c->entidad)) {
                return ['CAJA', $id, 'por nombre (tipo '.$c->tipo.')'];
            }
        }

        return [null, null, 'sin clasificar'];
    }

    private static function llave(string $texto): string
    {
        $texto = strtoupper(strtr(trim($texto), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ñ' => 'N']));

        return preg_replace('/[^A-Z0-9]/', '', $texto);
    }

    private static function nit($nit): string
    {
        return preg_replace('/\D/', '', (string) $nit);
    }
}
