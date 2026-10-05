<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Escalera de administración por cartera del mes
    |--------------------------------------------------------------------------
    | Por aliado: qué porcentaje de la administración le corresponde al asesor
    | según las personas que atendió en el mes (cédulas distintas). Solo alimenta
    | el informe «Cómo van los asesores»: el porcentaje de cada asesor se sigue
    | cambiando a mano. Un aliado sin escalera ve el informe sin niveles.
    |
    | niveles         => [desde N personas => % de la administración]
    | arranque_meses  => meses iniciales al porcentaje más alto
    | metas_arranque  => [mes del arranque => personas que debe tener al cierre]
    | prorroga_desde  => con cuántas personas al final del arranque gana un mes más
    */
    'escaleras' => [
        2 => [ // Brygar
            'niveles' => [1 => 20, 5 => 30, 10 => 40, 20 => 50],
            'arranque_meses' => 3,
            'metas_arranque' => [2 => 10, 3 => 20],
            'prorroga_desde' => 15,
        ],
    ],

];
