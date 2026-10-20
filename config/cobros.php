<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Afiliaciones que nunca se cobraron
    |--------------------------------------------------------------------------
    |
    | Cobros mira un mes a la vez: la afiliación solo se pide en el mes de
    | ingreso. Si ese mes no se pagó, al mes siguiente la persona sale cobrando
    | planilla y la afiliación desaparece; si se retira, desaparece del todo
    | (caso Carlos Zambrano, CC 16770992, oct-2026). Esto la sigue mostrando
    | hasta que exista una factura de afiliación de ese contrato.
    |
    | No se cobra cuando el retiro fue por "Error - Sin Afiliación": son
    | afiliaciones equivocadas o de prueba. Ver AfiliacionPendienteService.
    |
    */

    'afiliacion_pendiente' => [
        // Aliados que lo ven. Formalizate tenía 223 casos en ago-sep de 2026
        // contra 15 de Brygar: se prende en cada uno al revisar sus casos.
        'aliados' => [2], // Brygar

        // Ingresos desde esta fecha. Lo de antes viene de la migración, donde
        // la afiliación se cobraba en el sistema viejo.
        'desde' => '2026-08-01',
    ],
];
