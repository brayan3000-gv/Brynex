{{--
    ╔══════════════════════════════════════════════════════════╗
    ║  PARTIAL: _modal_anticipo_distribuido.blade.php  — BryNex ║
    ║  Modal multi-paso para registrar anticipos distribuidos ║
    ║  de empresas a clientes individuales.                   ║
    ╚══════════════════════════════════════════════════════════╝
--}}
@php
    $adBancos = $bancos ?? collect();
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
@endphp

<style>
/* ═══════════════════════════════════════════════════════════════
   Modal Anticipo Distribuido — BryNex
   Diseño Premium en tonos Ámbar / Dorado
   ═══════════════════════════════════════════════════════════════ */
#ad-overlay {
    position: fixed; inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 2100;
    display: flex; align-items: center; justify-content: center;
    padding: .75rem;
}
#ad-box {
    background: #fff; border-radius: 18px;
    width: min(780px, 98vw); max-height: 96vh;
    overflow: hidden; display: flex; flex-direction: column;
    box-shadow: 0 32px 100px rgba(0,0,0,.35), 0 0 0 1px rgba(255,255,255,.05);
    animation: adScaleUp 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes adScaleUp {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

/* ── HEADER ── */
#ad-header {
    background: linear-gradient(135deg, #b45309 0%, #d97706 100%);
    padding: .9rem 1.3rem .8rem;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
#ad-header-icon {
    width: 36px; height: 36px; border-radius: 10px;
    background: rgba(255, 255, 255, 0.15);
    display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
}
#ad-header-text { margin-left: .8rem; flex: 1; }
#ad-header-text h2 { font-size: .95rem; font-weight: 800; color: #fff; margin: 0; }
#ad-header-text p { font-size: .68rem; color: rgba(255, 255, 255, 0.75); margin: 0; }
#ad-close-btn {
    width: 28px; height: 28px; border-radius: 7px; border: none; cursor: pointer;
    background: rgba(255, 255, 255, 0.1); color: rgba(255, 255, 255, 0.85); font-size: .95rem;
    display: flex; align-items: center; justify-content: center; transition: background .15s;
}
#ad-close-btn:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }

/* ── PROGRESS BAR ── */
#ad-progress-container {
    background: #fffbeb; border-bottom: 1px solid #fde68a;
    padding: .6rem 1.5rem; display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
.ad-step-indicator {
    display: flex; align-items: center; gap: .45rem; font-size: .72rem; font-weight: 700; color: #92400e; opacity: 0.5;
    transition: opacity 0.2s, transform 0.2s;
}
.ad-step-indicator.active { opacity: 1; transform: scale(1.02); }
.ad-step-indicator.completed { opacity: 0.95; color: #16a34a; }
.ad-step-num {
    width: 20px; height: 20px; border-radius: 50%; background: #f59e0b; color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: .68rem;
}
.ad-step-indicator.completed .ad-step-num { background: #16a34a; }
.ad-step-line { flex: 1; height: 2px; background: #fcd34d; margin: 0 1rem; opacity: 0.4; }

/* ── BODY & STEPS ── */
#ad-body {
    flex: 1; overflow-y: auto; padding: 1.2rem 1.4rem; background: #fafafa; min-height: 0;
}
.ad-step-content { display: none; }
.ad-step-content.active { display: block; animation: adFadeIn 0.2s ease-out; }
@keyframes adFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ── FORM GROUPS ── */
.ad-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.9rem; }
.ad-form-group { display: flex; flex-direction: column; gap: .3rem; }
.ad-form-group.full { grid-column: 1 / span 2; }
.ad-label { font-size: .65rem; font-weight: 800; color: #451a03; text-transform: uppercase; letter-spacing: .04em; }
.ad-input, .ad-select, .ad-textarea {
    padding: .48rem .7rem; border: 1.5px solid #fcd34d; border-radius: 8px;
    font-size: .82rem; background: #fff; color: #0f172a; outline: none;
    transition: border-color 0.15s, box-shadow 0.15s; font-family: inherit;
}
.ad-input:focus, .ad-select:focus, .ad-textarea:focus {
    border-color: #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.15);
}

/* Paso 2: Selección de Clientes */
#ad-clients-search {
    padding: .45rem .7rem; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: .8rem; width: 100%; box-sizing: border-box; margin-bottom: .8rem; outline: none;
}
#ad-clients-list-container {
    border: 1.5px solid #fde68a; border-radius: 10px; background: #fff;
    max-height: 220px; overflow-y: auto; padding: .4rem;
}
.ad-client-item {
    display: flex; align-items: center; gap: .6rem; padding: .42rem .6rem;
    border-radius: 7px; transition: background .12s; cursor: pointer;
    border-bottom: 1px solid #fef3c7;
}
.ad-client-item:last-child { border-bottom: none; }
.ad-client-item:hover { background: #fffbeb; }
.ad-client-item.selected { background: #fef3c7; }
.ad-client-checkbox {
    width: 15px; height: 15px; border-radius: 4px; border: 1.5px solid #d97706;
    cursor: pointer; accent-color: #d97706;
}
.ad-client-details { display: flex; flex-direction: column; flex: 1; }
.ad-client-name { font-size: .78rem; font-weight: 700; color: #1e293b; }
.ad-client-sub { font-size: .65rem; color: #64748b; display: flex; gap: .6rem; }
.ad-client-plan { color: #b45309; font-weight: 600; }

/* Paso 3: Distribución */
#ad-dist-table-container {
    border: 1.5px solid #fde68a; border-radius: 10px; background: #fff;
    max-height: 240px; overflow-y: auto; margin-bottom: .9rem;
}
.ad-dist-table {
    width: 100%; border-collapse: collapse; text-align: left; font-size: .78rem;
}
.ad-dist-table th {
    background: #fffbeb; color: #92400e; padding: .55rem .7rem;
    font-weight: 800; font-size: .62rem; text-transform: uppercase;
    border-bottom: 1.5px solid #fde68a; position: sticky; top: 0; z-index: 10;
}
.ad-dist-table td {
    padding: .4rem .7rem; border-bottom: 1px solid #fef3c7; vertical-align: middle;
}
.ad-dist-table tr:last-child td { border-bottom: none; }
.ad-dist-val-input {
    padding: .28rem .5rem; border: 1.5px solid #fcd34d; border-radius: 6px;
    font-size: .78rem; font-weight: 700; color: #0f172a; outline: none; width: 90px; text-align: right;
}
.ad-dist-val-input:focus { border-color: #d97706; }
.ad-dist-period-select {
    padding: .25rem .4rem; border: 1.5px solid #e2e8f0; border-radius: 6px;
    font-size: .72rem; outline: none; background: #fff;
}
.ad-dist-period-select:focus { border-color: #f59e0b; }

/* Indicadores de balance */
#ad-dist-balance {
    background: #fffbeb; border: 1.5px dashed #fde68a; border-radius: 10px;
    padding: .6rem .9rem; display: flex; align-items: center; justify-content: space-between;
}
.ad-balance-item { display: flex; flex-direction: column; gap: .1rem; }
.ad-balance-label { font-size: .58rem; font-weight: 800; color: #92400e; text-transform: uppercase; }
.ad-balance-val { font-size: .9rem; font-weight: 900; color: #1e293b; }
.ad-balance-val.match { color: #16a34a; }
.ad-balance-val.diff { color: #dc2626; }

/* ── FOOTER ── */
#ad-footer {
    background: #f8fafc; border-top: 1px solid #e2e8f0;
    padding: .75rem 1.3rem; display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0; border-radius: 0 0 18px 18px;
}
.ad-btn {
    padding: .42rem 1.1rem; border-radius: 8px; font-size: .78rem; font-weight: 700;
    cursor: pointer; border: none; display: flex; align-items: center; gap: .4rem;
    transition: background .15s, opacity .15s; font-family: inherit;
}
.ad-btn-secondary { background: #e2e8f0; color: #334155; }
.ad-btn-secondary:hover { background: #cbd5e1; }
.ad-btn-primary { background: #d97706; color: #fff; }
.ad-btn-primary:hover { background: #b45309; }
.ad-btn-primary:disabled { background: #fcd34d; color: rgba(255,255,255,0.7); cursor: not-allowed; }

/* ── Pegado masivo desde Excel ── */
#ad-paste-panel {
    border: 1.5px dashed #d97706; background: #fffbeb;
    border-radius: 10px; padding: .6rem .8rem; margin-bottom: .8rem;
}
#ad-paste-toggle {
    display: flex; align-items: center; justify-content: space-between; cursor: pointer;
    font-size: .68rem; font-weight: 800; color: #92400e;
    text-transform: uppercase; letter-spacing: .03em; user-select: none;
}
#ad-paste-body { margin-top: .55rem; }
#ad-paste-text {
    width: 100%; box-sizing: border-box; min-height: 76px; padding: .45rem .6rem;
    border: 1.5px solid #fcd34d; border-radius: 8px; background: #fff;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem;
    outline: none; resize: vertical; color: #0f172a;
}
#ad-paste-text:focus { border-color: #d97706; box-shadow: 0 0 0 3px rgba(217,119,6,.15); }
.ad-mini-btn {
    padding: .3rem .7rem; border-radius: 7px; font-size: .68rem; font-weight: 800;
    cursor: pointer; border: none; font-family: inherit;
}
.ad-mini-primary { background: #d97706; color: #fff; }
.ad-mini-primary:hover { background: #b45309; }
.ad-mini-ghost { background: #fff; color: #92400e; border: 1.5px solid #fcd34d; }
.ad-mini-ghost:hover { background: #fef3c7; }
#ad-paste-result {
    margin-top: .5rem; font-size: .71rem; line-height: 1.45;
    display: none; border-radius: 8px; padding: .45rem .6rem;
}
#ad-paste-result.ok   { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
#ad-paste-result.warn { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
#ad-paste-result.err  { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
.ad-row-del {
    background: transparent; border: none; color: #cbd5e1; cursor: pointer;
    font-size: .85rem; line-height: 1; padding: .15rem .3rem; border-radius: 5px;
}
.ad-row-del:hover { color: #dc2626; background: #fef2f2; }
tr.ad-row-nueva td { background: #fffbeb; }
#ad-total-auto {
    display: flex; align-items: center; gap: .25rem; margin-top: .15rem;
    font-size: .55rem; font-weight: 800; color: #92400e;
    text-transform: uppercase; cursor: pointer; user-select: none;
}
#ad-total-auto input { width: 11px; height: 11px; accent-color: #d97706; cursor: pointer; }
</style>

<div id="ad-overlay" style="display: none;" onclick="MAD.cerrarSi(event)">
    <div id="ad-box">
        <!-- HEADER -->
        <div id="ad-header">
            <div style="display: flex; align-items: center;">
                <div id="ad-header-icon">💼</div>
                <div id="ad-header-text">
                    <h2 id="ad-title-label">Registrar Anticipo Empresa</h2>
                    <p id="ad-subtitle-label">Distribución entre empleados de la empresa</p>
                </div>
            </div>
            <button id="ad-close-btn" onclick="MAD.cerrar()">&times;</button>
        </div>

        <!-- PROGRESS BAR -->
        <div id="ad-progress-container">
            <div class="ad-step-indicator active" id="ad-ind-1">
                <span class="ad-step-num">1</span>
                <span>Datos del Pago</span>
            </div>
            <div class="ad-step-line"></div>
            <div class="ad-step-indicator" id="ad-ind-3">
                <span class="ad-step-num">2</span>
                <span id="ad-step-3-label">Distribución / Confirmación</span>
            </div>
        </div>

        <!-- BODY -->
        <div id="ad-body">
            <form id="ad-form" onsubmit="event.preventDefault();" enctype="multipart/form-data">
                <input type="hidden" id="ad-empresa-id" name="empresa_id">

                <!-- PASO 1: Datos de pago -->
                <div class="ad-step-content active" id="ad-step-1">
                    <div class="ad-form-grid">
                        <div class="ad-form-group">
                            <label class="ad-label">Fecha de Pago</label>
                            <input type="date" id="ad-fecha" name="fecha_pago" class="ad-input" required value="{{ date('Y-m-d') }}">
                        </div>
                        <div class="ad-form-group">
                            <label class="ad-label">Valor del Anticipo</label>
                            <input type="number" id="ad-valor" name="valor" class="ad-input" placeholder="Monto total pagado" min="0" oninput="MAD.totalManual()">
                            <span style="font-size: .62rem; color: #64748b; line-height: 1.3;">
                                Puedes dejarlo vacío si vas a pegar la lista desde Excel: se calcula solo.
                            </span>
                        </div>
                    </div>

                    <div class="ad-form-grid">
                        <div class="ad-form-group">
                            <label class="ad-label">¿Quién pone la plata?</label>
                            <select id="ad-origen" name="origen" class="ad-select">
                                <option value="empresa">La empresa</option>
                                <option value="clientes">Los clientes (aporte individual)</option>
                            </select>
                        </div>
                        <div class="ad-form-group" style="justify-content: flex-end;">
                            <span style="font-size: .62rem; color: #64748b; line-height: 1.35;">
                                Queda impreso en el recibo. Si el dinero vino de dos lados,
                                registra cada pago por separado para que salga su propio recibo.
                            </span>
                        </div>
                    </div>

                    <div class="ad-form-grid">
                        <div class="ad-form-group">
                            <label class="ad-label">Forma de Pago</label>
                            <select id="ad-forma-pago" name="forma_pago" class="ad-select" onchange="MAD.toggleBanco()">
                                <option value="efectivo">Efectivo</option>
                                <option value="transferencia">Transferencia / Banco</option>
                            </select>
                        </div>
                        <div class="ad-form-group" id="ad-banco-group" style="display: none;">
                            <label class="ad-label">Cuenta de Destino</label>
                            <select id="ad-banco" name="banco_cuenta_id" class="ad-select">
                                <option value="">Seleccione Cuenta...</option>
                                @php
                                    $bancosOrdenados = $adBancos->sortByDesc('cobro');
                                @endphp
                                @foreach($bancosOrdenados as $b)
                                    <option value="{{ $b->id }}">
                                        {{ $b->banco }} — {{ $b->nombre }} | {{ $b->tipo_cuenta }} {{ $b->numero_cuenta }} @if($b->cobro) ⭐ (Cobro) @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="ad-form-grid" id="ad-trans-fields-group" style="display: none;">
                        <div class="ad-form-group">
                            <label class="ad-label">Referencia / Comprobante</label>
                            <input type="text" id="ad-referencia" name="referencia" class="ad-input" placeholder="N° de transferencia o nequi">
                        </div>
                        <div class="ad-form-group">
                            <label class="ad-label">Soporte de Pago (Imagen/PDF)</label>
                            <input type="file" id="ad-imagen" name="imagen" class="ad-input" accept="image/*,application/pdf" onchange="MAD.mostrarPreview(this)">
                            
                            <!-- Contenedor de previsualización premium -->
                            <div id="ad-preview-container" style="display: none; margin-top: .4rem; position: relative; border-radius: 8px; border: 1.5px dashed #d97706; overflow: hidden; background: #fffbeb; padding: .4rem;">
                                <div style="display: flex; align-items: center; gap: .5rem;">
                                    <img id="ad-preview-img" style="max-width: 60px; max-height: 60px; object-fit: cover; border-radius: 4px; display: none;" />
                                    <div id="ad-preview-fileinfo" style="font-size: .7rem; color: #92400e; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></div>
                                    <button type="button" onclick="MAD.eliminarSoporte()" style="background: #dc2626; color: white; border: none; border-radius: 5px; padding: .2rem .4rem; cursor: pointer; font-size: .65rem; font-weight: 700;">✕ Quitar</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ad-form-group full" style="margin-top: .3rem;">
                        <label class="ad-label">Observación</label>
                        <textarea id="ad-observacion" name="observacion" rows="2" class="ad-textarea" placeholder="Notas adicionales del registro..."></textarea>
                    </div>
                </div>

                <!-- PASO 3: Distribución y validación -->
                <div class="ad-step-content" id="ad-step-3">
                    <div style="margin-bottom: .5rem; font-size: .72rem; color: #475569;" id="ad-dist-instr-label">
                        Distribuye el valor total del anticipo entre los clientes seleccionados. Por defecto se dividirá equitativamente.
                    </div>

                    <div id="ad-paste-panel">
                        <div id="ad-paste-toggle" onclick="MAD.togglePaste()">
                            <span>📋 Pegar desde Excel (cédula y valor)</span>
                            <span id="ad-paste-caret">▾</span>
                        </div>
                        <div id="ad-paste-body">
                            <textarea id="ad-paste-text" spellcheck="false"
                                placeholder="Pega dos columnas: cédula y valor. Ejemplo:&#10;55131308&#9;137.900&#10;29973141&#9;137.900"></textarea>
                            <div style="display: flex; gap: .4rem; margin-top: .45rem; flex-wrap: wrap; align-items: center;">
                                <button type="button" class="ad-mini-btn ad-mini-primary" onclick="MAD.aplicarPegado()">Aplicar a la tabla</button>
                                <button type="button" class="ad-mini-btn ad-mini-ghost" id="ad-btn-deshacer" style="display: none;" onclick="MAD.deshacerPegado()">↶ Deshacer última</button>
                                <button type="button" class="ad-mini-btn ad-mini-ghost" onclick="MAD.limpiarValores()">Poner todo en cero</button>
                                <span style="font-size: .63rem; color: #78716c; line-height: 1.3;">
                                    Cada pegada <b>suma</b> sobre lo que ya está en la tabla.
                                </span>
                            </div>
                            <div id="ad-paste-result"></div>
                        </div>
                    </div>

                    <div id="ad-dist-table-container">
                        <table class="ad-dist-table">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Cédula</th>
                                    <th>Plan Vigente</th>
                                    <th style="width: 140px;">Mes Destino Info</th>
                                    <th style="text-align: right; width: 120px;">Monto Anticipo</th>
                                    <th style="width: 26px;"></th>
                                </tr>
                            </thead>
                            <tbody id="ad-dist-tbody">
                                <!-- Filas de distribución dinámicas -->
                            </tbody>
                        </table>
                    </div>

                    <!-- BALANCE -->
                    <div id="ad-dist-balance">
                        <div class="ad-balance-item">
                            <span class="ad-balance-label">Valor Total</span>
                            <span class="ad-balance-val" id="ad-bal-total">$0</span>
                            <label id="ad-total-auto">
                                <input type="checkbox" id="ad-total-auto-chk" onchange="MAD.toggleTotalAuto(this.checked)">
                                Igual a la suma
                            </label>
                        </div>
                        <div class="ad-balance-item">
                            <span class="ad-balance-label">Suma Distribuida</span>
                            <span class="ad-balance-val" id="ad-bal-suma">$0</span>
                        </div>
                        <div class="ad-balance-item" style="text-align: right;">
                            <span class="ad-balance-label">Diferencia / Restante</span>
                            <span class="ad-balance-val match" id="ad-bal-restante">$0</span>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- FOOTER -->
        <div id="ad-footer">
            <div>
                <button type="button" id="ad-btn-atras" class="ad-btn ad-btn-secondary" style="display: none;" onclick="MAD.atras()">
                    ← Atrás
                </button>
            </div>
            <div style="display: flex; gap: .5rem;">
                <button type="button" class="ad-btn ad-btn-secondary" onclick="MAD.cerrar()">
                    Cancelar
                </button>
                <button type="button" id="ad-btn-sig" class="ad-btn ad-btn-primary" onclick="MAD.siguiente()">
                    Siguiente →
                </button>
                <button type="button" id="ad-btn-registrar" class="ad-btn ad-btn-primary" style="display: none;" onclick="MAD.registrar()" disabled>
                    ✓ Registrar Anticipo
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Namespace para evitar colisión de variables en JS global
const MAD = {
    empresaId: null,
    contratos: [], // lista completa de contratos desde la API
    contratosSeleccionadosOriginales: [], // IDs seleccionados en la tabla principal
    cargandoContratos: false,
    pasoActivo: 1,
    meses: @json($meses),

    // ── Estado de la distribución ──
    filas: [],          // contratos que están en la tabla del paso 2
    montos: {},         // { contrato_id: valor }
    periodos: {},       // { contrato_id: {mes, anio} }
    ultimaPegada: null, // { deltas: {id: valor}, nuevos: [ids] } para deshacer
    totalAuto: false,   // el total se recalcula solo con la suma
    tocado: false,      // ya se pegó / editó algo: no volver a repartir por partes iguales

    abrir: function(empresaId, empresaNombre) {
        this.empresaId = empresaId;
        this.pasoActivo = 1;
        this.contratos = [];
        this.cargandoContratos = true;

        // Reset del estado de distribución
        this.filas = [];
        this.montos = {};
        this.periodos = {};
        this.ultimaPegada = null;
        this.totalAuto = false;
        this.tocado = false;
        const pasteText = document.getElementById('ad-paste-text');
        if (pasteText) pasteText.value = '';
        this.ocultarResultadoPegado();
        const chkAuto = document.getElementById('ad-total-auto-chk');
        if (chkAuto) chkAuto.checked = false;
        const btnDesh = document.getElementById('ad-btn-deshacer');
        if (btnDesh) btnDesh.style.display = 'none';
        
        // Capturar selección previa de la tabla principal
        const checkboxes = document.querySelectorAll('.chk-row:checked');
        this.contratosSeleccionadosOriginales = [...checkboxes].map(chk => parseInt(chk.value));

        // Reset campos del form
        document.getElementById('ad-form').reset();
        document.getElementById('ad-empresa-id').value = empresaId;
        document.getElementById('ad-title-label').innerText = `Registrar Anticipo: ${empresaNombre}`;
        document.getElementById('ad-imagen').value = '';
        this.eliminarSoporte();
        
        // Ocultar grupo banco y campos transferencia
        document.getElementById('ad-banco-group').style.display = 'none';
        document.getElementById('ad-trans-fields-group').style.display = 'none';
        
        // Configurar botón Siguiente como cargando
        const btnSig = document.getElementById('ad-btn-sig');
        if (btnSig) {
            btnSig.setAttribute('disabled', 'disabled');
            btnSig.innerText = 'Cargando contratos...';
        }

        document.getElementById('ad-overlay').style.display = 'flex';
        this.irAPaso(1);

        fetch(`/admin/anticipos/api/contratos-empresa/${empresaId}`)
            .then(res => res.json())
            .then(data => {
                this.cargandoContratos = false;
                if (btnSig) {
                    btnSig.removeAttribute('disabled');
                    btnSig.innerText = 'Siguiente →';
                }
                if (data.ok && data.contratos) {
                    this.contratos = data.contratos;
                } else {
                    console.error("Error al cargar contratos de la empresa para anticipos:", data.mensaje);
                }
            })
            .catch(err => {
                this.cargandoContratos = false;
                if (btnSig) {
                    btnSig.removeAttribute('disabled');
                    btnSig.innerText = 'Siguiente →';
                }
                console.error("Error de red al cargar contratos de la empresa:", err);
            });
    },

    cerrar: function() {
        document.getElementById('ad-overlay').style.display = 'none';
    },

    cerrarSi: function(e) {
        if (e.target.id === 'ad-overlay') {
            this.cerrar();
        }
    },

    toggleBanco: function() {
        const forma = document.getElementById('ad-forma-pago').value;
        const bGroup = document.getElementById('ad-banco-group');
        const bancoInput = document.getElementById('ad-banco');
        const transFields = document.getElementById('ad-trans-fields-group');
        const refInput = document.getElementById('ad-referencia');
        const imgInput = document.getElementById('ad-imagen');
        
        if (forma === 'transferencia') {
            bGroup.style.display = 'flex';
            transFields.style.display = 'grid';
            bancoInput.setAttribute('required', 'required');
        } else {
            bGroup.style.display = 'none';
            transFields.style.display = 'none';
            bancoInput.removeAttribute('required');
            bancoInput.value = '';
            refInput.value = '';
            imgInput.value = '';
            this.eliminarSoporte();
        }
    },

    obtenerSeleccionados: function() {
        const idsOriginales = this.contratosSeleccionadosOriginales.map(id => parseInt(id));
        return this.contratos.filter(c => idsOriginales.includes(parseInt(c.id)));
    },

    irAPaso: function(paso) {
        // Ocultar todos
        document.getElementById('ad-step-1').classList.remove('active');
        document.getElementById('ad-step-3').classList.remove('active');
        
        // Desactivar indicadores
        document.getElementById('ad-ind-1').classList.remove('active', 'completed');
        document.getElementById('ad-ind-3').classList.remove('active', 'completed');
        
        // Mostrar paso activo
        document.getElementById(`ad-step-${paso}`).classList.add('active');
        this.pasoActivo = paso;

        // Configurar botones
        const btnAtras = document.getElementById('ad-btn-atras');
        const btnSig = document.getElementById('ad-btn-sig');
        const btnRegistrar = document.getElementById('ad-btn-registrar');

        if (paso === 1) {
            btnAtras.style.display = 'none';
            btnSig.style.display = 'block';
            btnRegistrar.style.display = 'none';
            document.getElementById('ad-ind-1').classList.add('active');
        } else if (paso === 3) {
            btnAtras.style.display = 'block';
            btnSig.style.display = 'none';
            btnRegistrar.style.display = 'block';
            document.getElementById('ad-ind-1').classList.add('completed');
            document.getElementById('ad-ind-3').classList.add('active');
            
            // Generar tabla de distribución o mensaje de abono libre y balance
            this.generarDistribucion();
        }
    },

    siguiente: function() {
        if (this.cargandoContratos) {
            return;
        }
        if (this.pasoActivo === 1) {
            // Validar campos de Paso 1
            const valorInput = document.getElementById('ad-valor');
            const fechaInput = document.getElementById('ad-fecha');
            const formaInput = document.getElementById('ad-forma-pago');
            const bancoInput = document.getElementById('ad-banco');

            if (!fechaInput.value) {
                alert('Debe ingresar la fecha de pago.');
                return;
            }
            // El valor puede quedar vacío: se calcula al pegar la lista en el paso 2.
            if (valorInput.value !== '' && parseInt(valorInput.value) < 0) {
                alert('El valor del anticipo no puede ser negativo.');
                return;
            }
            if (valorInput.value === '') {
                this.totalAuto = true;
                const chkAuto = document.getElementById('ad-total-auto-chk');
                if (chkAuto) chkAuto.checked = true;
            }
            if (formaInput.value === 'transferencia' && !bancoInput.value) {
                alert('Debe seleccionar la cuenta bancaria de destino.');
                return;
            }
            
            this.irAPaso(3);
        }
    },

    atras: function() {
        if (this.pasoActivo === 3) {
            this.irAPaso(1);
        }
    },

    // ═══════════════════════════════════════════════════════════
    //  Motor de distribución
    // ═══════════════════════════════════════════════════════════

    fmt: function(val) {
        return '$' + new Intl.NumberFormat('es-CO').format(val || 0);
    },

    // Normaliza una cédula para comparar: solo dígitos y sin ceros a la izquierda
    normCedula: function(valor) {
        return String(valor ?? '').replace(/\D/g, '').replace(/^0+/, '');
    },

    generarDistribucion: function() {
        // Semilla: mientras nadie haya pegado ni editado nada, la tabla es
        // la selección de la pantalla principal repartida por partes iguales.
        if (!this.tocado) {
            this.filas = this.obtenerSeleccionados().slice();
            this.montos = {};
            const totalValor = parseInt(document.getElementById('ad-valor').value || 0);

            if (this.filas.length > 0 && totalValor > 0) {
                const base = Math.floor(totalValor / this.filas.length);
                const residuo = totalValor - (base * this.filas.length);
                this.filas.forEach((c, i) => {
                    this.montos[c.id] = base + (i === this.filas.length - 1 ? residuo : 0);
                });
            } else {
                this.filas.forEach(c => { this.montos[c.id] = 0; });
            }
        }

        this.render();
    },

    render: function() {
        const tbody = document.getElementById('ad-dist-tbody');
        const tableContainer = document.getElementById('ad-dist-table-container');
        const instLabel = document.getElementById('ad-dist-instr-label');

        tbody.innerHTML = '';

        // Remover mensaje previo de abono a nivel de empresa si existe
        const msgEmpresa = document.getElementById('ad-empresa-abono-msg');
        if (msgEmpresa) msgEmpresa.remove();

        if (this.filas.length === 0) {
            // Caso: sin clientes -> Anticipo general a nivel de empresa
            const totalValor = parseInt(document.getElementById('ad-valor').value || 0);
            tableContainer.style.display = 'none';
            if (instLabel) instLabel.style.display = 'none';

            const infoDiv = document.createElement('div');
            infoDiv.id = 'ad-empresa-abono-msg';
            infoDiv.style.cssText = 'padding: 1.5rem; background: #fffbeb; border: 1.5px dashed #d97706; border-radius: 12px; text-align: center; color: #92400e; margin-bottom: 0.9rem;';
            infoDiv.innerHTML = `
                <div style="font-size: 2.2rem; margin-bottom: .4rem;">🏢</div>
                <h4 style="margin: 0 0 .3rem 0; font-weight: 800; font-size: .85rem; text-transform: uppercase; letter-spacing: .02em;">Anticipo Libre de Empresa</h4>
                <p style="font-size: .78rem; margin: 0; line-height: 1.4; color: #b45309; max-width: 480px; margin-inline: auto;">
                    Este abono no se distribuirá entre clientes individuales. El monto total de <b>${this.fmt(totalValor)}</b> quedará disponible como saldo a favor de la empresa para ser aplicado en facturaciones generales.
                </p>
            `;
            tableContainer.parentNode.insertBefore(infoDiv, tableContainer);
            this.recalcularSuma();
            return;
        }

        tableContainer.style.display = 'block';
        if (instLabel) instLabel.style.display = 'block';

        const anioActual = new Date().getFullYear();

        this.filas.forEach((c) => {
            const periodo = this.periodoDe(c);
            const monto = this.montos[c.id] || 0;

            let selectMesesHtml = `<select class="ad-dist-period-select" id="ad-periodo-mes-${c.id}" onchange="MAD.setPeriodo(${c.id}, 'mes', this.value)">`;
            for (let m = 1; m <= 12; m++) {
                selectMesesHtml += `<option value="${m}" ${m === periodo.mes ? 'selected' : ''}>${this.meses[m]}</option>`;
            }
            selectMesesHtml += `</select>`;

            let selectAniosHtml = `<select class="ad-dist-period-select" id="ad-periodo-anio-${c.id}" onchange="MAD.setPeriodo(${c.id}, 'anio', this.value)">`;
            for (let a = anioActual - 1; a <= anioActual + 2; a++) {
                selectAniosHtml += `<option value="${a}" ${a === periodo.anio ? 'selected' : ''}>${a}</option>`;
            }
            selectAniosHtml += `</select>`;

            const tr = document.createElement('tr');
            if (c._nueva) tr.className = 'ad-row-nueva';
            tr.innerHTML = `
                <td style="font-weight: 700; color: #1e293b;">
                    ${c.cliente_nombre}
                    ${c._nueva ? '<span style="font-size:.58rem; font-weight:800; color:#b45309; background:#fef3c7; padding:.05rem .3rem; border-radius:4px; margin-left:.3rem;">PEGADO</span>' : ''}
                </td>
                <td style="color: #64748b;">${c.cedula}</td>
                <td style="color: #475569; font-weight: 600;">${c.plan_nombre}</td>
                <td>
                    <div style="display: flex; gap: .3rem;">
                        ${selectMesesHtml}
                        ${selectAniosHtml}
                    </div>
                </td>
                <td style="text-align: right; white-space: nowrap;">
                    <span style="font-size: .8rem; font-weight: 700; color: #92400e; margin-right: .3rem;">$</span>
                    <input type="number" class="ad-dist-val-input" id="ad-dist-val-${c.id}" data-contrato="${c.id}" value="${monto}" min="0" oninput="MAD.setMonto(${c.id}, this.value)">
                </td>
                <td style="text-align: center;">
                    <button type="button" class="ad-row-del" title="Quitar de la distribución" onclick="MAD.quitarFila(${c.id})">✕</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        this.recalcularSuma();
    },

    periodoDe: function(c) {
        if (!this.periodos[c.id]) {
            this.periodos[c.id] = { mes: c.periodo_mes, anio: c.periodo_anio };
        }
        return this.periodos[c.id];
    },

    setPeriodo: function(contratoId, campo, valor) {
        const c = this.filas.find(f => parseInt(f.id) === parseInt(contratoId));
        if (!c) return;
        this.periodoDe(c)[campo] = parseInt(valor);
    },

    setMonto: function(contratoId, valor) {
        this.montos[contratoId] = parseInt(valor || 0);
        this.tocado = true;
        this.recalcularSuma();
    },

    quitarFila: function(contratoId) {
        this.filas = this.filas.filter(c => parseInt(c.id) !== parseInt(contratoId));
        delete this.montos[contratoId];
        this.tocado = true;
        this.render();
    },

    totalManual: function() {
        // Si el usuario escribe el total a mano, deja de calcularse solo
        this.totalAuto = false;
        const chk = document.getElementById('ad-total-auto-chk');
        if (chk) chk.checked = false;
        if (this.pasoActivo === 3) this.recalcularSuma();
    },

    toggleTotalAuto: function(activo) {
        this.totalAuto = !!activo;
        this.recalcularSuma();
    },

    recalcularSuma: function() {
        const valorInput = document.getElementById('ad-valor');
        let suma = 0;
        this.filas.forEach(c => { suma += (this.montos[c.id] || 0); });

        // Con total automático, el valor del anticipo es la suma distribuida
        if (this.totalAuto && this.filas.length > 0) {
            valorInput.value = suma;
        }

        const totalValor = parseInt(valorInput.value || 0);
        const diferencia = totalValor - suma;

        document.getElementById('ad-bal-total').innerText = this.fmt(totalValor);
        document.getElementById('ad-bal-suma').innerText = this.fmt(this.filas.length === 0 ? 0 : suma);

        const restanteEl = document.getElementById('ad-bal-restante');
        const btnRegistrar = document.getElementById('ad-btn-registrar');

        // Sin clientes en la tabla: abono libre de empresa, solo exige un total válido
        if (this.filas.length === 0) {
            restanteEl.innerText = this.fmt(0);
            restanteEl.className = 'ad-balance-val match';
            if (totalValor > 0) {
                btnRegistrar.removeAttribute('disabled');
            } else {
                btnRegistrar.setAttribute('disabled', 'disabled');
            }
            return;
        }

        if (diferencia === 0 && totalValor > 0) {
            restanteEl.innerText = this.fmt(0);
            restanteEl.className = 'ad-balance-val match';
            btnRegistrar.removeAttribute('disabled');
        } else {
            restanteEl.innerText = (diferencia > 0 ? '+' : '') + this.fmt(diferencia);
            restanteEl.className = 'ad-balance-val diff';
            btnRegistrar.setAttribute('disabled', 'disabled');
        }
    },

    // ═══════════════════════════════════════════════════════════
    //  Pegado desde Excel: cédula + valor
    // ═══════════════════════════════════════════════════════════

    togglePaste: function() {
        const body = document.getElementById('ad-paste-body');
        const caret = document.getElementById('ad-paste-caret');
        const abierto = body.style.display !== 'none';
        body.style.display = abierto ? 'none' : 'block';
        caret.innerText = abierto ? '▸' : '▾';
    },

    ocultarResultadoPegado: function() {
        const box = document.getElementById('ad-paste-result');
        if (box) {
            box.style.display = 'none';
            box.innerHTML = '';
        }
    },

    mostrarResultadoPegado: function(clase, html) {
        const box = document.getElementById('ad-paste-result');
        box.className = clase;
        box.innerHTML = html;
        box.style.display = 'block';
    },

    // Convierte un texto tipo "137.900" / "$ 137.900,00" a entero
    aNumero: function(token) {
        let t = String(token).replace(/[^\d.,-]/g, '');
        // Descartar decimales de 1 o 2 dígitos al final (137.900,00 → 137.900)
        t = t.replace(/[.,]\d{1,2}$/, '');
        t = t.replace(/[.,]/g, '');
        if (!/^\d+$/.test(t)) return null;
        return parseInt(t, 10);
    },

    // Lee el texto pegado y devuelve { items, errores }
    parsearPegado: function(texto) {
        const items = [];
        const errores = [];

        String(texto).split(/\r?\n/).forEach((lineaCruda) => {
            const linea = lineaCruda.trim();
            if (linea === '') return;

            // Separadores: tabulador, punto y coma, barra vertical o espacios
            const tokens = linea.split(/[\t;|]+|\s+/).map(t => t.trim()).filter(t => t !== '');

            // Tokens que son números (con o sin separador de miles)
            const numericos = [];
            tokens.forEach((t, i) => {
                const limpio = t.replace(/[$\s]/g, '');
                if (/^[\d.,]+$/.test(limpio)) {
                    const n = this.aNumero(limpio);
                    if (n !== null) numericos.push({ i: i, crudo: limpio, valor: n });
                }
            });

            // Encabezados tipo "Cédula   Valor": sin números, se ignoran en silencio
            if (numericos.length === 0) return;

            if (numericos.length < 2) {
                errores.push(linea + '  (falta el valor)');
                return;
            }

            const cedulaTok = numericos[0];
            const valorTok  = numericos[numericos.length - 1];
            const cedula = cedulaTok.crudo.replace(/[.,]/g, '');

            if (!/^\d{4,15}$/.test(cedula)) {
                errores.push(linea + '  (cédula no reconocida)');
                return;
            }
            if (valorTok.valor <= 0) {
                errores.push(linea + '  (valor en cero)');
                return;
            }

            items.push({ cedula: cedula, valor: valorTok.valor, linea: linea });
        });

        return { items: items, errores: errores };
    },

    // Busca el contrato de una cédula dentro de los contratos de la empresa
    contratoDeCedula: function(cedula) {
        const objetivo = this.normCedula(cedula);
        const candidatos = this.contratos.filter(c => this.normCedula(c.cedula) === objetivo);
        if (candidatos.length === 0) return null;
        if (candidatos.length === 1) return candidatos[0];

        // Varios contratos para la misma cédula: manda el que sigue vigente
        // sobre el retirado y, entre los vigentes, el más nuevo (por fecha de
        // ingreso y, si empatan, el contrato creado de último).
        const vigente = (c) => ['vigente', 'activo'].includes(String(c.estado || '').toLowerCase()) ? 1 : 0;

        const ordenados = candidatos.slice().sort((a, b) => {
            if (vigente(a) !== vigente(b)) return vigente(b) - vigente(a);
            const fa = a.fecha_ingreso || '';
            const fb = b.fecha_ingreso || '';
            if (fa !== fb) return fa < fb ? 1 : -1;
            return parseInt(b.id) - parseInt(a.id);
        });

        return { contrato: ordenados[0], ambiguo: true, total: candidatos.length };
    },

    aplicarPegado: function() {
        const texto = document.getElementById('ad-paste-text').value;
        if (!texto.trim()) {
            this.mostrarResultadoPegado('err', 'No hay nada pegado en el cuadro.');
            return;
        }

        const { items, errores } = this.parsearPegado(texto);
        if (items.length === 0) {
            this.mostrarResultadoPegado('err',
                '<b>No se pudo leer ninguna línea.</b> Se esperan dos columnas: cédula y valor.' +
                (errores.length ? '<br>' + errores.slice(0, 5).map(e => '· ' + e).join('<br>') : ''));
            return;
        }

        const deltas = {};       // { contrato_id: suma a aplicar }
        const noEncontradas = [];
        const ambiguas = [];

        items.forEach(item => {
            const res = this.contratoDeCedula(item.cedula);
            if (!res) {
                noEncontradas.push(item.cedula);
                return;
            }
            const contrato = res.contrato || res;
            if (res.ambiguo) {
                ambiguas.push(item.cedula + ' (' + res.total + ' contratos, se tomó: '
                    + contrato.plan_nombre
                    + (contrato.fecha_ingreso ? ', ingreso ' + contrato.fecha_ingreso : '')
                    + ', ' + contrato.estado + ')');
            }
            // Cédulas repetidas dentro de la misma pegada se suman entre sí
            deltas[contrato.id] = (deltas[contrato.id] || 0) + item.valor;
        });

        const ids = Object.keys(deltas);
        if (ids.length === 0) {
            this.mostrarResultadoPegado('err',
                '<b>Ninguna cédula pegada tiene contrato en esta empresa.</b><br>' +
                noEncontradas.slice(0, 10).map(c => '· ' + c).join('<br>') +
                (noEncontradas.length > 10 ? '<br>… y ' + (noEncontradas.length - 10) + ' más' : ''));
            return;
        }

        // La primera pegada arranca de cero: descarta el reparto por partes iguales
        if (!this.tocado) {
            this.filas = this.obtenerSeleccionados().slice();
            this.montos = {};
            this.filas.forEach(c => { this.montos[c.id] = 0; });
        }

        const nuevos = [];
        let sumaAplicada = 0;

        ids.forEach(id => {
            const yaEsta = this.filas.some(c => parseInt(c.id) === parseInt(id));
            if (!yaEsta) {
                const contrato = this.contratos.find(c => parseInt(c.id) === parseInt(id));
                if (!contrato) return;
                contrato._nueva = true;
                this.filas.push(contrato);
                nuevos.push(parseInt(id));
            }
            this.montos[id] = (this.montos[id] || 0) + deltas[id];
            sumaAplicada += deltas[id];
        });

        this.ultimaPegada = { deltas: deltas, nuevos: nuevos };
        this.tocado = true;
        this.totalAuto = true;
        const chkAuto = document.getElementById('ad-total-auto-chk');
        if (chkAuto) chkAuto.checked = true;

        document.getElementById('ad-btn-deshacer').style.display = 'inline-block';
        this.render();

        // ── Resumen de lo que pasó ──
        let sumaTabla = 0;
        this.filas.forEach(c => { sumaTabla += (this.montos[c.id] || 0); });

        let html = '<b>✓ ' + items.length + ' línea' + (items.length === 1 ? '' : 's') + ' aplicada'
                 + (items.length === 1 ? '' : 's') + '</b> sobre ' + ids.length + ' persona'
                 + (ids.length === 1 ? '' : 's') + ': +' + this.fmt(sumaAplicada)
                 + '. Total en la tabla: <b>' + this.fmt(sumaTabla) + '</b>.';

        let clase = 'ok';
        if (noEncontradas.length) {
            clase = 'warn';
            html += '<br><b>⚠️ Sin contrato en esta empresa (' + noEncontradas.length + '):</b> '
                 + noEncontradas.slice(0, 10).join(', ')
                 + (noEncontradas.length > 10 ? ' … y ' + (noEncontradas.length - 10) + ' más' : '');
        }
        if (ambiguas.length) {
            clase = 'warn';
            html += '<br><b>⚠️ Cédulas con más de un contrato:</b> ' + ambiguas.join(' · ');
        }
        if (errores.length) {
            clase = 'warn';
            html += '<br><b>⚠️ Líneas ignoradas (' + errores.length + '):</b><br>'
                 + errores.slice(0, 5).map(e => '· ' + e).join('<br>');
        }

        this.mostrarResultadoPegado(clase, html);
        document.getElementById('ad-paste-text').value = '';
    },

    deshacerPegado: function() {
        if (!this.ultimaPegada) return;

        Object.keys(this.ultimaPegada.deltas).forEach(id => {
            this.montos[id] = (this.montos[id] || 0) - this.ultimaPegada.deltas[id];
            if (this.montos[id] < 0) this.montos[id] = 0;
        });

        // Las filas que entraron con esa pegada se retiran de la tabla
        this.ultimaPegada.nuevos.forEach(id => {
            this.filas = this.filas.filter(c => parseInt(c.id) !== parseInt(id));
            delete this.montos[id];
        });

        this.ultimaPegada = null;
        document.getElementById('ad-btn-deshacer').style.display = 'none';
        this.render();
        this.mostrarResultadoPegado('ok', 'Se deshizo la última pegada.');
    },

    limpiarValores: function() {
        this.filas.forEach(c => { this.montos[c.id] = 0; });
        this.ultimaPegada = null;
        this.tocado = true;
        document.getElementById('ad-btn-deshacer').style.display = 'none';
        this.render();
        this.mostrarResultadoPegado('ok', 'Todos los montos quedaron en cero.');
    },

    mostrarPreview: function(input) {
        const container = document.getElementById('ad-preview-container');
        const img = document.getElementById('ad-preview-img');
        const info = document.getElementById('ad-preview-fileinfo');
        
        if (input.files && input.files[0]) {
            const file = input.files[0];
            info.innerText = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
            
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    img.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                img.style.display = 'none';
                img.src = '';
            }
            container.style.display = 'block';
        } else {
            this.eliminarSoporte();
        }
    },

    eliminarSoporte: function() {
        const input = document.getElementById('ad-imagen');
        if (input) input.value = '';
        
        const container = document.getElementById('ad-preview-container');
        const img = document.getElementById('ad-preview-img');
        const info = document.getElementById('ad-preview-fileinfo');
        
        if (container) container.style.display = 'none';
        if (img) {
            img.src = '';
            img.style.display = 'none';
        }
        if (info) info.innerText = '';
    },

    registrar: function() {
        const totalValor = parseInt(document.getElementById('ad-valor').value || 0);
        const formEl = document.getElementById('ad-form');
        const formData = new FormData(formEl);
        const btnReg = document.getElementById('ad-btn-registrar');

        if (totalValor <= 0) {
            alert('El valor del anticipo debe ser mayor a cero.');
            return;
        }

        let endpoint = '/admin/anticipos';
        // Ya confirmó que un anticipo grande en efectivo sí fueron billetes.
        if (this._confirmoEfectivo) formData.append('confirmo_efectivo', '1');

        if (this.filas.length > 0) {
            endpoint = '/admin/anticipos/distribuir';
            let suma = 0;
            const distribucion = [];

            this.filas.forEach(c => {
                const valor = parseInt(this.montos[c.id] || 0);
                suma += valor;
                // Las personas que quedaron en cero no se registran como anticipo
                if (valor <= 0) return;
                const periodo = this.periodoDe(c);
                distribucion.push({
                    contrato_id: parseInt(c.id),
                    valor: valor,
                    periodo_mes: parseInt(periodo.mes),
                    periodo_anio: parseInt(periodo.anio)
                });
            });

            if (suma !== totalValor) {
                alert('La distribución no está balanceada. La diferencia debe ser cero.');
                return;
            }
            if (distribucion.length === 0) {
                alert('Todos los clientes quedaron en cero. Asigna al menos un monto.');
                return;
            }

            distribucion.forEach((item, index) => {
                formData.append(`distribucion[${index}][contrato_id]`, item.contrato_id);
                formData.append(`distribucion[${index}][valor]`, item.valor);
                formData.append(`distribucion[${index}][periodo_mes]`, item.periodo_mes);
                formData.append(`distribucion[${index}][periodo_anio]`, item.periodo_anio);
            });
        }

        // Deshabilitar botón
        btnReg.setAttribute('disabled', 'disabled');
        btnReg.innerText = 'Guardando...';

        fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.confirmar_efectivo) {
                btnReg.removeAttribute('disabled');
                btnReg.innerText = '✓ Registrar Anticipo';
                if (confirm(data.mensaje)) {
                    this._confirmoEfectivo = true;
                    this.registrar();
                }
                return;
            }
            this._confirmoEfectivo = false;
            if (data.ok && data.anticipo_id) {
                this.cerrar();
                
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Anticipo Guardado',
                        text: 'El anticipo ha sido guardado exitosamente.',
                        confirmButtonText: 'Ver Recibo',
                        confirmButtonColor: '#d97706'
                    }).then((result) => {
                        abrirRecibo(`/admin/anticipos/${data.anticipo_id}/recibo?modal=1`);
                    });
                } else {
                    alert('Anticipo registrado con éxito.');
                    abrirRecibo(`/admin/anticipos/${data.anticipo_id}/recibo?modal=1`);
                }
            } else {
                alert('Error: ' + (data.mensaje || 'No se pudo guardar el anticipo.'));
                btnReg.removeAttribute('disabled');
                btnReg.innerText = '✓ Registrar Anticipo';
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error de red al intentar registrar el anticipo.');
            btnReg.removeAttribute('disabled');
            btnReg.innerText = '✓ Registrar Anticipo';
        });
    }
};

// Captura de pegado (Ctrl + V) para imágenes de soporte
document.addEventListener('paste', function(e) {
    const overlay = document.getElementById('ad-overlay');
    if (!overlay || overlay.style.display !== 'flex') return;
    if (MAD.pasoActivo !== 1) return;

    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
    for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
            const blob = items[i].getAsFile();
            if (!blob) continue;
            
            const file = new File([blob], "soporte_pegado_" + Date.now() + ".png", { type: blob.type });
            const fileInput = document.getElementById('ad-imagen');
            if (fileInput) {
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                MAD.mostrarPreview(fileInput);
            }
            e.preventDefault();
            break;
        }
    }
});
</script>
