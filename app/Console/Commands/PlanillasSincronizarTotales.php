<?php

namespace App\Console\Commands;

use App\Models\OperadorCredencial;
use App\Models\OperadorPlanillaApi;
use App\Models\RazonSocial;
use App\Services\OdiDescuentoService;
use App\Services\SuaporteApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Trae del operador lo que dice de cada planilla ya liquidada.
 *
 * El soporte que se le entrega al cliente deduce hoy el número de afiliados, los
 * dos períodos y el desglose por entidad. El operador los tiene y son la verdad:
 * es lo que quedó radicado. Los períodos sobre todo, que salen de sumarle un mes
 * al del plano y ya se han equivocado antes.
 *
 * Va por comando y no al generar el PDF porque abrir sesión con el operador tarda
 * más de un minuto, y tras varias seguidas deja de responder —hoy nos cortó tras
 * media docena—. Cada planilla se pregunta una vez y de ahí en adelante sale de
 * la base.
 *
 * Solo sirve para los operadores que corren Enlace Operativo: ARUS y Simple.
 */
class PlanillasSincronizarTotales extends Command
{
    protected $signature = 'planillas:sincronizar-totales
                            {--aliado= : Solo este aliado}
                            {--planilla= : Solo este número de planilla}
                            {--limite=25 : Cuántas planillas como máximo}
                            {--refrescar : Volver a preguntar las que ya se preguntaron}
                            {--dry-run : Decir qué se preguntaría, sin tocar el operador}';

    protected $description = 'Guarda los totales que el operador reporta de cada planilla liquidada';

    public function handle(): int
    {
        $pendientes = OperadorPlanillaApi::query()
            ->whereNotNull('numero_planilla')
            ->when(! $this->option('refrescar'), fn ($q) => $q->whereNull('totales_at'))
            ->when($this->option('aliado'), fn ($q, $a) => $q->where('aliado_id', (int) $a))
            ->when($this->option('planilla'), fn ($q, $p) => $q->where('numero_planilla', $p))
            ->orderByDesc('id')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay planillas por consultar.');

            return self::SUCCESS;
        }

        $this->info("Planillas por consultar: {$pendientes->count()}");

        // Una sesión por razón social, no por planilla: el login es lo caro, y
        // varias planillas del mismo aportante se resuelven con la misma.
        $sesiones = [];
        $ok = $fallidas = 0;

        foreach ($pendientes as $registro) {
            $rs = RazonSocial::find($registro->razon_social_id);

            if (! $rs) {
                $this->warn("  {$registro->numero_planilla}: sin razón social.");
                $fallidas++;
                continue;
            }

            $etiqueta = "{$registro->numero_planilla} · ".mb_substr($rs->razon_social ?? '?', 0, 24);

            if ($this->option('dry-run')) {
                $this->line("  [seco] {$etiqueta}");
                continue;
            }

            try {
                $llave = $registro->aliado_id.'|'.$registro->operador_planilla_id.'|'.$rs->id;
                $api = $sesiones[$llave] ??= $this->abrirSesion($registro, $rs);

                $t = $api->consultarTotales((int) $registro->numero_planilla);

                if (! ($t['success'] ?? false)) {
                    $this->warn("  {$etiqueta}: {$t['message']}");
                    $fallidas++;
                    continue;
                }

                $this->guardar($registro, $t['totales'] ?? []);
                $this->line("  ✅ {$etiqueta}: {$registro->numero_afiliados} afiliados · "
                    ."períodos {$registro->periodo_cotizacion}/{$registro->periodo_servicio}");
                $ok++;

                // Con el desglose por administradora ya se ve si la planilla
                // descontó alguna ODI de la ARL (ver OdiDescuentoService).
                $this->registrarOdi($registro);
            } catch (Throwable $e) {
                // Un aportante que falla no debe detener a los demás: suele ser
                // su credencial o su sesión, no un problema general.
                $this->warn("  {$etiqueta}: ".mb_substr($e->getMessage(), 0, 120));
                $fallidas++;
            }
        }

        $this->newLine();
        $this->info("Guardadas: {$ok}   ·   Fallidas: {$fallidas}");

        return self::SUCCESS;
    }

    /** Login, aportante y autorización: los tres pasos que exige el operador. */
    private function abrirSesion(OperadorPlanillaApi $registro, RazonSocial $rs): SuaporteApiService
    {
        $operador = \DB::table('operadores_planilla')->find($registro->operador_planilla_id);

        if (! $operador || ! SuaporteApiService::soportaOperador($operador->codigo)) {
            throw new \RuntimeException('El operador no corre sobre Enlace Operativo.');
        }

        $cred = OperadorCredencial::paraOperador(
            (int) $registro->aliado_id, (int) $registro->operador_planilla_id, (int) $rs->id
        )->first() ?? throw new \RuntimeException("Faltan credenciales de {$operador->nombre}.");

        $api = new SuaporteApiService([
            'operador'      => $operador->codigo,
            'usuario'       => $cred->usuario,
            'contrasena'    => $cred->contrasena,
            'clave_secreta' => $cred->clave_secreta,
        ]);

        if (! ($a = $api->autenticar())['success']) {
            throw new \RuntimeException($a['message']);
        }

        $nit = preg_replace('/\D/', '', (string) $rs->nit);

        if (! ($ap = $api->consultarAportante('NI', $nit))['success']) {
            throw new \RuntimeException($ap['message']);
        }

        if (! ($au = $api->autorizar($ap['id'], 'NI', $nit))['success']) {
            throw new \RuntimeException($au['message']);
        }

        return $api;
    }

    /** Lo que devuelve `TotalPlanillaDTO`, en las columnas de la planilla. */
    private function guardar(OperadorPlanillaApi $registro, array $d): void
    {
        $registro->update([
            'nombre_aportante'   => $d['nombreAportante'] ?? null,
            'numero_afiliados'   => isset($d['numeroAfiliados']) ? (int) $d['numeroAfiliados'] : null,
            // El operador escribe "periodoCotizancion", con la n de más. Se lee
            // tal cual viene y se acepta también el nombre bien escrito, por si
            // algún día lo corrigen.
            'periodo_cotizacion' => $d['periodoCotizancion'] ?? $d['periodoCotizacion'] ?? null,
            'periodo_servicio'   => $d['periodoServicio'] ?? null,
            'fecha_limite'       => $this->fecha($d['fechaLimite'] ?? null),
            'total_administradoras' => isset($d['totalAdministradora'])
                ? json_encode($d['totalAdministradora'], JSON_UNESCAPED_UNICODE)
                : null,
            'valor_total'        => $d['totalPagar'] ?? $registro->valor_total,
            'totales_at'         => now(),
        ]);
    }

    private function registrarOdi(OperadorPlanillaApi $registro): void
    {
        if ($this->option('dry-run')) {
            return;
        }

        try {
            $r = app(OdiDescuentoService::class)->aplicar($registro->refresh());
        } catch (Throwable $e) {
            $this->warn('     ODI: '.mb_substr($e->getMessage(), 0, 120));

            return;
        }

        foreach ($r['detalle'] as $d) {
            $this->line("     📋 ODI {$d['numero']}: descontados $".number_format($d['valor'], 0, ',', '.'));
        }
        if ($r['sin_odi'] > 0) {
            $this->warn('     ⚠️ La ARL salió $'.number_format($r['sin_odi'], 0, ',', '.')
                .' por debajo de lo esperado y no hay ODI registrada que lo explique.');
        }
    }

    private function fecha(?string $valor): ?string
    {
        if (! $valor) {
            return null;
        }

        try {
            return Carbon::parse($valor)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
