{{--
    Modal «Corregir pago» del recibo (solo admin / superadmin).
    Cambia forma de pago, consignaciones y fecha de pago del recibo completo
    sin tocar el total pagado. Ver CorreccionPagoService.
    No usa id= repetidos: este partial se incluye una sola vez, fuera de
    _recibo_cuerpo (que sí se pinta dos veces en doble copia).
--}}
<div id="modalCorregirPago" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)cpCerrar()">
<div style="background:#fff;border-radius:12px;padding:1.4rem;max-width:620px;width:95%;max-height:92vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,.2);font-size:.82rem;color:#1f2937">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem">
        <h3 style="margin:0;color:#1e3a5f;font-size:1rem">💳 Corregir pago del recibo <span id="cp_recibo"></span></h3>
        <button onclick="cpCerrar()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#6b7280">&times;</button>
    </div>

    <div id="cp_cargando" style="padding:1.2rem;text-align:center;color:#64748b">⏳ Cargando…</div>
    <div id="cp_bloqueo" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:.6rem .8rem;margin-bottom:.8rem;color:#991b1b"></div>

    <div id="cp_form" style="display:none">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.55rem .8rem;margin-bottom:.85rem;color:#1e3a8a;line-height:1.45">
            Total pagado: <strong id="cp_total"></strong> <span id="cp_filas" style="color:#64748b"></span><br>
            <span style="font-size:.76rem">El total no cambia: solo cambia cómo y cuándo entró la plata. El saldo, la mora y la planilla quedan igual. Todo queda en la bitácora.</span>
        </div>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
            <label style="flex:1;min-width:150px">
                <span style="font-weight:700;display:block;margin-bottom:.25rem">Forma de pago</span>
                <select id="cp_forma" onchange="cpPintar()" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.4rem">
                    <option value="efectivo">Efectivo</option>
                    <option value="consignacion">Consignación</option>
                    <option value="mixto">Mixto (efectivo + consignación)</option>
                </select>
            </label>
            <label id="cp_wrap_efectivo" style="flex:1;min-width:150px">
                <span style="font-weight:700;display:block;margin-bottom:.25rem">Efectivo</span>
                <input id="cp_efectivo" type="number" min="0" step="1" oninput="cpSuma()" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.4rem;box-sizing:border-box">
            </label>
            <label id="cp_wrap_fecha" style="flex:1;min-width:150px">
                <span style="font-weight:700;display:block;margin-bottom:.25rem">Fecha de pago</span>
                <input id="cp_fecha" type="date" max="{{ now()->toDateString() }}" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.38rem;box-sizing:border-box">
            </label>
        </div>
        <div id="cp_nota_fecha" style="display:none;color:#64748b;font-size:.76rem;margin:-.35rem 0 .7rem">
            Con pago solo por consignación, la fecha de pago es la de la consignación más reciente.
        </div>

        <div id="cp_wrap_consig" style="margin-bottom:.75rem">
            <div style="font-weight:700;margin-bottom:.3rem">Consignaciones</div>
            <div id="cp_consigs"></div>
            <button type="button" class="btn-a" onclick="cpAgregar()" style="background:#f1f5f9;color:#1e3a5f;margin-top:.3rem">+ Agregar consignación</button>
        </div>

        <div id="cp_suma" style="font-weight:700;margin-bottom:.75rem"></div>

        <label style="display:block;margin-bottom:.8rem">
            <span style="font-weight:700;display:block;margin-bottom:.25rem">Motivo <span style="color:#dc2626">*</span></span>
            <textarea id="cp_motivo" rows="2" maxlength="200" placeholder="Ej: el cliente pagó por transferencia, se registró como efectivo" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.42rem .6rem;resize:vertical;box-sizing:border-box"></textarea>
        </label>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:.5rem">
        <button class="btn-a" style="background:#f1f5f9;color:#475569" onclick="cpCerrar()">Cancelar</button>
        <button class="btn-a" style="background:#1e3a5f;color:#fff" id="cp_guardar" onclick="cpGuardar()" disabled>💾 Guardar corrección</button>
    </div>
</div>
</div>

<script>
const CP_URL = '{{ route('admin.facturacion.corregir_pago.show', $factura->id) }}';
let cpDatos = null;
let cpConsigs = [];

const cpPesos = v => '$' + Math.round(v || 0).toLocaleString('es-CO');
const cpEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function cpCerrar() { document.getElementById('modalCorregirPago').style.display = 'none'; }

async function abrirCorregirPago() {
    document.getElementById('modalCorregirPago').style.display = 'flex';
    document.getElementById('cp_cargando').style.display = 'block';
    document.getElementById('cp_form').style.display = 'none';
    document.getElementById('cp_bloqueo').style.display = 'none';
    document.getElementById('cp_guardar').disabled = true;
    try {
        const res = await fetch(CP_URL, { headers: { 'Accept': 'application/json' } });
        const d = await res.json();
        document.getElementById('cp_cargando').style.display = 'none';
        if (!d.ok) { cpBloqueo(d.mensaje || 'No se pudo cargar el pago.'); return; }
        cpDatos = d;
        cpConsigs = d.consignaciones.map(c => ({ ...c }));
        document.getElementById('cp_recibo').textContent = d.numero_factura ? '#' + d.numero_factura : '';
        document.getElementById('cp_total').textContent = cpPesos(d.total_pagado);
        document.getElementById('cp_filas').textContent = d.filas > 1 ? `· ${d.filas} filas` : '';
        document.getElementById('cp_forma').value = ['efectivo','consignacion','mixto'].includes(d.forma_pago) ? d.forma_pago : 'efectivo';
        document.getElementById('cp_efectivo').value = d.efectivo;
        document.getElementById('cp_fecha').value = d.fecha_pago || '';
        document.getElementById('cp_motivo').value = '';
        if (d.bloqueo) { cpBloqueo(d.bloqueo); return; }
        document.getElementById('cp_form').style.display = 'block';
        document.getElementById('cp_guardar').disabled = false;
        cpPintar();
    } catch (e) {
        document.getElementById('cp_cargando').style.display = 'none';
        cpBloqueo('Error de conexión.');
    }
}

function cpBloqueo(msg) {
    const b = document.getElementById('cp_bloqueo');
    b.textContent = '🔒 ' + msg;
    b.style.display = 'block';
}

function cpPintar() {
    const forma = document.getElementById('cp_forma').value;
    document.getElementById('cp_wrap_consig').style.display = forma === 'efectivo' ? 'none' : 'block';
    document.getElementById('cp_wrap_efectivo').style.display = forma === 'mixto' ? 'block' : 'none';
    document.getElementById('cp_wrap_fecha').style.display = forma === 'consignacion' ? 'none' : 'block';
    document.getElementById('cp_nota_fecha').style.display = forma === 'consignacion' ? 'block' : 'none';
    if (forma !== 'efectivo' && !cpConsigs.length) cpAgregar(false);

    const opciones = id => cpDatos.cuentas.map(b =>
        `<option value="${b.id}" ${+b.id === +id ? 'selected' : ''}>${cpEsc(b.nombre)}</option>`).join('');
    const est = 'border:1px solid #d1d5db;border-radius:6px;padding:.32rem;box-sizing:border-box';
    document.getElementById('cp_consigs').innerHTML = cpConsigs.map((c, i) => c.bloqueada
        ? `<div style="display:flex;gap:.4rem;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:.35rem .5rem;margin-bottom:.35rem">
              🔒 <span style="flex:1">${cpEsc((cpDatos.cuentas.find(b => +b.id === +c.banco_cuenta_id) || {}).nombre || 'Cuenta')} · ${cpEsc(c.fecha)} · <b>${cpPesos(c.valor)}</b> ${c.referencia ? '· ' + cpEsc(c.referencia) : ''}</span>
              <span style="color:#64748b;font-size:.72rem">validada con el banco</span>
           </div>`
        : `<div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.35rem">
              <select style="${est};flex:2;min-width:150px" onchange="cpConsigs[${i}].banco_cuenta_id=this.value">
                  <option value="">— Cuenta —</option>${opciones(c.banco_cuenta_id)}
              </select>
              <input type="date" max="{{ now()->toDateString() }}" value="${cpEsc(c.fecha)}" style="${est};flex:1;min-width:120px" onchange="cpConsigs[${i}].fecha=this.value">
              <input type="number" min="0" step="1" value="${c.valor || ''}" placeholder="Valor" style="${est};flex:1;min-width:100px" oninput="cpConsigs[${i}].valor=+this.value||0;cpSuma()">
              <input type="text" maxlength="100" value="${cpEsc(c.referencia)}" placeholder="Referencia" style="${est};flex:1;min-width:100px" onchange="cpConsigs[${i}].referencia=this.value">
              <button type="button" onclick="cpConsigs.splice(${i},1);cpPintar()" title="Quitar" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:6px;padding:0 .55rem;cursor:pointer">✕</button>
           </div>`).join('');
    cpSuma();
}

function cpAgregar(repintar = true) {
    const forma = document.getElementById('cp_forma').value;
    const yaConsig = cpConsigs.reduce((s, c) => s + (+c.valor || 0), 0);
    const efectivo = forma === 'mixto' ? (+document.getElementById('cp_efectivo').value || 0) : 0;
    const falta = Math.max(0, cpDatos.total_pagado - efectivo - yaConsig);
    cpConsigs.push({ id: null, banco_cuenta_id: '', fecha: document.getElementById('cp_fecha').value || '', valor: falta, referencia: '', bloqueada: false });
    if (repintar) cpPintar();
}

function cpSuma() {
    const forma = document.getElementById('cp_forma').value;
    const consig = forma === 'efectivo' ? 0 : cpConsigs.reduce((s, c) => s + (+c.valor || 0), 0);
    const efectivo = forma === 'efectivo' ? cpDatos.total_pagado
                   : forma === 'mixto' ? (+document.getElementById('cp_efectivo').value || 0) : 0;
    const suma = consig + efectivo;
    const el = document.getElementById('cp_suma');
    const dif = cpDatos.total_pagado - suma;
    el.innerHTML = `Efectivo ${cpPesos(efectivo)} + consignado ${cpPesos(consig)} = ${cpPesos(suma)} ` +
        (dif === 0 ? '<span style="color:#15803d">✓ cuadra</span>'
                   : `<span style="color:#b91c1c">${dif > 0 ? 'faltan' : 'sobran'} ${cpPesos(Math.abs(dif))}</span>`);
    return dif === 0;
}

async function cpGuardar() {
    const forma = document.getElementById('cp_forma').value;
    const motivo = document.getElementById('cp_motivo').value.trim();
    if (!cpSuma()) { alert('El pago debe sumar exactamente ' + cpPesos(cpDatos.total_pagado) + '.'); return; }
    if (motivo.length < 5) { alert('Escribe el motivo de la corrección.'); return; }
    const consigs = forma === 'efectivo' ? [] : cpConsigs.filter(c => (+c.valor || 0) > 0);
    if (consigs.some(c => !c.banco_cuenta_id || !c.fecha)) { alert('Cada consignación necesita cuenta y fecha.'); return; }

    const btn = document.getElementById('cp_guardar');
    btn.disabled = true; btn.textContent = '⏳ Guardando…';
    try {
        const res = await fetch(CP_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_REC },
            body: JSON.stringify({
                forma_pago: forma,
                fecha_pago: document.getElementById('cp_fecha').value || null,
                valor_efectivo: forma === 'mixto' ? (+document.getElementById('cp_efectivo').value || 0) : 0,
                consignaciones: consigs.map(c => ({ id: c.id, banco_cuenta_id: +c.banco_cuenta_id, fecha: c.fecha, valor: +c.valor, referencia: c.referencia || null })),
                motivo,
            }),
        });
        const d = await res.json();
        if (d.ok) {
            alert(d.mensaje);
            location.reload();
            return;
        }
        const errores = d.errors ? Object.values(d.errors).flat().join('\n') : '';
        alert(d.mensaje || errores || d.message || 'No se pudo corregir el pago.');
    } catch (e) {
        alert('Error de conexión.');
    }
    btn.disabled = false; btn.textContent = '💾 Guardar corrección';
}
</script>
