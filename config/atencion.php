<?php

/**
 * Quién atiende lo que la IA de WhatsApp deja listo para una persona (20-nov-2026).
 *
 * - ventas: clientes nuevos (sin contrato vigente) que la IA pasa a una persona porque piden
 *   hablar con alguien, mandan comprobante o documentos. Quedan asignados a esa persona en la
 *   bandeja («🛒 Venta») y, si tiene número, se le avisa por WhatsApp. Antes caían en la bandeja
 *   general sin dueño: dos clientes del anuncio del plan sin pensión (Tatiana y José Elimer)
 *   esperaron 6 y 7 días con la cédula ya entregada.
 * - numeros: WhatsApp interno de cada usuario para los avisos. El campo telefono de users no
 *   sirve para esto: Angela y Daniela Ariza tienen el mismo número de relleno.
 * - copia: a quién más le llega el segundo aviso de un caso sin respuesta (user_ids).
 * - segundo_aviso_horas: horas que un caso pasado por la IA puede esperar sin respuesta antes
 *   del segundo aviso (whatsapp:avisar-traspasos). Se avisa una sola vez por caso.
 */
return [
    'ventas' => [
        2 => ['nombre' => 'Daniela Ariza', 'user_id' => 14], // Brygar
    ],

    'numeros' => [
        2 => '3117762689',  // Brayan García
        3 => '3123561665',  // Angela Ortiz
        14 => null,         // Daniela Ariza: falta su WhatsApp
    ],

    'copia' => [
        2 => [2], // Brygar: Brayan
    ],

    'segundo_aviso_horas' => 2,
];
