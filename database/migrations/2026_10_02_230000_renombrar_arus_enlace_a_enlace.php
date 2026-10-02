<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El operador «ARUS Enlace» hoy es solo Enlace: ARUS lo manejaba antes.
 *
 * Cambia lo que se ve (nombre del operador, de su plantilla y las claves
 * guardadas como «ARUS», y el nombre en los pagos de planilla y envíos por
 * WhatsApp, que se cruzan por nombre). El `codigo` sigue siendo ARUS a propósito: de él
 * cuelgan el host del API (SuaporteApiService::HOSTS), las credenciales, las
 * consultas RUAF y los operadores autorizados para WhatsApp. El historial
 * de texto libre (observaciones, descripciones, mensajes) no se reescribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('operadores_planilla')->where('codigo', 'ARUS')->where('nombre', 'ARUS Enlace')
            ->update(['nombre' => 'Enlace']);

        DB::table('operador_planillas_templates')->where('nombre', 'Plantilla ARUS Enlace')
            ->update(['nombre' => 'Plantilla Enlace']);

        // Sin tocar updated_at: decide qué clave usan los robots cuando hay varias.
        DB::table('clave_accesos')->whereIn('entidad', ['ARUS', 'ARUS Enlace', 'ARUS ENLACE'])
            ->update(['entidad' => 'Enlace']);

        // El pago de la planilla se cruza con su operador por el nombre exacto
        // (FacturacionController::historial, PlanoPagoController): con el nombre
        // viejo, las planillas ya pagadas dejarían de reconocer a Enlace y
        // perderían el soporte real. Solo el nombre; montos y fechas igual.
        DB::table('gastos')->where('tipo', 'pago_planilla')->where('pagado_a', 'ARUS Enlace')
            ->update(['pagado_a' => 'Enlace']);

        DB::table('planilla_envios_whatsapp_detalle')->where('operador_nombre', 'ARUS Enlace')
            ->update(['operador_nombre' => 'Enlace']);
    }

    public function down(): void
    {
        DB::table('operadores_planilla')->where('codigo', 'ARUS')->where('nombre', 'Enlace')
            ->update(['nombre' => 'ARUS Enlace']);

        DB::table('operador_planillas_templates')->where('nombre', 'Plantilla Enlace')
            ->update(['nombre' => 'Plantilla ARUS Enlace']);

        DB::table('gastos')->where('tipo', 'pago_planilla')->where('pagado_a', 'Enlace')
            ->update(['pagado_a' => 'ARUS Enlace']);

        DB::table('planilla_envios_whatsapp_detalle')->where('operador_nombre', 'Enlace')
            ->update(['operador_nombre' => 'ARUS Enlace']);
    }
};
