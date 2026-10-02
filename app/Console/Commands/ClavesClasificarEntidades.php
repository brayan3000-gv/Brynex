<?php

namespace App\Console\Commands;

use App\Models\ClaveAcceso;
use App\Services\Afiliaciones\PortalesEntidades;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Liga las claves de empresa que ya existían a su entidad del catálogo.
 *
 * Antes la entidad era texto libre («SURA», «Sura ARL», «EPS», «Caja»…). Lo
 * que se puede deducir del nombre, o de la ARL/caja configurada en la empresa
 * cuando el texto es genérico, se clasifica; lo demás queda en «Sin
 * clasificar» en la pestaña de portales para asignarlo a mano.
 *
 * También pasa a `asesor_correo` el correo de la entidad cuando es de un
 * dominio de la entidad (p. ej. jtorres.qta@sos.com.co): hoy ese campo mezcla
 * el del asesor con el de la empresa.
 *
 * Escribe con DB::table y sin tocar `updated_at`, porque los robots eligen la
 * clave más reciente cuando hay varias: clasificar no debe cambiar cuál usan.
 */
class ClavesClasificarEntidades extends Command
{
    protected $signature = 'claves:clasificar-entidades {--aplicar : Guardar (sin esto solo muestra)}';

    protected $description = 'Liga las claves de empresa a su EPS, ARL o caja del catálogo';

    public function handle(): int
    {
        $claves = ClaveAcceso::with('razonSocial')
            ->whereNotNull('razon_social_id')
            ->whereNull('entidad_tipo')
            ->get();

        $dominios = array_keys(config('afiliaciones_correo.entidades', []));
        $filas = [];
        $cuenta = ['clasificadas' => 0, 'sin_clasificar' => 0, 'asesor' => 0];

        foreach ($claves as $c) {
            [$tipo, $id, $como] = PortalesEntidades::clasificar($c, $c->razonSocial);

            $asesor = null;
            $correo = strtolower(trim((string) $c->correo_entidad));
            if ($tipo && $tipo !== 'OTRO' && ! $c->asesor_correo && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $dominio = substr(strrchr($correo, '@'), 1);
                foreach ($dominios as $d) {
                    // epscomfenalcovalle.com.co también es de comfenalcovalle.com.co
                    if (str_ends_with($dominio, $d)) {
                        $asesor = $correo;
                        break;
                    }
                }
            }

            $tipo ? $cuenta['clasificadas']++ : $cuenta['sin_clasificar']++;
            $asesor && $cuenta['asesor']++;

            $filas[] = [
                $c->id,
                $c->razonSocial?->aliado_id,
                mb_substr((string) $c->razonSocial?->razon_social, 0, 28),
                $c->tipo,
                $c->entidad,
                $tipo ? $tipo.' '.($id ? PortalesEntidades::nombreEntidad($tipo, $id) : '') : '—',
                $como,
                $asesor ?? '',
            ];

            if ($this->option('aplicar') && ($tipo || $asesor)) {
                DB::table('clave_accesos')->where('id', $c->id)->update(array_filter([
                    'entidad_tipo' => $tipo,
                    'entidad_id' => $id,
                    'asesor_correo' => $asesor,
                ], fn ($v) => $v !== null));
            }
        }

        usort($filas, fn ($a, $b) => [$a[5] === '—', $a[3], $a[4]] <=> [$b[5] === '—', $b[3], $b[4]]);
        $this->table(['id', 'aliado', 'razón social', 'tipo', 'entidad', '→ catálogo', 'cómo', 'asesor'], $filas);

        $this->info("Clasificadas: {$cuenta['clasificadas']} · sin clasificar: {$cuenta['sin_clasificar']} · correo del asesor: {$cuenta['asesor']}");
        $this->line($this->option('aplicar') ? 'Guardado.' : 'Solo vista previa: --aplicar para guardar.');

        return self::SUCCESS;
    }
}
