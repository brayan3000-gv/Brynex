{{-- Formulario de Confirmar pago X1 (tanda de un aliado o planilla conjunta). Usa el x-data tandaX1. --}}
<div class="bx1-confirmar" x-show="abrirConfirmar" x-cloak style="flex-direction:column;align-items:stretch;max-width:320px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem">
    <label class="bx1-meta">N° de planilla
        <input type="text" x-model="numeroConfirmar" placeholder="Ej: 1086803618" style="width:100%">
    </label>
    <label class="bx1-meta">Cómo se pagó
        <select x-model="estadoConfirmar" style="width:100%">
            <option value="pagada">Pagada (Simple)</option>
            <option value="doble_planilla">Doble planilla</option>
            <option value="blindem">Blindem</option>
        </select>
    </label>
    <label class="bx1-meta">Valor pagado
        <input type="number" min="1" x-model="valorConfirmar" placeholder="Total que salió del banco" style="width:100%">
    </label>
    <label class="bx1-meta">N° de comprobante (PSE / CUS)
        <input type="text" x-model="comprobanteConfirmar" maxlength="60" style="width:100%">
    </label>
    <label class="bx1-meta">Fecha de pago
        <input type="date" x-model="fechaConfirmar" style="width:100%">
    </label>
    <label class="bx1-meta">Foto o PDF del comprobante
        <input type="file" accept="image/*,application/pdf" x-ref="soporte" style="width:100%">
    </label>
    <label class="bx1-meta">Observación
        <input type="text" x-model="observacionConfirmar" maxlength="500" style="width:100%">
    </label>
    <button type="button" class="bx1-btn verde" @click="confirmar()" :disabled="ocupado" style="justify-content:center">
        <span x-text="ocupado === 'confirmar' ? 'Guardando…' : 'Guardar pago'">Guardar pago</span>
    </button>
</div>
