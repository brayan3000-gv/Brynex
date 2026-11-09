<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El secreto del Authenticator (TOTP) de una clave de portal. Lo necesita el
 * gestor único de Comfandi: el portal obliga 2FA y la automatización genera el
 * código de 6 dígitos a partir de este secreto. Se guarda junto a la clave en
 * el módulo (misma pestaña de portales), no en el .env. Ver docs/comfandi-api.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clave_accesos', 'totp_secret')) {
            return;
        }

        Schema::table('clave_accesos', function (Blueprint $table) {
            $table->string('totp_secret', 120)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clave_accesos', 'totp_secret')) {
            return;
        }

        Schema::table('clave_accesos', function (Blueprint $table) {
            $table->dropColumn('totp_secret');
        });
    }
};
