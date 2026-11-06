<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\RazonSocial;
use App\Models\Tarea;
use App\Services\ArlSura\ArlConciliacionService;
use App\Services\ArlSura\ArlSuraApiService;
use App\Services\ArlSura\ArlSuraMoraService;
use App\Services\ArlSura\ArlSuraSesionService;
use App\Services\TareaAutomaticaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cruza los afiliados de ARL Sura con los contratos vigentes de BryNex.
 *
 * El servicio que compara ya existía, pero solo corría cuando alguien abría la
 * pantalla, y nadie abre una pantalla a diario. Los dos desfases cuestan:
 *
 *  - en Sura y no en BryNex → se paga cobertura de quien ya no está;
 *  - en BryNex y no en Sura → el trabajador se cree cubierto y no lo está,
 *    y eso solo se descubre el día del accidente.
 */
class ArlConciliar extends Command
{
    protected $signature = 'arl:conciliar
                            {--nit= : Solo esta empresa}
                            {--sin-mora : Solo cruza afiliados, sin revisar los que están sin pago}
                            {--meses=3 : Cuántos meses exigibles se revisan de mora}
                            {--simular : Consulta el portal pero no crea ni cierra tareas}';

    protected $description = 'Cruza los afiliados de ARL Sura con los contratos vigentes, revisa quién está sin pago y abre las tareas';

    private const PREFIJO = 'arlsura:cruce';

    /** Días que se le dan a una afiliación nueva para aparecer en el portal. */
    private const DIAS_DE_GRACIA = 5;

    public function handle(TareaAutomaticaService $tareas, ArlSuraMoraService $mora): int
    {
        $empresas = $this->empresas();

        if ($empresas->isEmpty()) {
            $this->warn('Ninguna empresa con póliza de ARL Sura y clave del portal.');

            return self::SUCCESS;
        }

        $fallos = 0;

        foreach ($empresas as $empresa) {
            $this->line("{$empresa->nit} {$empresa->razon_social} (póliza {$empresa->arl_poliza})…");

            try {
                // La sesión se abre aparte y antes de consultar: estrenarla
                // dentro de la primera consulta la deja colgada hasta el timeout
                // (60 s), mientras que con la sesión ya viva la misma consulta
                // tarda un segundo.
                $this->asegurarSesion($empresa);

                // Una por empresa: el servicio entra al portal con la credencial
                // de esa póliza, así que no se puede reutilizar entre empresas.
                $r = ArlConciliacionService::paraPoliza((int) $empresa->aliado_id, $empresa->arl_poliza)
                    ->conciliar($empresa->nit, $empresa->arl_poliza);
            } catch (\Throwable $e) {
                $fallos++;
                $this->error('  '.mb_substr($e->getMessage(), 0, 160));
                Log::warning('ARL Sura: falló el cruce', ['nit' => $empresa->nit, 'error' => $e->getMessage()]);

                continue;
            }

            $vistas = [];
            $motivos = [];
            $nuevas = 0;

            // Los que rotaron a otra empresa del mismo cliente van juntos: son
            // decenas y se depuran de una sentada con la lista delante. Una
            // tarea por cabeza ahí solo consigue que no se mire ninguna.
            $rotados = array_values(array_filter($r['sobran'], fn ($c) => str_contains($c['situacion'], 'otra empresa')));
            $nuevas += $this->abrirResumen($tareas, $empresa, $rotados, $vistas);

            foreach ($r['sobran'] as $caso) {
                if (str_contains($caso['situacion'], 'otra empresa')) {
                    continue;
                }

                $nuevas += $this->abrir($tareas, $empresa, $caso, $vistas,
                    "Retirar de ARL Sura a {$caso['nombre']}: {$caso['situacion']}.",
                    "ARL Sura lo tiene afiliado en la póliza {$empresa->arl_poliza}, pero en BryNex {$caso['situacion']}"
                        .'. Mientras siga afiliado se paga su cobertura.',
                    $caso['contrato_id'] ?? null);
            }

            // Póliza vacía en Sura con gente vigente en BryNex: no son N personas
            // por afiliar, es la póliza la que hay que revisar (se canceló, se
            // retiró a todos o la clave ya no la ve). Pasó con WORK AT HOME el
            // 30-sep-2026: 26 tareas de «afiliar» a gente que Sura retiró ese día.
            $polizaVacia = $r['en_sura'] === 0 && $r['en_brynex'] > 0;

            if ($polizaVacia) {
                $nuevas += $this->abrirPolizaVacia($tareas, $empresa, $r['faltan'], $vistas);

                foreach ($r['faltan'] as $caso) {
                    $motivos[$this->llave($empresa->nit, $caso['documento'])] =
                        'ARL Sura muestra la póliza '.$empresa->arl_poliza.' vacía: se revisa en una sola tarea de la póliza.';
                }
            }

            foreach ($polizaVacia ? [] : $r['faltan'] as $caso) {
                // A una afiliación recién hecha hay que darle tiempo de llegar
                // al portal: sin esto, todo el que ingresa esta semana sale como
                // si estuviera sin cobertura.
                $desde = $caso['desde'] ? Carbon::createFromFormat('d/m/Y', $caso['desde']) : null;

                if ($desde && $desde->greaterThan(now()->subDays(self::DIAS_DE_GRACIA))) {
                    continue;
                }

                // La afiliación ya está en la cola de radicados: quien la
                // tiene allá es quien la hace, y una tarea más solo la duplica.
                if ($caso['en_tramite'] ?? false) {
                    $motivos[$this->llave($empresa->nit, $caso['documento'])] =
                        'La afiliación a la ARL sigue en la cola de radicados: se gestiona desde allá.';

                    continue;
                }

                $nuevas += $this->abrir($tareas, $empresa, $caso, $vistas,
                    "Afiliar a ARL Sura a {$caso['nombre']}: tiene contrato vigente y no aparece en la póliza.",
                    "En BryNex el contrato está vigente desde {$caso['desde']} con riesgo {$caso['riesgo']}"
                        .($caso['plan'] ? " (plan {$caso['plan']})" : '')
                        .', pero ARL Sura no lo tiene en la póliza '.$empresa->arl_poliza
                        .'. Sin cobertura, un accidente no queda amparado.',
                    $caso['contrato_id'] ?? null);
            }

            $cerradas = $this->cerrarResueltas($tareas, $empresa->nit, $vistas, $motivos);

            $this->info("  {$r['en_sura']} en Sura · {$r['en_brynex']} vigentes en BryNex · "
                .count($r['sobran']).' sobra(n) · '.count($r['faltan']).' falta(n) · '
                .($this->option('simular') ? 'abriría ' : '')."{$nuevas} tarea(s) · {$cerradas} cerrada(s)");

            if ($this->option('sin-mora')) {
                continue;
            }

            // Y de paso, quién está afiliado pero sin pagar: es otra consulta
            // del mismo portal y la sesión ya está abierta.
            $m = $mora->revisar($empresa->nit, $empresa->arl_poliza, (bool) $this->option('simular'), (int) $this->option('meses'));

            if (! ($m['ok'] ?? false)) {
                $this->warn('  sin pago: '.($m['error'] ?? 'sin detalle'));

                continue;
            }

            $porCausa = collect($m['detalle'] ?? [])
                ->filter(fn ($d) => isset($d['causa']))
                ->countBy('causa')
                ->map(fn ($n, $causa) => "{$causa}: {$n}")
                ->implode(' · ');

            $this->info('  sin pago: '.($this->option('simular') ? 'abriría ' : '').$m['nuevas'].' tarea(s) · '
                .$m['cerradas'].' cerrada(s)'.($porCausa ? "  [{$porCausa}]" : '  ninguno'));
        }

        return $fallos && $fallos === $empresas->count() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Una sola tarea con los que se fueron a otra empresa del mismo cliente.
     *
     * Se rehace el texto en cada corrida: la lista cambia sola según se vayan
     * retirando, y una tarea que se quedó con la foto del primer día miente.
     */
    private function abrirResumen(TareaAutomaticaService $tareas, $empresa, array $rotados, array &$vistas): int
    {
        if (! $rotados) {
            return 0;
        }

        $llave = self::PREFIJO.":{$empresa->nit}:rotados";
        $vistas[] = $llave;

        $lista = collect($rotados)
            ->map(fn ($c) => '· '.$c['documento'].' '.$c['nombre'].($c['otra_empresa'] ? ' → '.$c['otra_empresa'] : ''))
            ->implode("\n");

        $texto = 'Retirar de la póliza '.$empresa->arl_poliza.' a '.count($rotados)
            .' persona(s) que ya cotizan por otra empresa del cliente.';
        $observacion = "ARL Sura las tiene en la póliza de {$empresa->razon_social}, pero en BryNex su contrato vigente está en otra "
            ."razón social: se está pagando su cobertura dos veces.\n\n".$lista;

        if ($ya = $tareas->activaPorLlave((int) $empresa->aliado_id, $llave)) {
            if (! $this->option('simular') && trim((string) $ya->observacion) !== trim($observacion)) {
                $tareas->anotar($ya, '🤖 '.$observacion, 'nota');
            }

            return 0;
        }

        if ($this->option('simular')) {
            return 1;
        }

        return $tareas->abrir([
            'aliado_id' => (int) $empresa->aliado_id,
            'tipo' => 'otros',
            'cedula' => $rotados[0]['documento'],
            'razon_social_id' => $empresa->id,
            'entidad' => 'ARL SURA',
            'tarea' => $texto,
            'observacion' => $observacion,
            'llave_auto' => $llave,
        ]) ? 1 : 0;
    }

    /**
     * Una sola tarea cuando Sura no muestra a nadie en la póliza.
     *
     * Queda en el aliado de la póliza: es un asunto de la empresa con la ARL,
     * no de cada trabajador. La lista dice de qué aliado es cada uno.
     */
    private function abrirPolizaVacia(TareaAutomaticaService $tareas, $empresa, array $faltan, array &$vistas): int
    {
        $llave = self::PREFIJO.":{$empresa->nit}:poliza-vacia";
        $vistas[] = $llave;

        $lista = collect($faltan)
            ->map(fn ($c) => '· '.$c['documento'].' '.$c['nombre'].($c['aliado'] ? ' ('.$c['aliado'].')' : ''))
            ->implode("\n");

        $texto = 'Revisar la póliza '.$empresa->arl_poliza.' en ARL Sura: aparece sin afiliados y en BryNex hay '
            .count($faltan).' contrato(s) vigente(s).';
        $observacion = "El portal de ARL Sura no muestra afiliados ni centros de trabajo en la póliza de {$empresa->razon_social}. "
            .'Puede que la póliza se haya cancelado, que hayan retirado a todos o que la clave ya no la vea. '
            ."Mientras tanto, estos contratos están vigentes en BryNex:\n\n".$lista;

        if ($ya = $tareas->activaPorLlave((int) $empresa->aliado_id, $llave)) {
            if (! $this->option('simular') && trim((string) $ya->observacion) !== trim($observacion)) {
                $tareas->anotar($ya, '🤖 '.$observacion, 'nota');
            }

            return 0;
        }

        if ($this->option('simular') || ! $faltan) {
            return $faltan ? 1 : 0;
        }

        return $tareas->abrir([
            'aliado_id' => (int) $empresa->aliado_id,
            'tipo' => 'otros',
            'cedula' => $faltan[0]['documento'],
            'razon_social_id' => $empresa->id,
            'entidad' => 'ARL SURA',
            'tarea' => $texto,
            'observacion' => $observacion,
            'llave_auto' => $llave,
        ]) ? 1 : 0;
    }

    /** Deja la sesión del portal lista antes de consultar. */
    private function asegurarSesion($empresa): void
    {
        $api = new ArlSuraApiService((int) $empresa->aliado_id, (string) $empresa->arl_poliza);

        if ($api->sesionViva()) {
            return;
        }

        ArlSuraSesionService::renovar((int) $empresa->aliado_id, (string) $empresa->arl_poliza);
        // Un respiro: la sesión recién creada no atiende bien el primer golpe.
        sleep(3);
    }

    /**
     * Abre la tarea de ese desfase, si no estaba ya.
     *
     * Va al aliado dueño del contrato, no al de la póliza: las razones sociales
     * se comparten, así que en la póliza de BRYGAR hay trabajadores de Fecop,
     * INTEGRA y otros. Abierta en BRYGAR, la tarea quedaba sin contrato ni
     * nombre y nadie allá encontraba a la persona. Solo si no hay contrato
     * (alguien que está en Sura y nunca estuvo en BryNex) queda en el aliado
     * de la póliza.
     */
    private function abrir(TareaAutomaticaService $tareas, $empresa, array $caso, array &$vistas, string $texto, string $observacion, ?int $contratoId): int
    {
        $documento = ltrim(preg_replace('/\D/', '', (string) $caso['documento']), '0');
        $llave = $this->llave($empresa->nit, $documento);
        $vistas[] = $llave;
        $aliadoId = (int) (($contratoId ? ($caso['aliado_id'] ?? null) : null) ?: $empresa->aliado_id);

        $this->cerrarEnOtroAliado($tareas, $llave, $aliadoId, $caso['aliado'] ?? null);

        if ($tareas->activaPorLlave($aliadoId, $llave)) {
            return 0;
        }

        if ($this->option('simular')) {
            return 1;
        }

        return $tareas->abrir([
            'aliado_id' => $aliadoId,
            'tipo' => 'otros',
            'cedula' => $documento,
            'contrato_id' => $contratoId,
            // Con contrato, la razón social es la de ese contrato (la copia de
            // la empresa en su aliado); sin él, la de la póliza.
            'razon_social_id' => $contratoId ? null : $empresa->id,
            'entidad' => 'ARL SURA',
            'tarea' => $texto,
            'observacion' => $observacion,
            'llave_auto' => $llave,
        ]) ? 1 : 0;
    }

    /**
     * Cierra la tarea de ese mismo hallazgo que quedó abierta en otro aliado.
     *
     * Hasta nov-2026 todas se abrían en BRYGAR, también las de gente de otros
     * aliados; así se pasan solas a donde corresponden en la siguiente corrida.
     */
    private function cerrarEnOtroAliado(TareaAutomaticaService $tareas, string $llave, int $aliadoId, ?string $aliadoNombre): void
    {
        if ($this->option('simular')) {
            return;
        }

        $nombre = $aliadoNombre ?: (Aliado::whereKey($aliadoId)->value('nombre') ?? "aliado {$aliadoId}");

        foreach (Tarea::where('llave_auto', $llave)->where('aliado_id', '<>', $aliadoId)
            ->whereIn('estado', Tarea::ESTADOS_ACTIVOS)->get() as $tarea) {
            $tareas->cerrar($tarea, "Esta persona es de {$nombre}: la tarea se pasó a ese aliado.");
        }
    }

    private function llave(string $nit, string $documento): string
    {
        return self::PREFIJO.":{$nit}:".ltrim(preg_replace('/\D/', '', $documento), '0');
    }

    /**
     * Cierra las tareas cuyo hallazgo ya no aparece.
     *
     * @param  array<string, string>  $motivos  por llave, por qué se cierra cuando no es
     *                                          que ya coincidan (p. ej., sigue en radicados)
     */
    private function cerrarResueltas(TareaAutomaticaService $tareas, string $nit, array $vistas, array $motivos = []): int
    {
        $cerradas = 0;

        foreach (Tarea::whereNotNull('llave_auto')->where('llave_auto', 'like', self::PREFIJO.":{$nit}:%")
            ->whereIn('estado', Tarea::ESTADOS_ACTIVOS)->get() as $tarea) {
            if (in_array($tarea->llave_auto, $vistas, true)) {
                continue;
            }

            if ($this->option('simular')) {
                $cerradas++;

                continue;
            }

            // Sin motivo propio no se afirma que ya coincidan: también desaparece
            // el hallazgo cuando el contrato era del aliado de pruebas.
            $motivo = $motivos[$tarea->llave_auto]
                ?? 'La revisión del '.now()->format('d/m/Y').' ya no encuentra este desfase entre BryNex y ARL Sura.';

            if ($tareas->cerrar($tarea, $motivo)) {
                $cerradas++;
            }
        }

        return $cerradas;
    }

    /**
     * Las empresas con póliza y clave del portal, una por NIT.
     *
     * Solo del aliado 2: es el único con la automatización de portales activa.
     */
    private function empresas()
    {
        return RazonSocial::where('aliado_id', 2)
            ->whereNotNull('arl_poliza')->where('arl_poliza', '<>', '')
            ->when($this->option('nit'), fn ($q, $nit) => $q->where('nit', preg_replace('/\D/', '', $nit)))
            ->get(['id', 'nit', 'razon_social', 'arl_poliza', 'aliado_id'])
            ->unique(fn ($r) => preg_replace('/\D/', '', (string) $r->nit))
            ->filter(fn ($r) => (bool) ArlSuraSesionService::credencialPara((int) $r->aliado_id, (string) $r->arl_poliza, $r->nit))
            ->values();
    }
}
