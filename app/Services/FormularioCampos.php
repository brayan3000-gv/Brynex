<?php

namespace App\Services;

/**
 * Catálogo de campos que se pueden mapear sobre un PDF de afiliación
 * (clave interna → etiqueta para el usuario).
 *
 * Lo comparten el editor de formularios de EPS y el de fondos de pensión:
 * los datos del cotizante, la empresa y las fechas son los mismos en ambos
 * formularios, solo cambia dónde caen en la hoja.
 */
class FormularioCampos
{
    public static function disponibles(): array
    {
        return [
            // ── Cotizante ──────────────────────────────────────────
            'cliente.primer_apellido' => 'Primer apellido',
            'cliente.segundo_apellido' => 'Segundo apellido',
            'cliente.primer_nombre' => 'Primer nombre',
            'cliente.segundo_nombre' => 'Segundo nombre',
            'cliente.tipo_doc' => 'Tipo de documento',
            'cliente.cedula' => 'Número de documento / Cédula',
            'cliente.genero' => 'Género (M / F)',
            'cliente.genero_m' => 'Género — cuadro MASCULINO (X si es M)',
            'cliente.genero_f' => 'Género — cuadro FEMENINO (X si es F)',
            'cliente.firma' => '✍️ FIRMA del cliente (imagen PNG)',
            'static.COLOMBIANA' => 'Nacionalidad (predeterminada: COLOMBIANA)',
            'tramite.afiliacion_x' => 'Trámite — X en AFILIACIÓN (se quita si es novedad)',
            'tramite.novedad_x' => 'Trámite — X en REPORTE DE NOVEDADES (solo novedad)',
            'novedad.inicio_laboral_x' => 'Novedad — X en INICIO DE RELACIÓN LABORAL (solo novedad)',
            'eps.nombre' => 'Nombre EPS',
            'arl.nombre' => 'Nombre ARL',
            'pension.nombre' => 'Nombre Pensión',
            'contrato.salario' => 'Salario del contrato',
            'cliente.direccion' => 'Dirección del cotizante',
            'cliente.telefono' => 'Teléfono',
            'cliente.celular' => 'Celular',
            'cliente.correo' => 'Correo electrónico',
            'cliente.departamento' => 'Departamento del cotizante',
            'cliente.municipio' => 'Municipio del cotizante',
            'cliente.barrio' => 'Barrio del cotizante',
            'cliente.sisben' => 'Sisben',
            'cliente.ips' => 'IPS',
            'cliente.ocupacion' => 'Ocupación / Cargo cliente',
            // ── Empresa / Razón Social ─────────────────────────────
            'empresa.razon_social' => 'Nombre o razón social',
            'empresa.tipo_doc' => 'Tipo de documento de la empresa (NIT)',
            'empresa.nit' => 'NIT de la empresa',
            'empresa.nit_dv' => 'NIT con dígito de verificación',
            'empresa.direccion' => 'Dirección de la empresa',
            'empresa.telefono' => 'Teléfono de la empresa',
            'empresa.correo' => 'Correo de la empresa',
            'empresa.departamento' => 'Departamento de la empresa',
            'empresa.municipio' => 'Municipio de la empresa',
            'empresa.sello' => '🖼️ SELLO / FIRMA (imagen PNG)',
            'contrato.cargo' => 'Cargo / Ocupación contrato',

            // ══ FECHAS ════════════════════════════════════════════
            // ── Fecha de nacimiento ────────────────────────────────
            'cliente.fecha_nacimiento' => '📅 Nac. — Fecha completa (dd/mm/aaaa)',
            'cliente.fecha_nacimiento_d_esp' => '📅 Nac. — DÍA espaciado (0 5)',
            'cliente.fecha_nacimiento_m_esp' => '📅 Nac. — MES espaciado (0 2)',
            'cliente.fecha_nacimiento_a_esp' => '📅 Nac. — AÑO espaciado (1 9 9 0)',
            // Día individual
            'cliente.fecha_nacimiento_d1' => '📅 Nac. — DÍA dígito 1  (ej: 0)',
            'cliente.fecha_nacimiento_d2' => '📅 Nac. — DÍA dígito 2  (ej: 5)',
            // Mes individual
            'cliente.fecha_nacimiento_m1' => '📅 Nac. — MES dígito 1  (ej: 0)',
            'cliente.fecha_nacimiento_m2' => '📅 Nac. — MES dígito 2  (ej: 2)',
            // Año individual
            'cliente.fecha_nacimiento_a1' => '📅 Nac. — AÑO dígito 1  (ej: 1)',
            'cliente.fecha_nacimiento_a2' => '📅 Nac. — AÑO dígito 2  (ej: 9)',
            'cliente.fecha_nacimiento_a3' => '📅 Nac. — AÑO dígito 3  (ej: 9)',
            'cliente.fecha_nacimiento_a4' => '📅 Nac. — AÑO dígito 4  (ej: 0)',

            // ── Fecha de ingreso ───────────────────────────────────
            'contrato.fecha_ingreso' => '📅 Ing. — Fecha completa (dd/mm/aaaa)',
            'contrato.fecha_ingreso_d_esp' => '📅 Ing. — DÍA espaciado (0 5)',
            'contrato.fecha_ingreso_m_esp' => '📅 Ing. — MES espaciado (0 2)',
            'contrato.fecha_ingreso_a_esp' => '📅 Ing. — AÑO espaciado (2 0 2 6)',
            // Día individual
            'contrato.fecha_ingreso_d1' => '📅 Ing. — DÍA dígito 1  (ej: 0)',
            'contrato.fecha_ingreso_d2' => '📅 Ing. — DÍA dígito 2  (ej: 5)',
            // Mes individual
            'contrato.fecha_ingreso_m1' => '📅 Ing. — MES dígito 1  (ej: 0)',
            'contrato.fecha_ingreso_m2' => '📅 Ing. — MES dígito 2  (ej: 2)',
            // Año individual
            'contrato.fecha_ingreso_a1' => '📅 Ing. — AÑO dígito 1  (ej: 2)',
            'contrato.fecha_ingreso_a2' => '📅 Ing. — AÑO dígito 2  (ej: 0)',
            'contrato.fecha_ingreso_a3' => '📅 Ing. — AÑO dígito 3  (ej: 2)',
            'contrato.fecha_ingreso_a4' => '📅 Ing. — AÑO dígito 4  (ej: 6)',

            // ── Radicado (solo en la copia que guarda BryNex, ya radicada) ──
            // El formulario que se envía va sin ellos: el número lo da la entidad al
            // recibirlo. Se estampan sobre el mismo PDF enviado (ver estamparRadicado).
            'radicado.numero' => '🧾 Radicado — Número que dio la entidad',
            'radicado.fecha' => '🧾 Radicado — Fecha completa (dd/mm/aaaa)',
            'radicado.fecha_d1' => '🧾 Radicado — DÍA dígito 1',
            'radicado.fecha_d2' => '🧾 Radicado — DÍA dígito 2',
            'radicado.fecha_m1' => '🧾 Radicado — MES dígito 1',
            'radicado.fecha_m2' => '🧾 Radicado — MES dígito 2',
            'radicado.fecha_a1' => '🧾 Radicado — AÑO dígito 1',
            'radicado.fecha_a2' => '🧾 Radicado — AÑO dígito 2',
            'radicado.fecha_a3' => '🧾 Radicado — AÑO dígito 3',
            'radicado.fecha_a4' => '🧾 Radicado — AÑO dígito 4',
        ];
    }
}
