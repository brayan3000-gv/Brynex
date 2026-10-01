<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Formulario de S.O.S.: lo que faltaba marcar (decisión del dueño, 1-oct-2026).
 *
 * - Novedad 9 «Inicio de relación laboral». S.O.S. siempre va como reporte de
 *   novedades —la persona ya venía en S.O.S. y entra a contributivo—, y la X de
 *   «B. Reporte de novedad» ya era fija; faltaba decir cuál novedad.
 * - Sección X «Anexos»: la casilla 82, cuántos documentos de identidad van de
 *   cada tipo y el total. Los llena SosCorreoService con lo que se adjunta.
 * - El correo del cotizante en la carta de derechos (página 3): marca «SI» a
 *   recibirla por correo y la línea del correo quedaba vacía.
 *
 * Coordenadas medidas sobre la plantilla de producción (S_O_S_.pdf).
 */
return new class extends Migration
{
    private const CODIGO = 'EPS018';

    /** Cajitas de la casilla 82, de izquierda a derecha: borde izquierdo de cada una. */
    private const TIPOS = ['cn' => 167.75, 'rc' => 184, 'ti' => 200.25, 'cc' => 216.5, 'pa' => 232.25, 'ce' => 249, 'cd' => 267, 'sc' => 283.75, 'pt' => 301];

    public function campos(): array
    {
        $marca = ['width' => 8.5, 'height' => 6.5, 'font_size' => 8, 'style' => '', 'align' => 'C', 'tipo' => 'texto'];

        $campos = [
            ['dato' => 'static.X_13', 'pagina' => 2, 'x' => 23.75, 'y' => 173.75] + $marca,
            ['dato' => 'custom.anexo_82_x', 'pagina' => 2, 'x' => 24, 'y' => 515.5] + $marca,
        ];
        foreach (self::TIPOS as $tipo => $x) {
            $campos[] = ['dato' => "custom.anexo_{$tipo}", 'pagina' => 2, 'x' => $x, 'y' => 522.2, 'width' => 10.25, 'height' => 7.4, 'font_size' => 7, 'style' => '', 'align' => 'C', 'tipo' => 'texto'];
        }
        $campos[] = ['dato' => 'custom.total_anexos', 'pagina' => 2, 'x' => 446, 'y' => 691.5, 'width' => 29, 'height' => 13, 'font_size' => 9, 'style' => '', 'align' => 'C', 'tipo' => 'texto'];
        $campos[] = ['dato' => 'cliente.correo__1', 'pagina' => 3, 'x' => 118, 'y' => 140.3, 'width' => 395, 'height' => 12, 'font_size' => 9, 'style' => '', 'align' => 'L', 'tipo' => 'texto'];

        return $campos;
    }

    public function up(): void
    {
        $eps = DB::table('eps')->where('codigo', self::CODIGO)->first(['id', 'formulario_campos']);
        $campos = json_decode((string) $eps?->formulario_campos, true);
        if (! is_array($campos) || ! $campos) {
            return;
        }

        $datos = array_column($campos, 'dato');
        foreach ($this->campos() as $nuevo) {
            if (! in_array($nuevo['dato'], $datos, true)) {
                $campos[] = $nuevo;
            }
        }

        DB::table('eps')->where('id', $eps->id)->update(['formulario_campos' => json_encode($campos, JSON_UNESCAPED_UNICODE)]);
    }

    public function down(): void
    {
        $eps = DB::table('eps')->where('codigo', self::CODIGO)->first(['id', 'formulario_campos']);
        $campos = json_decode((string) $eps?->formulario_campos, true);
        if (! is_array($campos)) {
            return;
        }

        $quitar = array_column($this->campos(), 'dato');
        $campos = array_values(array_filter($campos, fn ($c) => ! in_array($c['dato'] ?? '', $quitar, true)));

        DB::table('eps')->where('id', $eps->id)->update(['formulario_campos' => json_encode($campos, JSON_UNESCAPED_UNICODE)]);
    }
};
