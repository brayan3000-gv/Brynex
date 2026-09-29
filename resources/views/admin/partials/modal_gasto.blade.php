{{--
  Componente compartido: Modal de Registro de Gasto
  Parámetros requeridos:
    $formAction  — URL del POST
    $bancos      — colección BancoCuenta activos
    $esAdmin     — bool
    $usuarios    — colección User del aliado
  Opcionales:
    $modalId     — ID del div (default: 'modal-gasto')
    $imagenPaste — bool: zona paste/drag imagen
    $fechaDefault— fecha preseleccionada (default: hoy)
--}}
@php
    $modalId    = $modalId    ?? 'modal-gasto';
    $imagenPaste= $imagenPaste ?? false;
    $fechaDefault = $fechaDefault ?? today()->toDateString();
    $tiposGrupos= \App\Models\Gasto::TIPOS_GRUPOS;
    $tiposNomina= \App\Models\Gasto::TIPOS_NOMINA;
    $tiposAdmin = \App\Models\Gasto::TIPOS_ADMIN;
    $allTipos   = \App\Models\Gasto::TIPOS;
    $usuarios   = $usuarios ?? collect();
@endphp

<div id="{{ $modalId }}"
     onclick="if(event.target.id==='{{ $modalId }}')this.style.display='none'"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:14px;width:min(580px,96vw);max-height:92vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.3)">

        {{-- Header --}}
        <div style="background:#0f172a;padding:.8rem 1.1rem;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center">
            <span style="color:#fff;font-weight:700">💼 Registrar Gasto</span>
            <button onclick="document.getElementById('{{ $modalId }}').style.display='none'"
                    style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:5px;width:26px;height:26px;cursor:pointer;font-weight:700">×</button>
        </div>

        {{-- Form --}}
        <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data"
              style="padding:1.2rem;display:flex;flex-direction:column;gap:.9rem"
              id="{{ $modalId }}-form">
            @csrf
            @if($imagenPaste)
            <input type="hidden" name="imagen_base64" id="{{ $modalId }}-base64">
            @endif

            {{-- Fila: Fecha + Tipo --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem">
                <div>
                    <label class="lbl-g">Fecha *</label>
                    <input type="date" name="fecha" value="{{ $fechaDefault }}" required class="inp-g">
                </div>
                <div>
                    <label class="lbl-g">Tipo *</label>
                    <select name="tipo" id="{{ $modalId }}-tipo"
                            onchange="gasto_onTipoChange('{{ $modalId }}')" required class="inp-g">
                        <option value="" disabled selected>— Seleccionar tipo —</option>
                        @foreach($tiposGrupos as $grupo => $claves)
                        <optgroup label="{{ $grupo }}">
                            @foreach($claves as $k)
                                @php $v = $allTipos[$k] ?? $k; @endphp
                                @if(!in_array($k, $tiposAdmin) || $esAdmin)
                                <option value="{{ $k }}">{{ $v }}</option>
                                @endif
                            @endforeach
                        </optgroup>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Descripción --}}
            <div>
                <label class="lbl-g">Descripción *</label>
                <input type="text" name="descripcion" required placeholder="Ej: Compra resma de papel" class="inp-g">
            </div>

            {{-- Select de usuario (visible solo para tipos Nómina) --}}
            <div id="{{ $modalId }}-blq-usuario" style="display:none">
                <label class="lbl-g">👤 Empleado / Usuario</label>
                <select id="{{ $modalId }}-sel-usuario" class="inp-g"
                        onchange="gasto_seleccionarUsuario('{{ $modalId }}')">
                    <option value="">— Seleccionar usuario —</option>
                    @foreach($usuarios as $u)
                    <option value="{{ $u->nombre }}">{{ $u->nombre }}</option>
                    @endforeach
                    <option value="__otro__">✏️ Escribir manualmente…</option>
                </select>
            </div>

            {{-- Fila: Pagado a + Valor --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem">
                <div>
                    <label class="lbl-g" id="{{ $modalId }}-lbl-pagado">Pagado a</label>
                    <input type="text" name="pagado_a" id="{{ $modalId }}-pagado-a"
                           placeholder="Nombre o beneficiario" class="inp-g">
                </div>
                <div>
                    <label class="lbl-g">Valor *</label>
                    <input type="number" name="valor" required min="1" placeholder="0" class="inp-g">
                </div>
            </div>

            {{-- Forma de pago --}}
            <div>
                <label class="lbl-g">Forma de Pago *</label>
                <select name="forma_pago" id="{{ $modalId }}-forma"
                        onchange="gasto_actualizarBancos('{{ $modalId }}')" required class="inp-g">
                    <option value="efectivo">💵 Efectivo</option>
                    @if($esAdmin)
                    <option value="transferencia_bancaria">🏦 Transferencia Bancaria</option>
                    <option value="banco_banco">💱 Banco → Banco</option>
                    @endif
                </select>
            </div>

            {{-- Banco Origen (solo transferencia/banco_banco/efectivo_banco) --}}
            <div id="{{ $modalId }}-banco-origen" style="display:none">
                <label class="lbl-g" id="{{ $modalId }}-lbl-banco-origen">Banco Origen</label>
                <select name="banco_origen_id" id="{{ $modalId }}-sel-banco-origen" class="inp-g">
                    <option value="">— Seleccionar —</option>
                    @foreach($bancos as $bc)
                    <option value="{{ $bc->id }}">{{ $bc->banco }} · {{ $bc->nombre }}{{ $bc->numero_cuenta ? ' · '.$bc->numero_cuenta : '' }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Banco Destino (visible solo para banco_banco y efectivo_banco) --}}
            <div id="{{ $modalId }}-banco-destino" style="display:none">
                <label class="lbl-g" id="{{ $modalId }}-lbl-banco-destino">Banco Destino</label>
                <select name="banco_destino_id" id="{{ $modalId }}-sel-banco-destino" class="inp-g">
                    <option value="">— Seleccionar —</option>
                    @foreach($bancos as $bc)
                    <option value="{{ $bc->id }}">{{ $bc->banco }} · {{ $bc->nombre }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Recibo --}}
            <div>
                <label class="lbl-g">Recibo de caja</label>
                <input type="text" name="recibo_caja" placeholder="Opcional" class="inp-g">
            </div>

            {{-- Observación --}}
            <div>
                <label class="lbl-g">Observación</label>
                <textarea name="observacion" rows="2" placeholder="Opcional" class="inp-g" style="resize:vertical"></textarea>
            </div>

            {{-- Zona de imagen --}}
            @if($imagenPaste)
            <div>
                <label class="lbl-g">Comprobante / Soporte</label>
                <div id="{{ $modalId }}-paste-zone"
                     style="border:2px dashed #cbd5e1;border-radius:10px;padding:1rem;text-align:center;cursor:pointer;background:#f8fafc;min-height:80px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.4rem;transition:.2s"
                     onclick="document.getElementById('{{ $modalId }}-file').click()"
                     ondragover="event.preventDefault();this.style.borderColor='#2563eb'"
                     ondragleave="this.style.borderColor='#cbd5e1'"
                     ondrop="gastoOnDrop(event,'{{ $modalId }}')">
                    <div style="font-size:1.3rem">📎</div>
                    <p style="font-size:.75rem;color:#64748b;margin:0">Pega imagen (Ctrl+V) o arrastra aquí</p>
                    <p style="font-size:.68rem;color:#94a3b8;margin:0">Clic para seleccionar archivo</p>
                </div>
                <input type="file" id="{{ $modalId }}-file" name="imagen"
                       accept="image/*,application/pdf" style="display:none"
                       onchange="gastoOnFile(this,'{{ $modalId }}')">
            </div>
            @endif

            <button type="submit"
                    style="background:#065f46;color:#fff;border:none;border-radius:8px;padding:.6rem;font-size:.88rem;font-weight:700;cursor:pointer">
                ✅ Registrar Gasto
            </button>
        </form>
    </div>
</div>

<style>
.lbl-g{font-size:.76rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem}
.inp-g{width:100%;padding:.4rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.82rem;box-sizing:border-box;font-family:inherit}
optgroup{font-weight:700;color:#374151}
</style>

<script>
// Tipos de nómina (sincronizan con PHP)
const _TIPOS_NOMINA = @json(\App\Models\Gasto::TIPOS_NOMINA);
const _TIPOS_MOVIM  = ['efectivo_banco','banco_banco'];

function gasto_onTipoChange(id) {
    const tipo = document.getElementById(id+'-tipo').value;
    const blqUsr = document.getElementById(id+'-blq-usuario');
    const lblPag = document.getElementById(id+'-lbl-pagado');

    // Mostrar select usuario si es nómina
    if (_TIPOS_NOMINA.includes(tipo)) {
        blqUsr.style.display = 'block';
        if (lblPag) lblPag.textContent = 'Pagado a (editable)';
    } else {
        blqUsr.style.display = 'none';
        if (lblPag) lblPag.textContent = 'Pagado a';
    }

    // Auto-forma de pago para movimientos bancarios
    const forma = document.getElementById(id+'-forma');
    if (tipo === 'efectivo_banco') {
        // Efectivo→Banco: forma de pago = efectivo, muestra solo banco destino (a cuál banco va)
        forma.value = 'efectivo';
        gasto_actualizarBancos(id);
    } else if (tipo === 'banco_banco') {
        // Banco→Banco: asignar forma_pago = banco_banco (tiene opción dedicada)
        if (forma.querySelector('option[value="banco_banco"]')) {
            forma.value = 'banco_banco';
        } else if (forma.querySelector('option[value="transferencia_bancaria"]')) {
            forma.value = 'transferencia_bancaria';
        }
        gasto_actualizarBancos(id);
    }
}

function gasto_seleccionarUsuario(id) {
    const sel  = document.getElementById(id+'-sel-usuario');
    const inp  = document.getElementById(id+'-pagado-a');
    if (!inp) return;
    if (sel.value === '__otro__') {
        inp.value = '';
        inp.focus();
    } else if (sel.value) {
        inp.value = sel.value;
    }
}

function gasto_actualizarBancos(id) {
    const fp   = document.getElementById(id+'-forma').value;
    const tipo = document.getElementById(id+'-tipo')?.value ?? '';

    const bloqOrigen  = document.getElementById(id+'-banco-origen');
    const bloqDestino = document.getElementById(id+'-banco-destino');
    const lblOrigen   = document.getElementById(id+'-lbl-banco-origen');
    const lblDestino  = document.getElementById(id+'-lbl-banco-destino');

    if (fp === 'banco_banco') {
        // Banco → Banco: mostrar ORIGEN y DESTINO
        if (bloqOrigen)  bloqOrigen.style.display  = 'block';
        if (bloqDestino) bloqDestino.style.display = 'block';
        if (lblOrigen)   lblOrigen.textContent  = 'Banco Origen (sale el dinero)';
        if (lblDestino)  lblDestino.textContent = 'Banco Destino (entra el dinero)';
    } else if (fp === 'transferencia_bancaria' || fp === 'transferencia') {
        // Pago de gasto por transferencia: solo banco origen
        // ('transferencia' es la que guarda Planos SS en los pagos de planilla)
        if (bloqOrigen)  bloqOrigen.style.display  = 'block';
        if (bloqDestino) bloqDestino.style.display = 'none';
        if (lblOrigen)   lblOrigen.textContent = 'Banco Origen';
    } else if (fp === 'efectivo' && tipo === 'efectivo_banco') {
        // Efectivo → Banco: solo banco destino (a cuál banco ingresa el efectivo)
        if (bloqOrigen)  bloqOrigen.style.display  = 'block';
        if (bloqDestino) bloqDestino.style.display = 'none';
        if (lblOrigen)   lblOrigen.textContent = 'Banco que recibe el efectivo';
    } else {
        // Gasto en efectivo ordinario: sin bancos
        if (bloqOrigen)  bloqOrigen.style.display  = 'none';
        if (bloqDestino) bloqDestino.style.display = 'none';
    }
}

// Aliases para cuadre-diario (compatibilidad)
function actualizarBancos()  { gasto_actualizarBancos('modal-gasto'); }
function actualizarFormPago(){ gasto_onTipoChange('modal-gasto'); }

// ── Imagen paste ─────────────────────────────────────────────────
document.addEventListener('paste', function(e) {
    // Buscar el modal visible
    const modales = document.querySelectorAll('[id$="-form"]');
    let activeId = null;
    modales.forEach(m => {
        const modal = document.getElementById(m.id.replace('-form',''));
        if (modal && modal.style.display === 'flex') activeId = m.id.replace('-form','');
    });
    if (!activeId) return;
    const zone = document.getElementById(activeId+'-paste-zone');
    if (!zone) return;
    const items = e.clipboardData?.items;
    if (!items) return;
    for (let item of items) {
        if (item.type.startsWith('image/')) {
            gastoMostrarPreview(item.getAsFile(), activeId);
            break;
        }
    }
});
function gastoOnDrop(e, id) {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (file) gastoMostrarPreview(file, id);
}
function gastoOnFile(input, id) {
    if (input.files[0]) gastoMostrarPreview(input.files[0], id);
}
function gastoMostrarPreview(blob, id) {
    const reader = new FileReader();
    reader.onload = function(ev) {
        const b64 = document.getElementById(id+'-base64');
        if (b64) b64.value = ev.target.result;
        const zone = document.getElementById(id+'-paste-zone');
        if (zone) {
            zone.style.borderColor = '#10b981';
            zone.innerHTML = `<img src="${ev.target.result}" style="max-height:100px;max-width:100%;border-radius:6px">
                              <p style="font-size:.7rem;color:#10b981;margin:0">✅ Imagen lista</p>`;
        }
    };
    reader.readAsDataURL(blob);
}
</script>
