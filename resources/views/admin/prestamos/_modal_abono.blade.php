{{--
    Modal para registrar el abono de un préstamo. Lo comparten el listado y el
    detalle: antes cada uno tenía su copia y se desfasaban.

    Se abre con abrirAbonar(id, saldo, nombre?, varios?) y necesita $bancos
    (las cuentas del aliado) y las clases .modal-bg / cerrarModal() de la
    página. Con varios = {ids: [...], periodos: [...]} paga de una vez varios
    períodos de un cliente (PrestamosController::abonarCliente).
--}}
<style>
.abm-box { width:min(500px,96vw); padding:0; border-radius:18px; overflow:hidden; display:flex; flex-direction:column; }
.abm-hdr { display:flex; align-items:center; gap:.8rem; padding:1.1rem 1.35rem; background:linear-gradient(135deg,#0f172a,#1e3a8a); color:#fff; }
.abm-hdr-ico { width:42px; height:42px; border-radius:12px; background:rgba(255,255,255,.14); display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.abm-hdr-txt { flex:1; min-width:0; }
.abm-hdr-tit { font-size:1.02rem; font-weight:800; line-height:1.2; }
.abm-hdr-sub { font-size:.78rem; color:#bfdbfe; margin-top:.1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.abm-x { background:rgba(255,255,255,.12); border:none; color:#fff; width:32px; height:32px; border-radius:9px; font-size:1rem; cursor:pointer; flex-shrink:0; }
.abm-x:hover { background:rgba(239,68,68,.85); }

.abm-body { padding:1.15rem 1.35rem .4rem; overflow-y:auto; flex:1; min-height:0; }
.abm-saldo { display:flex; justify-content:space-between; align-items:center; gap:.6rem; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:.65rem .9rem; margin-bottom:1rem; }
.abm-saldo-lbl { font-size:.68rem; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:.05em; }
.abm-saldo-val { font-size:1.25rem; font-weight:800; color:#14532d; font-family:ui-monospace,monospace; }
.abm-queda { font-size:.72rem; color:#475569; text-align:right; }
.abm-queda b { color:#0f172a; }
.abm-varios { display:none; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:10px; padding:.5rem .75rem; font-size:.76rem; margin:-.4rem 0 1rem; line-height:1.4; }
.abm-varios.on { display:block; }

.abm-row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media (max-width:520px) { .abm-row { grid-template-columns:1fr; } }
.abm-f { display:flex; flex-direction:column; gap:.28rem; margin-bottom:.85rem; min-width:0; }
.abm-f > label, .abm-lbl { font-size:.68rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.05em; }
.abm-f input[type=number], .abm-f input[type=date], .abm-f select, .abm-f textarea {
    width:100%; padding:.55rem .7rem; border:1.5px solid #e2e8f0; border-radius:10px; font-size:.9rem; outline:none; background:#fff; color:#0f172a; transition:border-color .15s, box-shadow .15s; box-sizing:border-box;
}
.abm-f input:focus, .abm-f select:focus, .abm-f textarea:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.abm-f textarea { resize:vertical; min-height:58px; font-family:inherit; }
.abm-money { position:relative; }
.abm-money span { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#94a3b8; font-weight:700; pointer-events:none; }
.abm-money input { padding-left:1.6rem !important; font-weight:700; font-size:1rem !important; font-family:ui-monospace,monospace; }
.abm-hint { font-size:.7rem; color:#94a3b8; }
.abm-hint.warn { color:#b45309; }

.abm-seg { display:grid; grid-template-columns:repeat(3,1fr); gap:.4rem; }
.abm-seg button { border:1.5px solid #e2e8f0; background:#fff; border-radius:10px; padding:.5rem .3rem; font-size:.8rem; font-weight:700; color:#475569; cursor:pointer; transition:all .15s; }
.abm-seg button:hover { border-color:#93c5fd; }
.abm-seg button.on { border-color:#2563eb; background:#eff6ff; color:#1d4ed8; box-shadow:0 0 0 3px rgba(37,99,235,.12); }

.abm-drop { position:relative; border:2px dashed #cbd5e1; border-radius:12px; background:#f8fafc; padding:.95rem; text-align:center; cursor:pointer; transition:all .15s; outline:none; }
.abm-drop:hover, .abm-drop:focus, .abm-drop.drag { border-color:#3b82f6; background:#eff6ff; }
.abm-drop.req { border-color:#fca5a5; }
.abm-drop-ico { font-size:1.5rem; }
.abm-drop-t { font-size:.84rem; font-weight:700; color:#334155; margin-top:.15rem; }
.abm-drop-s { font-size:.72rem; color:#64748b; margin-top:.15rem; }
.abm-kbd { display:inline-block; padding:.05rem .35rem; border:1px solid #cbd5e1; border-bottom-width:2px; border-radius:5px; background:#fff; font-size:.68rem; font-weight:700; color:#334155; font-family:ui-monospace,monospace; }
.abm-file { display:none; align-items:center; gap:.7rem; border:1.5px solid #bbf7d0; background:#f0fdf4; border-radius:12px; padding:.55rem .65rem; }
.abm-file.on { display:flex; }
.abm-thumb { width:58px; height:58px; border-radius:8px; object-fit:cover; background:#e2e8f0; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.6rem; cursor:zoom-in; }
.abm-file-info { flex:1; min-width:0; }
.abm-file-n { font-size:.82rem; font-weight:700; color:#14532d; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.abm-file-s { font-size:.7rem; color:#15803d; }
.abm-file-x { background:#fff; border:1px solid #fecaca; color:#dc2626; border-radius:8px; padding:.3rem .55rem; font-size:.75rem; font-weight:700; cursor:pointer; }

.abm-err { display:none; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:10px; padding:.55rem .75rem; font-size:.8rem; font-weight:600; margin-bottom:.8rem; white-space:pre-line; }
.abm-err.on { display:block; }
.abm-foot { padding:.85rem 1.35rem 1.15rem; border-top:1px solid #f1f5f9; background:#fff; }
.abm-btn { width:100%; border:none; border-radius:12px; padding:.75rem 1rem; font-size:.95rem; font-weight:800; color:#fff; cursor:pointer; background:linear-gradient(135deg,#15803d,#16a34a); box-shadow:0 6px 18px rgba(22,163,74,.28); transition:transform .1s, opacity .15s; }
.abm-btn:hover { transform:translateY(-1px); }
.abm-btn:disabled { opacity:.6; cursor:wait; transform:none; }
</style>

<div class="modal-bg" id="modalAbonar">
<div class="modal-box abm-box" onclick="event.stopPropagation()">
    <div class="abm-hdr">
        <div class="abm-hdr-ico">💰</div>
        <div class="abm-hdr-txt">
            <div class="abm-hdr-tit">Registrar abono</div>
            <div class="abm-hdr-sub" id="ab-nombre">Préstamo</div>
        </div>
        <button type="button" class="abm-x" onclick="cerrarModal('modalAbonar')" title="Cerrar">✕</button>
    </div>

    <form id="formAbonar" style="display:contents;">
    <div class="abm-body">
        <input type="hidden" id="ab-id">

        <div class="abm-saldo">
            <div>
                <div class="abm-saldo-lbl">Saldo pendiente</div>
                <div class="abm-saldo-val" id="ab-saldo">$0</div>
            </div>
            <div class="abm-queda" id="ab-queda"></div>
        </div>
        <div class="abm-varios" id="ab-varios"></div>

        <div id="ab-err" class="abm-err"></div>

        <div class="abm-row">
            <div class="abm-f">
                <label for="ab-valor">Valor del abono *</label>
                <div class="abm-money"><span>$</span><input type="number" id="ab-valor" min="1" required></div>
            </div>
            {{-- El día en que entró la plata, el que dice el recibo de la
                 consignación: no el día en que se anota. --}}
            <div class="abm-f">
                <label for="ab-fecha">Fecha del pago *</label>
                <input type="date" id="ab-fecha" required max="{{ today()->toDateString() }}">
                <div class="abm-hint" id="ab-fecha-hint">La que dice el recibo.</div>
            </div>
        </div>

        <div class="abm-f">
            <span class="abm-lbl">Forma de pago *</span>
            <div class="abm-seg" id="ab-seg">
                <button type="button" data-forma="efectivo">💵 Efectivo</button>
                <button type="button" data-forma="consignacion">🏦 Consignación</button>
                <button type="button" data-forma="mixto">🔀 Mixto</button>
            </div>
            <input type="hidden" id="ab-forma" value="efectivo">
        </div>

        {{-- El desglose solo se pregunta en un pago mixto: en efectivo o
             consignación puros es el valor del abono completo, y pedirlo dos
             veces solo servía para guardar una consignación con la plata
             anotada en la casilla de efectivo. --}}
        <div id="ab-mix-row" style="display:none;">
            <div class="abm-row">
                <div class="abm-f">
                    <label for="ab-ef">Valor efectivo *</label>
                    <div class="abm-money"><span>$</span><input type="number" id="ab-ef" min="0"></div>
                </div>
                <div class="abm-f">
                    <label for="ab-cs">Valor consignado *</label>
                    <div class="abm-money"><span>$</span><input type="number" id="ab-cs" min="0"></div>
                </div>
            </div>
            <div class="abm-hint" id="ab-mix-hint" style="margin:-.5rem 0 .85rem;">Los dos tienen que sumar el valor del abono.</div>
        </div>

        {{-- A qué cuenta entró la plata. Sin esto no hay forma de saber qué
             recaudo le corresponde a cada razón social, y la facturación
             electrónica —que emite lo que entra a la cuenta de la emisora— se
             queda sin ver los pagos de préstamos. --}}
        <div id="ab-banco-row" class="abm-f" style="display:none;">
            <label for="ab-banco">🏦 Cuenta que recibió el pago *</label>
            <select id="ab-banco">
                <option value="">— Elige la cuenta —</option>
                @foreach($bancos as $b)
                    <option value="{{ $b->id }}">{{ $b->banco }} — {{ $b->nombre }} ({{ $b->numero_cuenta }})</option>
                @endforeach
            </select>
        </div>

        <div class="abm-f">
            <span class="abm-lbl" id="ab-sop-lbl">📎 Certificado de la consignación</span>
            <input type="file" id="ab-sop" accept="image/jpeg,image/png,application/pdf" style="display:none;">
            <div class="abm-drop" id="ab-drop" tabindex="0">
                <div class="abm-drop-ico">📋</div>
                <div class="abm-drop-t">Pega la foto con <span class="abm-kbd" id="ab-kbd">Ctrl</span> + <span class="abm-kbd">V</span></div>
                <div class="abm-drop-s">o arrástrala aquí, o haz clic para buscarla · JPG, PNG o PDF, máx. 10 MB</div>
            </div>
            <div class="abm-file" id="ab-file">
                <div class="abm-thumb" id="ab-thumb" title="Ver en grande"></div>
                <div class="abm-file-info">
                    <div class="abm-file-n" id="ab-file-n"></div>
                    <div class="abm-file-s" id="ab-file-s"></div>
                </div>
                <button type="button" class="abm-file-x" id="ab-file-x">Quitar</button>
            </div>
        </div>

        <div class="abm-f">
            <label for="ab-obs">Observación</label>
            <textarea id="ab-obs" rows="2" placeholder="Opcional"></textarea>
        </div>
    </div>

    <div class="abm-foot">
        <button type="submit" class="abm-btn" id="ab-btn">💰 Registrar abono</button>
    </div>
    </form>
</div>
</div>

@push('scripts')
<script>
(() => {
    const $ = id => document.getElementById(id);
    const CSRF_AB = document.querySelector('meta[name="csrf-token"]')?.content;
    const pesos = v => '$' + (parseInt(v) || 0).toLocaleString('es-CO');
    const hoy = () => {
        const d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    };
    let saldoActual = 0;
    let variosIds = null;   // ids de los períodos cuando se paga todo junto
    let objUrl = null;

    if (/Mac|iPhone|iPad/.test(navigator.platform)) $('ab-kbd').textContent = '⌘';

    // ── Abrir ────────────────────────────────────────────────
    window.abrirAbonar = function (id, saldo, nombre, varios) {
        saldoActual = parseInt(saldo) || 0;
        variosIds = varios && varios.ids && varios.ids.length > 1 ? varios.ids : null;
        $('ab-varios').classList.toggle('on', !!variosIds);
        $('ab-varios').innerHTML = variosIds
            ? '📅 <b>Pago de ' + variosIds.length + ' períodos:</b> ' + (varios.periodos || []).join(', ')
              + '.<br>Se reparte del más antiguo al más nuevo y queda un abono en cada uno, con un solo recibo.'
            : '';
        $('ab-id').value = id;
        $('ab-nombre').textContent = nombre ? '👤 ' + nombre : 'Préstamo #' + id;
        $('ab-saldo').textContent = pesos(saldoActual);
        $('ab-valor').value = saldoActual;
        $('ab-fecha').value = hoy();
        $('ab-fecha').max = hoy();
        $('ab-ef').value = '';
        $('ab-cs').value = '';
        $('ab-obs').value = '';
        $('ab-banco').value = '';
        quitarArchivo();
        mostrarError('');
        elegirForma('efectivo');
        actualizarQueda();
        revisarFecha();
        $('modalAbonar').classList.add('open');
        setTimeout(() => $('ab-valor').select(), 50);
    };

    // ── Forma de pago ────────────────────────────────────────
    function elegirForma(f) {
        $('ab-forma').value = f;
        document.querySelectorAll('#ab-seg button').forEach(b => b.classList.toggle('on', b.dataset.forma === f));
        $('ab-mix-row').style.display = f === 'mixto' ? '' : 'none';
        // El certificado y la cuenta solo se exigen cuando hay plata entrando
        // por el banco: en un abono en efectivo no hay cuenta que registrar.
        const exige = f === 'consignacion' || f === 'mixto';
        $('ab-banco-row').style.display = exige ? '' : 'none';
        $('ab-banco').required = exige;
        $('ab-sop-lbl').textContent = exige
            ? '📎 Certificado de la consignación *'
            : '📎 Certificado / soporte (opcional)';
        $('ab-drop').classList.toggle('req', exige && !$('ab-sop').files.length);
    }
    document.querySelectorAll('#ab-seg button').forEach(b => b.addEventListener('click', () => elegirForma(b.dataset.forma)));

    function actualizarQueda() {
        const v = parseInt($('ab-valor').value) || 0;
        const queda = saldoActual - v;
        $('ab-queda').innerHTML = v <= 0 ? ''
            : queda > 0 ? 'Queda debiendo<br><b>' + pesos(queda) + '</b>'
            : queda === 0 ? '<b style="color:#15803d;">✅ Queda saldado</b>'
            : '<b style="color:#b45309;">⚠️ ' + pesos(-queda) + ' más del saldo</b>';
    }
    $('ab-valor').addEventListener('input', actualizarQueda);

    function revisarFecha() {
        const f = $('ab-fecha').value;
        const h = $('ab-fecha-hint');
        const atrasada = f && f < hoy();
        h.textContent = !f ? 'La que dice el recibo.'
            : f > hoy() ? 'No puede ser posterior a hoy.'
            : atrasada ? '⚠️ Queda registrado con fecha ' + f.split('-').reverse().join('/') + '.'
            : 'Hoy. Cámbiala si el recibo es de otro día.';
        h.classList.toggle('warn', !!atrasada || f > hoy());
    }
    $('ab-fecha').addEventListener('change', revisarFecha);

    // ── Soporte: pegar, arrastrar o buscar ───────────────────
    const TIPOS = ['image/jpeg', 'image/png', 'application/pdf'];

    // Una captura que no es JPG/PNG (webp, heic que el navegador sí lea…) se
    // pasa a PNG: el servidor solo acepta esos tres tipos.
    function aPng(file) {
        return new Promise((ok, mal) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                const c = document.createElement('canvas');
                c.width = img.naturalWidth; c.height = img.naturalHeight;
                c.getContext('2d').drawImage(img, 0, 0);
                URL.revokeObjectURL(url);
                c.toBlob(b => b ? ok(new File([b], file.name.replace(/\.\w+$/, '') + '.png', { type: 'image/png' })) : mal(), 'image/png');
            };
            img.onerror = () => { URL.revokeObjectURL(url); mal(); };
            img.src = url;
        });
    }

    async function ponerArchivo(file, pegado) {
        if (!file) return;
        if (!TIPOS.includes(file.type)) {
            if (!file.type.startsWith('image/')) { mostrarError('El certificado debe ser una imagen (JPG/PNG) o un PDF.'); return; }
            try { file = await aPng(file); } catch (e) { mostrarError('No se pudo leer esa imagen. Guárdala como JPG o PNG.'); return; }
        }
        // Lo que se pega del portapapeles llega como "image.png": se le pone
        // un nombre que diga qué es.
        if (pegado) {
            const ext = file.type === 'application/pdf' ? 'pdf' : (file.type === 'image/jpeg' ? 'jpg' : 'png');
            const t = new Date();
            const sello = hoy().replace(/-/g, '') + '-' + String(t.getHours()).padStart(2, '0') + String(t.getMinutes()).padStart(2, '0') + String(t.getSeconds()).padStart(2, '0');
            file = new File([file], 'consignacion-' + sello + '.' + ext, { type: file.type });
        }
        if (file.size > 10 * 1024 * 1024) { mostrarError('El certificado no puede pesar más de 10 MB.'); return; }

        const dt = new DataTransfer();
        dt.items.add(file);
        $('ab-sop').files = dt.files;
        mostrarError('');

        if (objUrl) URL.revokeObjectURL(objUrl);
        objUrl = URL.createObjectURL(file);
        const th = $('ab-thumb');
        th.innerHTML = file.type.startsWith('image/')
            ? '<img src="' + objUrl + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">'
            : '📄';
        $('ab-file-n').textContent = file.name;
        $('ab-file-s').textContent = (file.size / 1024 > 1024 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.ceil(file.size / 1024) + ' KB') + (pegado ? ' · pegado' : '');
        $('ab-file').classList.add('on');
        $('ab-drop').style.display = 'none';
    }

    function quitarArchivo() {
        $('ab-sop').value = '';
        if (objUrl) { URL.revokeObjectURL(objUrl); objUrl = null; }
        $('ab-thumb').innerHTML = '';
        $('ab-file').classList.remove('on');
        $('ab-drop').style.display = '';
        const f = $('ab-forma').value;
        $('ab-drop').classList.toggle('req', f === 'consignacion' || f === 'mixto');
    }

    $('ab-drop').addEventListener('click', () => $('ab-sop').click());
    $('ab-drop').addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('ab-sop').click(); } });
    $('ab-sop').addEventListener('change', e => { const f = e.target.files[0]; if (f) ponerArchivo(f, false); });
    $('ab-file-x').addEventListener('click', quitarArchivo);
    $('ab-thumb').addEventListener('click', () => { if (objUrl) window.open(objUrl, '_blank'); });

    ['dragenter', 'dragover'].forEach(ev => $('ab-drop').addEventListener(ev, e => { e.preventDefault(); $('ab-drop').classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => $('ab-drop').addEventListener(ev, e => { e.preventDefault(); $('ab-drop').classList.remove('drag'); }));
    $('ab-drop').addEventListener('drop', e => ponerArchivo(e.dataTransfer.files[0], false));

    // Ctrl/⌘+V en cualquier parte del modal abierto. Si lo que se pega es
    // texto (en el valor o la observación) se deja pasar normal.
    document.addEventListener('paste', e => {
        if (!$('modalAbonar').classList.contains('open')) return;
        const items = [...(e.clipboardData?.items || [])];
        const it = items.find(i => i.kind === 'file' && (i.type.startsWith('image/') || i.type === 'application/pdf'));
        if (!it) return;
        e.preventDefault();
        ponerArchivo(it.getAsFile(), true);
    });

    // ── Errores y envío ──────────────────────────────────────
    function mostrarError(m) {
        $('ab-err').textContent = m;
        $('ab-err').classList.toggle('on', !!m);
        if (m) $('ab-err').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    $('formAbonar').addEventListener('submit', async e => {
        e.preventDefault();
        const id = $('ab-id').value;
        // El desglose se deduce de la forma de pago; solo el mixto lo trae escrito.
        const valor = parseInt($('ab-valor').value) || 0;
        const forma = $('ab-forma').value;
        const ef = forma === 'efectivo' ? valor : (forma === 'mixto' ? (parseInt($('ab-ef').value) || 0) : 0);
        const cs = forma === 'consignacion' ? valor : (forma === 'mixto' ? (parseInt($('ab-cs').value) || 0) : 0);
        if (forma === 'mixto' && ef + cs !== valor) {
            mostrarError('El efectivo y la consignación tienen que sumar ' + pesos(valor) + '.');
            return;
        }
        if (!$('ab-fecha').value || $('ab-fecha').value > hoy()) {
            mostrarError('Pon la fecha del pago (no puede ser posterior a hoy).');
            return;
        }
        if (variosIds && valor > saldoActual) {
            mostrarError('El pago es mayor que lo que debe en estos períodos (' + pesos(saldoActual) + ').');
            return;
        }
        const exige = forma === 'consignacion' || forma === 'mixto';
        if (exige && !$('ab-banco').value) { mostrarError('Dinos a qué cuenta entró la consignación.'); return; }
        if (exige && !$('ab-sop').files.length) { mostrarError('Adjunta el certificado de la consignación: pégalo con Ctrl+V o búscalo.'); return; }

        const fd = new FormData();
        if (variosIds) variosIds.forEach(i => fd.append('factura_ids[]', i));
        fd.append('valor',            valor);
        fd.append('forma_pago',       forma);
        fd.append('valor_efectivo',   ef);
        fd.append('valor_consignado', cs);
        fd.append('fecha',            $('ab-fecha').value);
        fd.append('banco_cuenta_id',  $('ab-banco').value || '');
        fd.append('observacion',      $('ab-obs').value);
        const sop = $('ab-sop').files[0];
        if (sop) fd.append('soporte', sop);

        const btn = $('ab-btn');
        btn.disabled = true;
        btn.textContent = '⏳ Registrando…';
        try {
            const url = variosIds ? '/admin/prestamos/abonar-cliente' : `/admin/prestamos/${id}/abonar`;
            const r = await fetch(url, {
                method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF_AB, 'Accept': 'application/json' }, body: fd
            });
            const res = await r.json().catch(() => ({}));
            if (!r.ok) {
                mostrarError(res.message && !res.errors ? res.message
                    : Object.values(res.errors || {}).flat().join('\n') || res.message || 'Error al registrar el abono');
                return;
            }
            alert(res.mensaje || 'Abono registrado');
            if (res.recibo_url) window.open(res.recibo_url, '_blank');
            if (res.ok) location.reload();
        } catch (err) {
            mostrarError('No se pudo conectar con el servidor. Intenta de nuevo.');
        } finally {
            btn.disabled = false;
            btn.textContent = '💰 Registrar abono';
        }
    });
})();
</script>
@endpush
