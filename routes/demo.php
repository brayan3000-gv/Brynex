<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

/*
| Rutas que solo existen en la demo (demo.brynex.co). Las carga
| App\Providers\DemoServiceProvider cuando BRYNEX_DEMO=true; en producción
| este archivo no se lee.
*/

// La entrada: nombre, empresa y celular, y adentro (ver DemoController::registro).
Route::middleware(['guest', 'throttle:10,1'])->post('demo/registro', [DemoController::class, 'registro'])->name('demo.registro');

Route::middleware('auth')->group(function () {
    Route::get('demo/whatsapp/estado', [DemoController::class, 'whatsappEstado'])->middleware('throttle:60,1')->name('demo.whatsapp.estado');
    Route::post('demo/actividad', [DemoController::class, 'actividad'])->middleware('throttle:30,1')->name('demo.actividad');

    Route::prefix('demo/radicar')->name('demo.radicar.')
        ->middleware(['permiso:radicados.ver', 'permiso.escritura:radicados.gestionar'])
        ->group(function () {
            Route::get('{id}/precheck', [DemoController::class, 'precheck'])->whereNumber('id')->name('precheck');
            Route::post('{id}', [DemoController::class, 'radicar'])->whereNumber('id')->name('ejecutar');
            Route::get('{id}/pdf', [DemoController::class, 'pdf'])->whereNumber('id')->name('pdf');
            Route::post('{id}/anular', [DemoController::class, 'anular'])->whereNumber('id')->name('anular');
        });

    Route::get('demo/pago-pse/{numero}', [DemoController::class, 'pagoPse'])->whereNumber('numero')->name('demo.pago-pse');
    Route::post('demo/pago-pse/{numero}', [DemoController::class, 'aprobarPse'])->whereNumber('numero')->name('demo.pago-pse.aprobar');
});
