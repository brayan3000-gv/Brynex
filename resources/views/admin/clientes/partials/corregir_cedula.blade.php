{{-- ═══════════════════════════════════════════════════════════════ --}}
{{--   MODAL: Corregir cédula                                        --}}
{{--   Cambia el número en la ficha y en todo lo que cuelga de él    --}}
{{--   (contratos, facturas, anticipos...). Ver CorregirCedulaService. --}}
{{-- ═══════════════════════════════════════════════════════════════ --}}
<div id="cc-modal"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1100;
            backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:16px;"
     onclick="if (event.target === this) CC_cerrar()">
    <div style="background:#fff;border-radius:14px;width:520px;max-width:100%;max-height:92vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,0.3);">
        <div style="background:linear-gradient(135deg,#1e40af,#2563eb);padding:0.9rem 1.2rem;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size:0.95rem;font-weight:800;color:#fff;">✏️ Corregir cédula</div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.8);">
                    {{ nombre_oracion(trim(($cliente->primer_nombre ?? '').' '.($cliente->primer_apellido ?? ''))) }} — actual {{ $cliente->tipo_doc ?: 'CC' }} {{ $cliente->cedula }}
                </div>
            </div>
            <button type="button" onclick="CC_cerrar()" style="background:rgba(255,255,255,0.2);border:none;border-radius:8px;width:32px;height:32px;color:#fff;font-size:1rem;cursor:pointer;font-weight:700;">✕</button>
        </div>

        <div style="padding:1rem 1.2rem;font-size:0.82rem;color:#0f172a;">
            <div id="cc-cargando" style="color:#64748b;">Revisando qué registros usan esta cédula…</div>

            <div id="cc-contenido" style="display:none;">
                <div style="font-weight:700;margin-bottom:0.35rem;">Se cambiará también en:</div>
                <ul id="cc-vinculados" style="margin:0 0 0.8rem 1.1rem;padding:0;color:#334155;"></ul>

                <div id="cc-avisos-caja" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:0.6rem 0.8rem;margin-bottom:0.8rem;">
                    <div style="font-weight:700;color:#92400e;margin-bottom:0.25rem;">⚠️ Ya salió con el número viejo (no se cambia aquí)</div>
                    <ul id="cc-avisos" style="margin:0 0 0 1.1rem;padding:0;color:#78350f;font-size:0.78rem;"></ul>
                </div>

                <label class="lbl-campo">Cédula correcta *</label>
                <input type="text" id="cc-nueva" inputmode="numeric" class="inp-campo" style="font-family:monospace;font-weight:700;margin-bottom:0.6rem;" autocomplete="off">

                <label class="lbl-campo">¿Por qué se corrige? *</label>
                <textarea id="cc-motivo" rows="2" class="inp-campo" placeholder="Ej.: se digitó mal, el documento dice 6203602"></textarea>

                <div id="cc-error" style="display:none;margin-top:0.6rem;padding:0.45rem 0.7rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#b91c1c;font-size:0.78rem;"></div>
            </div>
        </div>

        <div style="padding:0 1.2rem 1rem;display:flex;justify-content:flex-end;gap:0.5rem;">
            <button type="button" onclick="CC_cerrar()" style="padding:0.45rem 1rem;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;font-size:0.8rem;font-weight:600;color:#475569;cursor:pointer;">Cancelar</button>
            <button type="button" id="cc-btn" onclick="CC_guardar()" disabled style="padding:0.45rem 1.1rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;border-radius:8px;font-size:0.8rem;font-weight:700;color:#fff;cursor:pointer;opacity:0.6;">Corregir cédula</button>
        </div>
    </div>
</div>

<script>
function CC_abrir() {
    var modal = document.getElementById('cc-modal');
    modal.style.display = 'flex';
    document.getElementById('cc-cargando').style.display = '';
    document.getElementById('cc-contenido').style.display = 'none';
    document.getElementById('cc-error').style.display = 'none';
    var btn = document.getElementById('cc-btn');
    btn.disabled = true; btn.style.opacity = 0.6;

    fetch(@json(route('admin.clientes.cedula.impacto', $cliente->id)), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (d) {
            var ul = document.getElementById('cc-vinculados');
            ul.innerHTML = '';
            (d.vinculados.length ? d.vinculados : [{ etiqueta: 'la ficha solamente', n: '' }]).forEach(function (v) {
                var li = document.createElement('li');
                li.textContent = (v.n ? v.n + ' ' : '') + v.etiqueta;
                ul.appendChild(li);
            });
            var av = document.getElementById('cc-avisos');
            av.innerHTML = '';
            d.avisos.forEach(function (t) {
                var li = document.createElement('li');
                li.textContent = t;
                av.appendChild(li);
            });
            document.getElementById('cc-avisos-caja').style.display = d.avisos.length ? '' : 'none';
            document.getElementById('cc-cargando').style.display = 'none';
            document.getElementById('cc-contenido').style.display = '';
            btn.disabled = false; btn.style.opacity = 1;
            document.getElementById('cc-nueva').focus();
        })
        .catch(function () {
            document.getElementById('cc-cargando').textContent = 'No se pudo consultar. Recarga la página e intenta de nuevo.';
        });
}

function CC_cerrar() {
    document.getElementById('cc-modal').style.display = 'none';
}

function CC_guardar() {
    var nueva = document.getElementById('cc-nueva').value.trim();
    var motivo = document.getElementById('cc-motivo').value.trim();
    var err = document.getElementById('cc-error');
    err.style.display = 'none';

    if (!confirm('¿Cambiar la cédula {{ $cliente->cedula }} por ' + nueva + ' en la ficha y en todos sus registros?')) return;

    var btn = document.getElementById('cc-btn');
    btn.disabled = true; btn.style.opacity = 0.6; btn.textContent = 'Corrigiendo…';

    fetch(@json(route('admin.clientes.cedula.corregir', $cliente->id)), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ cedula: nueva, motivo: motivo })
    })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
            if (res.ok && res.d.ok) {
                window.location.reload();
                return;
            }
            var msgs = res.d.errors ? Object.values(res.d.errors).flat() : [res.d.message || 'No se pudo corregir.'];
            err.textContent = msgs.join(' ');
            err.style.display = '';
            btn.disabled = false; btn.style.opacity = 1; btn.textContent = 'Corregir cédula';
        })
        .catch(function () {
            err.textContent = 'Error de conexión. Intenta de nuevo.';
            err.style.display = '';
            btn.disabled = false; btn.style.opacity = 1; btn.textContent = 'Corregir cédula';
        });
}
</script>
