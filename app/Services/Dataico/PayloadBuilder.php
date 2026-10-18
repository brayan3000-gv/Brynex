<?php

namespace App\Services\Dataico;

use App\Models\DataicoConfiguracion;
use Carbon\Carbon;

/**
 * Arma el JSON que se le manda a `POST /direct/dataico_api/v2/invoices`.
 *
 * La estructura NO es una reconstrucción: se calcó de facturas reales de
 * BRYGAR ya aceptadas por la DIAN, traídas con
 * `GET /direct/dataico_api/v2/invoices?number=FE1184` el 24-ago-2026. Por eso
 * los enums son los que Dataico devuelve de verdad (`PERSONA_NATURAL`,
 * `DEBITO`, `BANK_TRANSFER`, `SIMPLIFICADO`) y no los que uno supondría.
 *
 * Lo que se aprendió de esas facturas:
 *   · La dirección va PLANA en `customer`, no anidada en un `address`.
 *   · `PERSONA_NATURAL` usa `first_name` + `family_name`;
 *     `PERSONA_JURIDICA` usa `company_name`. Nunca los dos.
 *   · Los ítems no llevan impuestos ni descuento: BRYGAR no cobra IVA.
 *   · `notes` es un ARRAY de strings. BRYGAR pone ahí el período.
 *   · La numeración se identifica con `{prefix, resolution_number}`.
 *
 * Único campo sin confirmar: el formato de `issue_date`. La respuesta lo
 * devuelve como `15/08/2026 13:31:57`, pero el QR de la DIAN lo lleva como
 * `2026-08-15`, así que se manda ISO. Si la primera emisión rebota por fecha,
 * es lo primero que hay que cambiar — y se cambia solo aquí.
 *
 * Contenido de la factura, por decisión del dueño (24-ago-2026):
 * un solo ítem con `admon + afiliacion`. La seguridad social (EPS, ARL, AFP,
 * caja), la mora, el seguro y la mensajería NO se facturan — son plata que
 * BRYGAR recauda y traslada, no ingreso propio.
 */
class PayloadBuilder
{
    /** Servicio de administración de la afiliación (facturas de planilla). */
    private const SKU_ADMON = 'SKU 0001';

    private const DESC_ADMON = '0001-Servicio de administración y gestión de afiliaciones a seguridad social';

    /** Afiliación nueva al sistema. */
    private const SKU_AFILIACION = 'SKU 0006';

    private const DESC_AFILIACION = '0006-Afiliación al sistema general de seguridad social';

    /**
     * Relleno de correo para adquirientes sin uno.
     *
     * No es invento: es exactamente lo que traen las 1.184 facturas que BRYGAR
     * ya emitió por el Excel, y la DIAN las aceptó todas. Va siempre con
     * `send_email: false`, así que a esa dirección no le llega nada.
     */
    private const CORREO_RELLENO = 'notiene@gmail.com';

    public function construir(DataicoConfiguracion $cfg, object $grupo, ?int $consecutivo = null): array
    {
        $adq = $grupo->adquiriente;
        $esAfiliacion = strtolower($grupo->tipo ?? '') === 'afiliacion';

        // La fecha es HOY, no la del pago.
        //
        // La DIAN rechaza con la regla FAD09e —«valida que fecha de generación
        // de la factura sea igual a la fecha de firma»— cualquier factura
        // fechada antes del día en que se firma. Pasó con FE1185: se mandó con
        // issue_date del 15-jul y se firmó el 27-ago.
        //
        // Por eso el período NO puede vivir en la fecha: va en la descripción
        // del ítem y en las notas. Es exactamente lo que hacen las 1.184
        // facturas que la DIAN ya aceptó — todas las del lote del 15-ago
        // llevan issue_date 15/08 y «JUNIO» en las notas.
        $fecha = now()->format('Y-m-d');
        $aCredito = (int) ($grupo->a_credito ?? 0) === 1;

        $invoice = [
            // Va DENTRO de invoice. En la raíz del cuerpo el API responde 500
            // ("los campos deben ser sólo 'invoice' y opcionalmente
            // 'actions'"), y como header o parámetro de URL responde 500
            // diciendo que falta un account id válido — aunque el mismo valor
            // sí funciona como parámetro en el GET de consulta.
            'dataico_account_id' => $cfg->dataico_account_id,

            // Sin esto el API responde 401 «No se encuentra numeración» aunque
            // la numeración esté vigente y en uso. No está documentado; lo
            // entregó el soporte de Dataico al cerrar el ticket #47903972886
            // el 27-ago-2026, y es el único campo que destraba la emisión —
            // el `flexible` de `numbering` que venía en el mismo ejemplo no
            // cambia nada por sí solo.
            'env' => $cfg->env ?: 'PRODUCCION',

            'issue_date' => $fecha,
            // Un préstamo que sigue debiendo sale A CRÉDITO, con fecha de pago
            // a `dataico.dias_credito` días; cuando el cliente paga solo se le
            // da el recibo de pago de cartera. Lo demás es de contado.
            'payment_date' => $aCredito
                ? now()->addDays((int) config('dataico.dias_credito', 30))->format('Y-m-d')
                : $fecha,
            'invoice_type_code' => 'FACTURA_VENTA',

            // Efectivo de contado no llega hasta aquí: el criterio de selección
            // exige una consignación en la cuenta de la razón social emisora
            // (o que sea un préstamo, que no movió plata).
            'payment_means_type' => $aCredito ? 'CREDITO' : 'DEBITO',
            'payment_means' => 'BANK_TRANSFER',

            'customer' => $this->customer($cfg, $adq),
            'items' => [$this->item($grupo, $esAfiliacion)],
            'notes' => $this->notas($grupo),
        ];

        // El consecutivo lo ponemos nosotros: `number` es obligatorio y va como
        // ENTERO, sin el prefijo. Con "FE1185" el API responde 500 «Entero
        // inválido»; el prefijo viaja aparte, en `numbering`.
        if ($consecutivo !== null) {
            $invoice['number'] = $consecutivo;
        }

        if ($numeracion = $this->numeracion($cfg)) {
            $invoice['numbering'] = $numeracion;
        }

        // El cuerpo admite EXACTAMENTE dos llaves: `invoice` y, opcional,
        // `actions` — y `actions` va en la raíz, no dentro de `invoice`.
        return [
            'invoice' => $invoice,
            'actions' => $this->actions($cfg, $adq),
        ];
    }

    // ─── Piezas ──────────────────────────────────────────────────────────

    /**
     * Cuál de las resoluciones DIAN de la cuenta se usa.
     *
     * Con una sola resolución vigente se puede omitir y Dataico toma la que
     * tenga por defecto; el par explícito evita sorpresas cuando entra una
     * resolución nueva y las dos quedan activas un tiempo.
     */
    private function numeracion(DataicoConfiguracion $cfg): ?array
    {
        if (filled($cfg->prefijo) && filled($cfg->resolucion)) {
            return [
                'prefix' => $cfg->prefijo,
                'resolution_number' => $cfg->resolucion,
                // Viene en el ejemplo que mandó el soporte de Dataico.
                'flexible' => true,
            ];
        }

        return null;
    }

    private function customer(DataicoConfiguracion $cfg, array $adq): array
    {
        $esJuridica = $adq['tipo_persona'] === 'PERSONA_JURIDICA';

        $customer = [
            'party_type' => $adq['tipo_persona'],
            'party_identification_type' => $adq['tipo_documento'],
            'party_identification' => $adq['identificacion'],
            'tax_level_code' => 'SIMPLIFICADO',
            'email' => $this->correo($cfg, $adq),
        ];

        if ($esJuridica) {
            $customer['company_name'] = $adq['nombre_completo'];
        } else {
            $customer['first_name'] = $adq['primer_nombre'];
            $customer['family_name'] = $adq['apellido'];
        }

        // La dirección va plana y es TODO O NADA — `country_code` incluido.
        //
        // Mandar una parte hace que el API rechace con 500: sin address_line
        // dice «Le falta el campo 'address_line' en su direccion», y sin
        // departamento dice «Departamento '' es inválido para pais de
        // Colombia». Tumbó 3 de las primeras 10 emisiones.
        //
        // Lo que no era evidente: **`country_code` solo ya abre la validación
        // de dirección**. Con `country_code: CO` y sin los otros tres, rebota;
        // sin `country_code` y sin dirección, pasa limpio. Por eso vive aquí
        // dentro y no suelto en el customer. De 269 pendientes, 90 no tienen
        // dirección completa y se emiten sin ella, que la DIAN sí acepta.
        if (filled($adq['ciudad']) && filled($adq['departamento']) && filled($adq['direccion'])) {
            $customer['country_code'] = 'CO';
            $customer['city'] = $adq['ciudad'];
            $customer['department'] = $adq['departamento'];
            $customer['address_line'] = $adq['direccion'];
        }

        return $customer;
    }

    /**
     * La descripción lleva el mes COBRADO (`mes`/`anio`), no el mes de pago:
     * hay facturas pagadas en julio que cobran agosto. Va igual que en el
     * Excel de importación manual, para que una misma factura se lea idéntica
     * salga por donde salga.
     */
    private function item(object $grupo, bool $esAfiliacion): array
    {
        $descripcion = $esAfiliacion ? self::DESC_AFILIACION : self::DESC_ADMON;

        if ($periodo = $this->periodo($grupo)) {
            $descripcion .= " - {$periodo}";
        }

        return [
            'sku' => $esAfiliacion ? self::SKU_AFILIACION : self::SKU_ADMON,
            'description' => $descripcion,
            'quantity' => 1,
            'price' => (float) $grupo->base_admon,
        ];
    }

    /** "JULIO 2026" a partir del mes cobrado del grupo. */
    private function periodo(object $grupo): ?string
    {
        $mes = (int) ($grupo->mes ?? 0);
        $anio = (int) ($grupo->anio ?? 0);

        if ($mes < 1 || $mes > 12 || $anio < 2000) {
            return null;
        }

        return mb_strtoupper(
            Carbon::create($anio, $mes, 1)->locale('es')->isoFormat('MMMM')
        )." {$anio}";
    }

    private function actions(DataicoConfiguracion $cfg, array $adq): array
    {
        $tieneCorreoReal = $this->correoValido($adq['correo'] ?? null)
                        || $this->correoValido($cfg->correo_fallback);

        // Sin correo real no se manda representación gráfica. La factura igual
        // queda emitida ante la DIAN: es la salida acordada para el 44% de
        // clientes de BRYGAR que no tienen correo registrado.
        return [
            'send_dian' => true,
            'send_email' => $cfg->enviar_email && $tieneCorreoReal,
        ] + ($cfg->enviar_email && $tieneCorreoReal
                ? ['email' => $this->correo($cfg, $adq)]
                : []);
    }

    private function correo(DataicoConfiguracion $cfg, array $adq): string
    {
        return $this->correoValido($adq['correo'] ?? null)
            ?? $this->correoValido($cfg->correo_fallback)
            ?? self::CORREO_RELLENO;
    }

    /**
     * El correo solo si de verdad lo es.
     *
     * La columna de correo de los clientes viejos trae de todo: una «X», un
     * guion, un «no tiene». Con `filled()` bastaba para darlo por bueno y
     * Dataico respondía «HTTP 500: Correo invalido: X», tumbando la factura por
     * un campo que ni siquiera se usa cuando no se manda representación
     * gráfica.
     */
    private function correoValido(?string $correo): ?string
    {
        // Antes de descartarlo se intenta rescatar: hay direcciones buenas
        // envueltas en basura —un `mailto:` pegado, una coma al final, dos
        // correos en la misma casilla— y tirarlas le quita la representación
        // gráfica a un cliente que sí tiene dónde recibirla.
        $limpio = preg_replace('/^mailto:/i', '', trim((string) $correo));

        foreach (preg_split('/[\s,;]+/', $limpio, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $parte) {
            $parte = trim($parte, '.,;:-');

            if (filter_var($parte, FILTER_VALIDATE_EMAIL)) {
                return $parte;
            }
        }

        return null;
    }

    /**
     * `notes` es un array. BRYGAR viene poniendo ahí el mes del período
     * ("JUNIO"); se conserva esa costumbre y se agrega el número interno de
     * Brynex, que es lo que permite rastrear la factura de vuelta.
     */
    private function notas(object $grupo): array
    {
        $mes = Carbon::create((int) $grupo->anio, (int) $grupo->mes, 1)
            ->locale('es')
            ->isoFormat('MMMM');

        $notas = [mb_strtoupper($mes)];
        $notas[] = "Brynex {$grupo->numero_factura}";

        if ((int) $grupo->num_clientes > 1) {
            $notas[] = "{$grupo->num_clientes} afiliados";
        }

        return $notas;
    }
}
