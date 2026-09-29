<?php

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Models\Plano;
use App\Services\MiPlanilla\MiPlanillaPortalService;
use App\Services\PlanoPilaTxtService;
use Illuminate\Console\Command;

/**
 * Acciones del robot de Mi Planilla sobre la cuenta de un independiente,
 * mientras se mapea el portal. Cada acción entra, hace lo suyo, guarda lo que
 * responde el portal en storage/app/miplanilla/ y sale.
 *
 *   pdf      --numero=   baja el PDF de una planilla pagada
 *   generar  --periodo=  genera en línea la planilla I del período (sin validar)
 *   archivo  [--codigo=] sube el TXT de BryNex del último plano del contrato
 *   estado               guarda las planillas sin pagar y la edición de cada una
 *   eliminar --id=       borra la planilla sin pagar con ese id (no las pagadas)
 *   finalizar --id=      deja solo a --solo (por defecto la cédula del contrato) y finaliza la edición
 */
class MiPlanillaRobot extends Command
{
    protected $signature = 'miplanilla:robot
                            {contrato : id del contrato del independiente}
                            {accion : pdf, generar, archivo, estado, eliminar o finalizar}
                            {--id= : id (guid) de la planilla sin pagar (eliminar)}
                            {--solo= : cédulas a incluir, separadas por coma (generar; por defecto la del contrato)}
                            {--numero= : número de la planilla pagada (pdf)}
                            {--periodo= : período AAAA-MM (generar)}
                            {--codigo= : código PILA del operador para el TXT (archivo)}';

    protected $description = 'Robot de Mi Planilla: PDF de pagadas, generar en línea o subir el TXT (sin validar ni pagar)';

    public function handle(): int
    {
        $contrato = Contrato::find((int) $this->argument('contrato'));
        if (! $contrato) {
            $this->error('No existe ese contrato.');

            return self::FAILURE;
        }

        $accion = $this->argument('accion');
        $carpeta = storage_path("app/miplanilla/{$accion}/{$contrato->cedula}/".now()->format('Ymd_His'));
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        try {
            $robot = MiPlanillaPortalService::paraCedula((int) $contrato->aliado_id, (string) $contrato->cedula);
            $robot->login();

            try {
                match ($accion) {
                    'pdf' => $this->pdf($robot, $carpeta),
                    'generar' => $this->generar($robot, $carpeta, $contrato),
                    'archivo' => $this->archivo($robot, $carpeta, $contrato),
                    'estado' => $this->estado($robot, $carpeta),
                    'eliminar' => $this->eliminar($robot, $carpeta),
                    'finalizar' => $this->finalizar($robot, $carpeta, $contrato, (string) $this->option('id')),
                    default => throw new \RuntimeException('Acción desconocida: use pdf, generar, archivo, estado, eliminar o finalizar.'),
                };
            } finally {
                $robot->logout();
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Listo. Lo que respondió el portal quedó en {$carpeta}");

        return self::SUCCESS;
    }

    private function pdf(MiPlanillaPortalService $robot, string $carpeta): void
    {
        $numero = (string) $this->option('numero');
        if ($numero === '') {
            throw new \RuntimeException('Falta --numero=');
        }

        $pdf = $robot->pdfPagada($numero);
        file_put_contents("{$carpeta}/{$numero}.pdf", $pdf);
        $this->line("PDF de la planilla {$numero}: ".number_format(strlen($pdf) / 1024, 1).' KB');
    }

    private function generar(MiPlanillaPortalService $robot, string $carpeta, Contrato $contrato): void
    {
        $periodo = (string) $this->option('periodo');
        if (! preg_match('~^\d{4}-\d{2}$~', $periodo)) {
            throw new \RuntimeException('Falta --periodo=AAAA-MM');
        }

        // Lo que ya hay de ese período: se avisa, porque generar otra la duplica.
        $planillas = $robot->planillas();
        file_put_contents("{$carpeta}/planillas_antes.json", json_encode($planillas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach (array_merge($planillas['disponibles'], $planillas['pagadas']) as $p) {
            if (MiPlanillaPortalService::fecha($p['PeriodoPension'] ?? null)?->format('Y-m') === $periodo) {
                $this->warn("Ojo: ya existe la planilla {$p['NumeroRadicado']} de {$periodo} por \$".number_format((int) ($p['TotalPagar'] ?? 0), 0, ',', '.').'.');
            }
        }

        $solo = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('solo') ?: $contrato->cedula)))));
        $r = $robot->generarEnLinea($periodo.'-01', $solo);
        file_put_contents("{$carpeta}/respuesta_generar.html", $r['html']);
        file_put_contents("{$carpeta}/url.txt", $r['url']);
        file_put_contents("{$carpeta}/administrar_despues.html", $robot->get('/PrivadoIndependientes/Planilla/AdministrarPlanillas'));
        file_put_contents("{$carpeta}/planillas_despues.json", json_encode($robot->planillas(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("Generada con {$r['cotizantes']} persona(s) (".implode(', ', $solo)."). El portal quedó en: {$r['url']}");
    }

    private function estado(MiPlanillaPortalService $robot, string $carpeta): void
    {
        $r = $robot->sinPagar();
        file_put_contents("{$carpeta}/en_generacion.html", $r['generacion']);
        file_put_contents("{$carpeta}/pendientes_pago.html", $r['pendientes']);
        file_put_contents("{$carpeta}/items.json", json_encode($r['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Los que terminaron con error de validación traen su enlace "Ver Errores".
        preg_match_all('~href="(/PrivadoIndependientes/Planilla/LogErrores\?[^"]+)"~', $r['generacion'].$r['pendientes'], $logs);
        foreach (array_unique($logs[1]) as $i => $url) {
            file_put_contents("{$carpeta}/errores_{$i}.html", $robot->get(html_entity_decode($url)));
            $this->warn('Tiene errores de validación: guardados en errores_'.$i.'.html');
        }

        $vistos = [];
        foreach ($r['items'] as $item) {
            $id = $item['planillaactiva'] ?? $item['id'] ?? null;
            // El portal lee la fecha MM/dd/yyyy (la lista la da dd/MM).
            $fecha = preg_replace('~^(\d{2})/(\d{2})/(\d{4})~', '$2/$1/$3', $item['fechaplanilla'] ?? $item['date'] ?? '');
            if (! $id || ! $fecha || isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;
            $editar = $robot->editarPlanilla($id, $fecha);
            file_put_contents("{$carpeta}/editar_{$id}.html", $editar);
            $this->line("Planilla sin pagar {$id} ({$item['origen']}), fecha {$fecha}");

            // La ventana de "Borrar" de cada persona (solo se abre, no se confirma).
            preg_match_all("~ModalBorrarEmpleadoPlanilla\('([^']+)', '([^']+)', '([^']+)', '([^']+)'\)~", $editar, $personas, PREG_SET_ORDER);
            foreach ($personas as [, $idP, $fechaP, $tipoDoc, $doc]) {
                file_put_contents("{$carpeta}/popup_quitar_{$doc}.html", (string) $robot->get('/PrivadoIndependientes/Planilla/PopupBorrarEmpleadoPlanillaEditarPlanilla?'.http_build_query([
                    'idPlanilla' => $idP, 'fechaPlanilla' => $fechaP, 'tipoDocumento' => $tipoDoc, 'numeroDocumento' => $doc,
                ])));
                $this->line("  persona {$tipoDoc} {$doc}: ventana de quitar guardada");
            }
        }

        if (! $vistos) {
            $this->line('No hay planillas sin pagar con datos para editar (ver en_generacion.html y pendientes_pago.html).');
        }
    }

    /**
     * Deja la planilla sin pagar solo con las personas del contrato, finaliza la
     * edición, espera la validación y muestra cómo quedó.
     */
    private function finalizar(MiPlanillaPortalService $robot, string $carpeta, Contrato $contrato, string $id): void
    {
        if ($id === '') {
            throw new \RuntimeException('Falta --id= (el guid de la planilla sin pagar).');
        }

        $item = collect($robot->sinPagar()['items'])->first(fn ($i) => ($i['planillaactiva'] ?? null) === $id && ! empty($i['fechaplanilla']));
        if (! $item) {
            throw new \RuntimeException("La planilla {$id} no está entre las sin pagar de esta cuenta.");
        }

        $fecha = preg_replace('~^(\d{2})/(\d{2})/(\d{4})~', '$2/$1/$3', $item['fechaplanilla']);
        $solo = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('solo') ?: $contrato->cedula)))));

        $r = $robot->depurarYFinalizar($id, $fecha, $solo);
        file_put_contents("{$carpeta}/respuesta_finalizar.html", $r['html']);
        $this->line('Quitadas: '.($r['quitadas'] ? implode(', ', $r['quitadas']) : 'nadie').'. Finalizada la edición; esperando la validación…');

        $estado = $robot->esperarValidacion($id);
        $this->line("Estado en el portal: {$estado}");
        $this->reportar($robot, $carpeta);
    }

    /** Guarda las listas y muestra lo que quedó disponible para pago (número y valor) o los errores. */
    private function reportar(MiPlanillaPortalService $robot, string $carpeta): void
    {
        $r = $robot->sinPagar();
        file_put_contents("{$carpeta}/en_generacion.html", $r['generacion']);
        file_put_contents("{$carpeta}/pendientes_pago.html", $r['pendientes']);

        preg_match_all('~href="(/PrivadoIndependientes/Planilla/LogErrores\?[^"]+)"~', $r['generacion'], $logs);
        foreach (array_unique($logs[1]) as $i => $url) {
            $html = $robot->get(html_entity_decode($url));
            file_put_contents("{$carpeta}/errores_{$i}.html", $html);
            // "Descargar archivo de errores": el CSV del validador, a veces con más detalle.
            if (preg_match('~href="(/PrivadoIndependientes/Planilla/VerLog\?[^"]+)"~', $html, $csv)) {
                file_put_contents("{$carpeta}/errores_{$i}.csv", $robot->get(html_entity_decode($csv[1])));
            }
            $texto = trim(preg_replace('~\s+~', ' ', strip_tags(preg_replace('~<script.*?</script>~is', '', $html))));
            if (preg_match('~Detalle(.*?)Planilla anterior~u', $texto, $m)) {
                $this->warn('Errores: '.mb_substr(trim($m[1]), 0, 400));
            }
        }

        $disponibles = $robot->planillas()['disponibles'];
        file_put_contents("{$carpeta}/disponibles.json", json_encode($disponibles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach ($disponibles as $p) {
            $periodo = MiPlanillaPortalService::fecha($p['PeriodoPension'] ?? null)?->format('Y-m');
            $this->info("Disponible para pago: planilla {$p['NumeroRadicado']}, período {$periodo}, \$".number_format((int) ($p['TotalPagar'] ?? 0), 0, ',', '.').", id {$p['IdPlanilla']}");
        }
    }

    private function eliminar(MiPlanillaPortalService $robot, string $carpeta): void
    {
        $id = (string) $this->option('id');
        if ($id === '') {
            throw new \RuntimeException('Falta --id= (el guid de la planilla sin pagar).');
        }

        // Solo se borra lo que aparece sin pagar: nunca una pagada. De cada
        // planilla la lista trae el enlace de errores (solo el id) y el botón
        // Eliminar (id, fecha e idLog): se usa el del botón.
        $item = collect($robot->sinPagar()['items'])->first(fn ($i) => ($i['planillaactiva'] ?? null) === $id && ($i['accion'] ?? '') === 'DEL');
        if (! $item) {
            throw new \RuntimeException("La planilla {$id} no está entre las sin pagar de esta cuenta.");
        }

        // La lista da la fecha como dd/MM/yyyy, pero el servidor del portal la lee
        // MM/dd/yyyy (así la usa en el enlace de errores): con dd/MM responde
        // "An error occurred" y no borra (probado el 29-sep-2026).
        $fecha = preg_replace('~^(\d{2})/(\d{2})/(\d{4})~', '$2/$1/$3', $item['fechaplanilla'] ?? '');
        $r = $robot->borrarPlanilla($id, $fecha, $item['idlog'] ?? '');
        file_put_contents("{$carpeta}/popup_borrar.html", $r['popup']);
        file_put_contents("{$carpeta}/respuesta_borrar.txt", $r['respuesta']);

        $sigue = collect($robot->sinPagar()['items'])->contains(fn ($i) => ($i['planillaactiva'] ?? $i['id'] ?? null) === $id);
        $sigue
            ? $this->warn("La planilla {$id} sigue en el portal: el borrado no se aplicó (ver respuesta_borrar.txt).")
            : $this->info("Planilla {$id} eliminada.");
    }

    private function archivo(MiPlanillaPortalService $robot, string $carpeta, Contrato $contrato): void
    {
        $plano = Plano::where('contrato_id', $contrato->id)
            ->whereIn('tipo_reg', ['planilla', 'retiro'])
            ->where('num_dias', '>', 0)
            ->orderByDesc('anio_plano')->orderByDesc('mes_plano')
            ->firstOrFail();

        // El TXT se pide por mes de PAGO: el vencido se paga el mes siguiente.
        $pago = \Carbon\Carbon::create((int) $plano->anio_plano, (int) $plano->mes_plano, 1);
        if (! $plano->paga_mes_actual) {
            $pago->addMonth();
        }

        $txt = (new PlanoPilaTxtService)->construir([
            'aliado_id' => (int) $contrato->aliado_id,
            'razon_social_id' => (int) $plano->razon_social_id,
            'mes' => $pago->month,
            'anio' => $pago->year,
            'n_plano' => (int) $plano->n_plano,
            'plano_id' => (int) $plano->id,
            'codigo_operador' => (string) ($this->option('codigo') ?: '88'),
        ]);

        file_put_contents("{$carpeta}/{$txt['filename']}", $txt['contenido']);
        $r = $robot->subirArchivo($txt['contenido'], $txt['filename']);
        file_put_contents("{$carpeta}/respuesta_archivo.html", $r['html']);
        file_put_contents("{$carpeta}/url.txt", $r['url']);
        file_put_contents("{$carpeta}/administrar_despues.html", $robot->get('/PrivadoIndependientes/Planilla/AdministrarPlanillas'));
        $this->line("Subido {$txt['filename']} (plano {$plano->id}, período {$plano->mes_plano}/{$plano->anio_plano}). El portal quedó en: {$r['url']}");
    }
}
