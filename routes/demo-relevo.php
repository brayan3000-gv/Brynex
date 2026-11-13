<?php

use App\Http\Controllers\DemoRelevoController;
use Illuminate\Support\Facades\Route;

/*
| Lo que producción le atiende a la demo pública (ver App\Services\Demo\RelevoDemo).
| Lo carga DemoServiceProvider solo en producción y solo con DEMO_RELEVO_SECRETO.
|
| Las de la demo van sin sesión ni CSRF: las protege la firma HMAC.
*/

Route::prefix('demo-relevo')->middleware('throttle:120,1')->group(function () {
    Route::post('registro', [DemoRelevoController::class, 'registro']);
    Route::post('estado', [DemoRelevoController::class, 'estado']);
    Route::post('enviar', [DemoRelevoController::class, 'enviar']);
});

Route::middleware(['web', 'auth'])->get('admin/demo-prospectos', [DemoRelevoController::class, 'listado'])
    ->name('admin.demo-prospectos');
