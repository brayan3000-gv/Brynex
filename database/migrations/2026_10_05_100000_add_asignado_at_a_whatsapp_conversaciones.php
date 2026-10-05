<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desde cuándo la conversación está asignada al asesor actual.
 *
 * La usa `whatsapp:liberar-sin-atender`: una conversación asignada con el cliente
 * esperando vuelve al inbox general a las 4 h. Sin esta fecha, a quien le asignaran
 * hace diez minutos una conversación que ya llevaba esperando se la quitarían de
 * inmediato, sin haber tenido tiempo de contestar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->dateTime('asignado_at')->nullable()->after('asignado_a');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->dropColumn('asignado_at');
        });
    }
};
