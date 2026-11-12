<?php

namespace App\Services\Correo;

use App\Models\BrynexRepresentante;
use App\Models\BrynexRepresentanteCredencial;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Lee los correos de la DIAN de un representante legal para sacar el enlace de
 * acceso que manda el portal de facturación electrónica (catalogo-vpfe) cuando
 * se entra como «Representante legal». El enlace llega al correo del RUT
 * PERSONAL y dura 60 minutos.
 *
 * De dónde se lee, en orden:
 *  1. Su propio Gmail: una clave «Correo» del representante cuyo usuario es un
 *     Gmail y cuya contraseña es una contraseña de aplicación (16 letras).
 *  2. El Gmail central de reenvíos (`representantes.buzon_reenvios`), para los
 *     Hotmail/Outlook, que Microsoft ya no deja leer por IMAP con contraseña.
 *
 * Todo en solo lectura: no marca nada como leído.
 */
class CorreoDianRepresentante
{
    /** Lo que se le pide a Gmail; la misma sintaxis del buscador. */
    private const BUSQUEDA = 'from:dian.gov.co';

    /** Enlaces al portal de facturación electrónica, producción o habilitación. */
    private const PATRON_ENLACE = '#https?://catalogo-vpfe(?:-hab)?\.dian\.gov\.co/[^\s"\'<>]+#i';

    /**
     * @return array{buzon: BuzonGmail, via: string}|null  via = 'propio' | 'reenvios'
     */
    public function buzonDe(BrynexRepresentante $rep): ?array
    {
        $propia = $rep->credenciales()
            ->where('tipo', 'CORREO')
            ->where('activo', true)
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn ($c) => self::esGmail($c->usuario) && self::esClaveDeAplicacion($c->contrasena));

        if ($propia) {
            return ['buzon' => new BuzonGmail(strtolower(trim($propia->usuario)), self::limpiar($propia->contrasena)), 'via' => 'propio'];
        }

        $central = config('representantes.buzon_reenvios');
        if (! $central) {
            return null;
        }

        // La contraseña del central puede estar guardada como clave «Correo»
        // de cualquier representante, o en el módulo de claves de un aliado.
        $clave = BrynexRepresentanteCredencial::where('tipo', 'CORREO')
            ->where('usuario', $central)
            ->where('activo', true)
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn ($c) => self::esClaveDeAplicacion($c->contrasena));

        if ($clave) {
            return ['buzon' => new BuzonGmail($central, self::limpiar($clave->contrasena)), 'via' => 'reenvios'];
        }

        return BuzonGmail::tieneClave($central)
            ? ['buzon' => BuzonGmail::deCuenta($central), 'via' => 'reenvios']
            : null;
    }

    /**
     * Correos de la DIAN de los últimos días, del más nuevo al más viejo, sin
     * el enlace (solo si lo trae y si sigue vigente). Sirve para probar que el
     * buzón conecta sin exponer un enlace que da acceso a la empresa.
     *
     * @return array{cuenta: string, via: string, correos: array<int, array{fecha: string, asunto: string, con_enlace: bool, vigente: bool, es_suyo: bool}>}
     */
    public function revisar(BrynexRepresentante $rep, int $dias = 30): array
    {
        $b = $this->buzonDe($rep) ?? throw new RuntimeException($this->porQueNoHayBuzon($rep));

        $correos = collect($this->correosDian($b['buzon'], now()->subDays($dias), $rep, $b['via']))
            ->map(fn ($c) => [
                'fecha' => $c['fecha']->format('Y-m-d H:i'),
                'asunto' => $c['asunto'],
                'con_enlace' => $c['enlace'] !== null,
                'vigente' => $c['enlace'] !== null && $c['fecha']->gt(now()->subMinutes(self::minutos())),
                'es_suyo' => $c['es_suyo'],
            ])
            ->values()
            ->all();

        return ['cuenta' => $b['buzon']->cuenta(), 'via' => $b['via'], 'correos' => $correos];
    }

    /**
     * El enlace de acceso más reciente que sigue vigente, o null.
     *
     * @param  Carbon|null  $desde  Solo correos posteriores (p. ej. la hora en que el robot lo pidió).
     * @return array{enlace: string, fecha: string, asunto: string, cuenta: string}|null
     */
    public function enlaceVigente(BrynexRepresentante $rep, ?Carbon $desde = null): ?array
    {
        $b = $this->buzonDe($rep) ?? throw new RuntimeException($this->porQueNoHayBuzon($rep));

        $limite = now()->subMinutes(self::minutos());
        $desde = $desde && $desde->gt($limite) ? $desde : $limite;

        $c = collect($this->correosDian($b['buzon'], $desde, $rep, $b['via']))
            ->filter(fn ($c) => $c['enlace'] !== null && $c['es_suyo'] && $c['fecha']->gte($desde))
            ->first();

        return $c ? [
            'enlace' => $c['enlace'],
            'fecha' => $c['fecha']->format('Y-m-d H:i:s'),
            'asunto' => $c['asunto'],
            'cuenta' => $b['buzon']->cuenta(),
        ] : null;
    }

    public function porQueNoHayBuzon(BrynexRepresentante $rep): string
    {
        if (self::esGmail($rep->correo_rut)) {
            return "Falta la contraseña de aplicación de {$rep->correo_rut}: guárdala como clave «Correo» del representante (usuario = el Gmail, contraseña = las 16 letras).";
        }

        return $rep->correo_rut
            ? "{$rep->correo_rut} no es Gmail y no hay buzón central de reenvíos configurado (config/representantes.php)."
            : 'El representante no tiene el correo de su RUT personal.';
    }

    /**
     * @return array<int, array{fecha: Carbon, asunto: string, enlace: ?string, es_suyo: bool}>
     */
    private function correosDian(BuzonGmail $buzon, Carbon $desde, BrynexRepresentante $rep, string $via): array
    {
        $salida = [];

        foreach ($buzon->recibidos($desde, null, 30, self::BUSQUEDA, true) as $m) {
            $salida[] = [
                'fecha' => $m['fecha'],
                'asunto' => $m['asunto'],
                'enlace' => self::enlaceDe($m['html'] ?? '', $m['texto'] ?? ''),
                // En el buzón central llegan los reenvíos de varias personas:
                // se reconoce el suyo por su correo o su cédula en el mensaje.
                'es_suyo' => $via === 'propio' || self::mencionaA($m, $rep),
            ];
        }

        return collect($salida)->sortByDesc(fn ($c) => $c['fecha']->timestamp)->values()->all();
    }

    /** El enlace con token: el más largo de los que apuntan al portal (los cortos son el logo o la portada). */
    public static function enlaceDe(string $html, string $texto): ?string
    {
        preg_match_all(self::PATRON_ENLACE, html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' ' . $texto, $m);

        $enlaces = collect($m[0])
            ->map(fn ($e) => rtrim($e, '.,);'))
            ->filter(fn ($e) => strlen(parse_url($e, PHP_URL_QUERY) ?? '') > 15 || strlen(parse_url($e, PHP_URL_PATH) ?? '') > 40)
            ->sortByDesc(fn ($e) => strlen($e));

        return $enlaces->first();
    }

    private static function mencionaA(array $m, BrynexRepresentante $rep): bool
    {
        $todo = strtolower(implode(' ', $m['para'] ?? []) . ' ' . ($m['texto'] ?? '') . ' ' . strip_tags($m['html'] ?? ''));

        // El nombre completo también vale: la DIAN saluda al representante.
        $partes = array_filter(explode(' ', strtolower((string) $rep->nombre)), fn ($p) => strlen($p) > 2);
        $porNombre = count($partes) >= 2 && collect($partes)->every(fn ($p) => str_contains($todo, $p));

        return ($rep->correo_rut && str_contains($todo, strtolower($rep->correo_rut)))
            || str_contains(preg_replace('/\D/', '', $todo), $rep->cedula)
            || $porNombre;
    }

    private static function minutos(): int
    {
        return (int) config('representantes.minutos_enlace', 60);
    }

    public static function esGmail(?string $correo): bool
    {
        return (bool) preg_match('/@(gmail|googlemail)\.com$/i', trim((string) $correo));
    }

    private static function esClaveDeAplicacion(?string $clave): bool
    {
        return strlen(self::limpiar($clave)) === 16;
    }

    private static function limpiar(?string $clave): string
    {
        return preg_replace('/\s+/', '', (string) $clave);
    }
}
