<?php

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Services\ArlColmena\ColmenaAfiliacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Opera ARL Colmena por consola: consultar, afiliar, anular y retirar.
 *
 * Existe para poder trabajar y depurar sin pantalla —la afiliación desde la
 * ficha del contrato va detrás del Gate `automatizar-arl`— y porque las
 * pruebas contra Colmena son reales: no hay ambiente de pruebas, así que todo
 * lo que escribe exige `--ejecutar` y sin él solo muestra lo que enviaría.
 */
class ArlColmena extends Command
{
    protected $signature = 'arl:colmena
        {accion : sesion|centros|consultar|certificado|afiliar|anular|retirar}
        {--contrato= : Id del contrato en BryNex}
        {--nit= : NIT de la empresa ante Colmena, si no es la de la razón social del contrato}
        {--fecha= : Inicio de vigencia (afiliar) o fecha de retiro, AAAA-MM-DD}
        {--arl-anterior= : Id de la ARL anterior en el catálogo de Colmena}
        {--ejecutar : Envía de verdad; sin esto no escribe nada en Colmena}';

    protected $description = 'Consulta y radica novedades en el portal de ARL Colmena';

    public function handle(): int
    {
        try {
            return match ($this->argument('accion')) {
                'sesion' => $this->sesion(),
                'centros' => $this->centros(),
                'consultar' => $this->consultar(),
                'certificado' => $this->certificado(),
                'afiliar' => $this->afiliar(),
                'anular' => $this->anular(),
                'retirar' => $this->retirar(),
                default => $this->fallar('Acción desconocida.'),
            };
        } catch (Throwable $e) {
            return $this->fallar($e->getMessage());
        }
    }

    // ─── Acciones ────────────────────────────────────────────────────

    private function sesion(): int
    {
        $api = $this->apiPorNit();

        $this->info('Contrato en Colmena: '.$api->contrato());
        $this->line('SMLV que maneja Colmena: '.number_format($api->smlv(), 0, ',', '.'));

        return self::SUCCESS;
    }

    private function certificado(): int
    {
        $documento = $this->servicio()->archivarCertificado($this->contrato());

        $this->info('Certificado guardado: '.$documento->ruta);

        return self::SUCCESS;
    }

    private function centros(): int
    {
        $this->table(
            ['consecutivo', 'centro', 'riesgo', 'tasa', 'grado'],
            array_map(fn ($c) => [
                $c['consecutive'] ?? '', trim($c['name'] ?? ''), $c['riskClass'] ?? '', $c['riskRate'] ?? '', $c['grade'] ?? '',
            ], $this->apiPorNit()->centrosDeTrabajo())
        );

        return self::SUCCESS;
    }

    private function consultar(): int
    {
        $contrato = $this->contrato();
        $servicio = $this->servicio();
        $cobertura = $servicio->coberturaEnColmena($contrato);

        if (! $cobertura) {
            $this->warn("{$contrato->cedula} no está en el contrato de Colmena de esta empresa.");

            return self::SUCCESS;
        }

        $this->info(($cobertura['firstname'] ?? '').' '.($cobertura['firstSurname'] ?? '').' · '.$contrato->cedula);
        $this->line('  Ingreso   : '.substr((string) ($cobertura['admissionDate'] ?? ''), 0, 10));
        $this->line('  Vigencia  : '.substr((string) ($cobertura['initEffectiveDate'] ?? ''), 0, 10).
            ' → '.substr((string) ($cobertura['endEffectiveDate'] ?? ''), 0, 10).
            ($servicio->estaVigente($cobertura) ? '  (VIGENTE)' : '  (retirado)'));
        $this->line('  Centro    : '.($cobertura['headquarterId'] ?? '').'  ·  trabajador '.($cobertura['workerId'] ?? ''));
        $this->line('  Salario   : '.($cobertura['basicSalary'] ?? '').'  ·  EPS '.($cobertura['eps']['name'] ?? '').
            '  ·  AFP '.($cobertura['afp']['name'] ?? ''));

        return self::SUCCESS;
    }

    private function afiliar(): int
    {
        $contrato = $this->contrato();
        $servicio = $this->servicio();
        $inicio = $this->fecha() ?: now()->addDay();

        if ($problemas = $servicio->builder()->problemas($contrato)) {
            $this->error('Al contrato le falta:');
            foreach ($problemas as $p) {
                $this->line('  · '.$p);
            }

            return self::FAILURE;
        }

        $payload = $servicio->builder()->paraAfiliacion($contrato, $inicio, $this->option('arl-anterior'));

        $this->info('Se enviaría a POST /workers/dependent:');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (! $this->option('ejecutar')) {
            $this->warn('Simulación: nada se radicó. Repite con --ejecutar.');

            return self::SUCCESS;
        }

        $registro = $servicio->afiliar($contrato, $inicio, $this->usuario(), $this->option('arl-anterior'));

        $this->info('Radicado en Colmena: '.$registro->codigo_transaccion.
            ' · vigencia desde '.$registro->fecha_inicio_cobertura->format('d/m/Y'));

        return self::SUCCESS;
    }

    private function anular(): int
    {
        $contrato = $this->contrato();

        if (! $this->option('ejecutar')) {
            $this->warn('Simulación: se anularía el ingreso de '.$contrato->cedula.'. Repite con --ejecutar.');

            return self::SUCCESS;
        }

        $registro = $this->servicio()->anular($contrato, $this->usuario());

        $this->info('Ingreso anulado'.($registro->codigo_transaccion ? ' · radicación '.$registro->codigo_transaccion : '').'.');

        return self::SUCCESS;
    }

    private function retirar(): int
    {
        $contrato = $this->contrato();
        $fecha = $this->fecha() ?: now();

        if (! $this->option('ejecutar')) {
            $this->warn('Simulación: se retiraría a '.$contrato->cedula.' el '.$fecha->format('d/m/Y').'. Repite con --ejecutar.');

            return self::SUCCESS;
        }

        $registro = $this->servicio()->retirar($contrato, $fecha, $this->usuario());

        $this->info('Retiro radicado'.($registro->codigo_transaccion ? ' · radicación '.$registro->codigo_transaccion : '').'.');

        return self::SUCCESS;
    }

    // ─── Apoyo ───────────────────────────────────────────────────────

    private function apiPorNit(): \App\Services\ArlColmena\ColmenaApiService
    {
        if ($nit = $this->option('nit')) {
            return new \App\Services\ArlColmena\ColmenaApiService((string) $nit);
        }

        return $this->servicio()->api();
    }

    private function servicio(): ColmenaAfiliacionService
    {
        return ColmenaAfiliacionService::paraContrato($this->contrato(), $this->option('nit'));
    }

    private function contrato(): Contrato
    {
        $id = (int) $this->option('contrato')
            ?: throw new \RuntimeException('Falta --contrato.');

        return Contrato::with(['cliente', 'razonSocial', 'eps', 'pension', 'aliado'])
            ->findOrFail($id);
    }

    private function fecha(): ?Carbon
    {
        return $this->option('fecha') ? Carbon::parse($this->option('fecha'))->startOfDay() : null;
    }

    /** Los cambios por consola se atribuyen al usuario 2, como el resto. */
    private function usuario(): int
    {
        return 2;
    }

    private function fallar(string $mensaje): int
    {
        $this->error($mensaje);

        return self::FAILURE;
    }
}
