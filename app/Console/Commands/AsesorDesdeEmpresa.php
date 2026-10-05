<?php

namespace App\Console\Commands;

use App\Models\Asesor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Convierte en asesor a alguien que estaba creado como empresa: crea el asesor
 * (o usa el que ya exista con esa cédula), le enlaza la empresa como grupo de
 * cobro y le asigna los contratos de los clientes de esa empresa.
 *
 * Solo toma contratos sin asesor o del asesor «oficina» que se indique, y deja
 * por fuera los que ya tienen comisión de asesor en alguna factura, para no
 * pasar plata de una liquidación a otra. Sin --aplicar solo muestra lo que haría.
 *
 *   php artisan brynex:asesor-desde-empresa 185 --cedula=16742102 --tarifa-neta=37000 --oficina=143
 *   php artisan brynex:asesor-desde-empresa 185 --cedula=16742102 --tarifa-neta=37000 --oficina=143 --aplicar
 */
class AsesorDesdeEmpresa extends Command
{
    protected $signature = 'brynex:asesor-desde-empresa
        {empresa : Id de la empresa que en realidad es un asesor}
        {--cedula= : Cédula del asesor}
        {--nombre= : Nombre del asesor (por defecto, el de la empresa)}
        {--tarifa-neta= : Si se indica, queda con tipo de cobro «neta» y este valor por persona}
        {--oficina= : Id del asesor «oficina» cuyos contratos también se reasignan}
        {--fecha-ingreso= : Fecha de ingreso del asesor (por defecto, la del contrato más antiguo que se le enlaza)}
        {--aplicar : Hace los cambios. Sin esto solo muestra lo que haría}';

    protected $description = 'Crea un asesor a partir de una empresa y le enlaza sus contratos';

    public function handle(): int
    {
        $empresa = DB::table('empresas')->where('id', (int) $this->argument('empresa'))->first();
        if (! $empresa) {
            $this->error('No existe esa empresa.');

            return self::FAILURE;
        }
        $cedula = trim((string) $this->option('cedula'));
        if ($cedula === '') {
            $this->error('Falta --cedula.');

            return self::FAILURE;
        }
        $aliadoId = (int) $empresa->aliado_id;
        $oficina = $this->option('oficina') !== null ? (int) $this->option('oficina') : null;
        $nombre = trim((string) ($this->option('nombre') ?: $empresa->empresa));

        $contratos = DB::table('contratos as c')
            ->join('clientes as cl', fn ($j) => $j->on('cl.cedula', '=', 'c.cedula')->on('cl.aliado_id', '=', 'c.aliado_id'))
            ->where('cl.cod_empresa', $empresa->id)
            ->where('c.aliado_id', $aliadoId)
            ->where(function ($q) use ($oficina) {
                $q->whereNull('c.asesor_id');
                if ($oficina) {
                    $q->orWhere('c.asesor_id', $oficina);
                }
            })
            ->select('c.id', 'c.asesor_id', 'c.estado', 'c.fecha_ingreso')
            ->get();

        // Los que ya comisionaron a otro asesor se quedan como están.
        $conComision = DB::table('facturas')
            ->whereIn('contrato_id', $contratos->pluck('id'))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('admin_asesor', '>', 0)->orWhere('dist_asesor', '>', 0))
            ->distinct()
            ->pluck('contrato_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $enlazar = $contratos->reject(fn ($c) => in_array((int) $c->id, $conComision, true))->values();

        $existente = Asesor::withTrashed()->where('aliado_id', $aliadoId)->where('cedula', $cedula)->first();
        $fechaIngreso = $this->option('fecha-ingreso') ?: substr((string) $enlazar->min('fecha_ingreso'), 0, 10) ?: null;

        $this->line("Empresa #{$empresa->id} «{$empresa->empresa}» (aliado {$aliadoId})");
        $this->line($existente ? "Asesor: ya existe #{$existente->id} {$existente->nombre}" : "Asesor: se crea «{$nombre}», cédula {$cedula}");
        if ($this->option('tarifa-neta') !== null) {
            $this->line('Tipo de cobro: neta, $'.number_format((float) $this->option('tarifa-neta'), 0, ',', '.').' por persona');
        }
        $this->line('Fecha de ingreso: '.($fechaIngreso ?: 'sin definir'));
        $this->line('Contratos a enlazar: '.$enlazar->count().' ('.$enlazar->where('estado', 'vigente')->count().' vigentes)');
        if ($conComision) {
            $this->warn('Se dejan como están por tener comisión de otro asesor: #'.implode(', #', $conComision));
        }

        if (! $this->option('aplicar')) {
            $this->info('Modo de prueba: no se cambió nada. Agregue --aplicar para hacerlo.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($existente, $empresa, $aliadoId, $cedula, $nombre, $fechaIngreso, $enlazar) {
            $asesor = $existente ?: Asesor::create([
                'aliado_id' => $aliadoId,
                'cedula' => $cedula,
                'nombre' => $nombre,
                'telefono' => $empresa->telefono,
                'celular' => $empresa->celular,
                'comision_afil_tipo' => 'fijo', 'comision_afil_valor' => 0,
                'comision_admon_tipo' => 'fijo', 'comision_admon_valor' => 0,
                'fecha_ingreso' => $fechaIngreso,
                'activo' => true,
            ]);
            if ($this->option('tarifa-neta') !== null) {
                $asesor->update(['tipo_cobro' => 'neta', 'tarifa_neta' => (float) $this->option('tarifa-neta')]);
            }

            // Respaldo de cómo estaba, por si hay que devolverlo.
            $ruta = storage_path('app/respaldos');
            if (! is_dir($ruta)) {
                mkdir($ruta, 0775, true);
            }
            $archivo = "$ruta/asesor_desde_empresa_{$empresa->id}_".now()->format('Ymd_His').'.json';
            file_put_contents($archivo, json_encode([
                'fecha' => now()->toDateTimeString(),
                'asesor_id' => $asesor->id,
                'asesor_creado' => ! $existente,
                'empresa' => ['id' => (int) $empresa->id, 'asesor_id_antes' => $empresa->asesor_id],
                'contratos' => $enlazar->map(fn ($c) => ['id' => (int) $c->id, 'asesor_id_antes' => $c->asesor_id === null ? null : (int) $c->asesor_id])->all(),
            ], JSON_PRETTY_PRINT));

            $n = DB::table('contratos')->where('aliado_id', $aliadoId)->whereIn('id', $enlazar->pluck('id'))
                ->update(['asesor_id' => $asesor->id, 'updated_at' => now()]);
            DB::table('empresas')->where('id', $empresa->id)->where('aliado_id', $aliadoId)
                ->update(['asesor_id' => $asesor->id, 'updated_at' => now()]);

            $this->info("Listo: asesor #{$asesor->id}, {$n} contratos enlazados y la empresa quedó como su grupo de cobro.");
            $this->line("Respaldo: $archivo");
        });

        return self::SUCCESS;
    }
}
