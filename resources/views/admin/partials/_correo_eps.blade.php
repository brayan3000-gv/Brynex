{{--
    Modal de afiliación por correo al asesor de una EPS sin portal de empleador
    (hoy Comfenalco Valle). Lo abre `abrirCorreoEps(contratoId, entidad)` desde el
    radicado de EPS: arma la vista previa (datos + formulario + cédula), deja
    subir la cédula si falta y envía desde el Gmail del aliado. La respuesta la
    procesa el agente del buzón.
--}}
<style>
.ceps2-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.ceps2-bg.open { display:flex }
.ceps2-box { background:#fff;border-radius:14px;max-width:600px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;max-height:94vh;overflow-y:auto }
.ceps2-head { background:linear-gradient(135deg,#15803d,#16a34a);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.ceps2-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.ceps2-x { background:none;border:none;color:#dcfce7;font-size:1.15rem;cursor:pointer;line-height:1 }
.ceps2-body { padding:1.1rem }
.ceps2-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.7rem;font-size:.75rem;color:#991b1b }
.ceps2-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.7rem;font-size:.75rem;color:#92400e;line-height:1.5 }
.ceps2-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.ceps2-campo { display:block;font-size:.72rem;color:#475569;font-weight:600;margin:.45rem 0 .15rem }
.ceps2-input { width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:.35rem .5rem;font-size:.78rem;font-family:inherit }
.ceps2-adj { font-size:.74rem;margin:.2rem 0 0 1rem;padding:0;line-height:1.6 }
.ceps2-btn { width:100%;margin-top:.6rem;background:linear-gradient(135deg,#15803d,#16a34a);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.ceps2-btn:disabled { opacity:.5;cursor:not-allowed }
.ceps2-chip { display:inline-block;margin-top:.25rem;font-size:.7rem;color:#15803d;background:#f0fdf4;border:1px solid #86efac;border-radius:999px;padding:.05rem .5rem;cursor:pointer }
</style>

<div class="ceps2-bg" id="ceps2Modal">
  <div class="ceps2-box">
    <div class="ceps2-head">
      <h3 id="ceps2Titulo">📧 Afiliación por correo</h3>
      <button class="ceps2-x" onclick="cerrarCorreoEps()">✕</button>
    </div>
    <div class="ceps2-body">
      <div id="ceps2Cargando" style="text-align:center;color:#64748b;font-size:.82rem;padding:1.2rem">⏳ Preparando el correo...</div>
      <div id="ceps2Contenido" style="display:none">
        <div class="ceps2-prob" id="ceps2Problemas" style="display:none"></div>
        <div id="ceps2Subir" style="display:none;font-size:.74rem;margin-bottom:.5rem">
          <label class="ceps2-campo" for="ceps2Archivo">Subir copia del documento de identidad (PDF o imagen)</label>
          <input type="file" id="ceps2Archivo" accept=".pdf,.jpg,.jpeg,.png" class="ceps2-input" onchange="subirDocumentoCorreoEps()">
        </div>
        <div class="ceps2-aviso" id="ceps2Avisos" style="display:none"></div>

        {{-- Sin la firma dibujada el formulario sale en blanco y la EPS lo
             devuelve: aquí están las dos salidas, firmar o revisar si ya firmó. --}}
        <div id="ceps2Firma" style="display:none;border:1px solid #fbbf24;background:#fffbeb;border-radius:9px;padding:.55rem .7rem;margin-bottom:.5rem">
          <div style="font-size:.76rem;color:#92400e;font-weight:700;margin-bottom:.4rem">✍️ Falta la firma del contratista</div>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <button class="ceps2-btn" style="flex:1;min-width:190px" onclick="firmarParaCorreoEps()">✍️ Abrir formulario y firmar</button>
            <button class="ceps2-btn sec" style="flex:1;min-width:150px" onclick="abrirCorreoEps(ceps2ContratoId, ceps2Entidad, true)">✅ Ya firmó, revisar</button>
          </div>
        </div>

        <label class="ceps2-campo" for="ceps2Para">Para</label>
        <input id="ceps2Para" class="ceps2-input">
        <span class="ceps2-chip" id="ceps2Reemplazo" style="display:none" onclick="usarReemplazoCorreoEps()"></span>
        <label class="ceps2-campo" for="ceps2Cc">CC (opcional)</label>
        <input id="ceps2Cc" class="ceps2-input">
        <label class="ceps2-campo" for="ceps2Asunto">Asunto</label>
        <input id="ceps2Asunto" class="ceps2-input">
        <label class="ceps2-campo" for="ceps2Cuerpo">Mensaje (con los datos del cotizante)</label>
        <textarea id="ceps2Cuerpo" rows="16" class="ceps2-input"></textarea>
        <div class="ceps2-campo">Adjuntos</div>
        <ul class="ceps2-adj" id="ceps2Adjuntos"></ul>
        <div id="ceps2Info" style="font-size:.72rem;color:#64748b;margin-top:.4rem"></div>
        <button class="ceps2-btn" id="ceps2BtnEnviar" onclick="enviarCorreoEps()">📧 Enviar correo</button>
      </div>
      <div id="ceps2Resultado" class="ceps2-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let ceps2ContratoId = null, ceps2Entidad = null, ceps2Prep = null;
const CEPS2_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const ceps2Esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const ceps2El = id => document.getElementById(id);

function cerrarCorreoEps() { ceps2El('ceps2Modal').classList.remove('open'); }

async function abrirCorreoEps(contratoId, entidad, conservar = false) {
    ceps2ContratoId = contratoId; ceps2Entidad = entidad;
    const previo = conservar ? { para: ceps2El('ceps2Para').value, cc: ceps2El('ceps2Cc').value } : null;
    if (!conservar) {
        ceps2El('ceps2Contenido').style.display = 'none';
        ceps2El('ceps2Resultado').style.display = 'none';
        ceps2El('ceps2Cargando').style.display = 'block';
        ceps2El('ceps2Modal').classList.add('open');
    }

    let d;
    try {
        const r = await fetch(`/admin/afiliaciones/${contratoId}/correo-eps/${entidad}`, { headers: { 'Accept': 'application/json' } });
        d = await r.json();
    } catch (e) { d = { ok: false, error: 'No se pudo preparar el correo.' }; }
    ceps2El('ceps2Cargando').style.display = 'none';
    if (!d.ok) { ceps2El('ceps2Resultado').style.display = 'block'; ceps2El('ceps2Resultado').className = 'ceps2-prob'; ceps2El('ceps2Resultado').textContent = d.error || 'No se pudo preparar el correo.'; return; }
    ceps2Prep = d;

    ceps2El('ceps2Titulo').textContent = `📧 Afiliación por correo — ${d.nombre_entidad}`;
    ceps2El('ceps2Contenido').style.display = 'block';
    const prob = d.problemas || [];
    ceps2El('ceps2Problemas').innerHTML = prob.map(p => '• ' + ceps2Esc(p)).join('<br>');
    ceps2El('ceps2Problemas').style.display = prob.length ? 'block' : 'none';
    const av = d.avisos || [];
    ceps2El('ceps2Subir').style.display = av.some(a => /documento de identidad/i.test(a)) ? 'block' : 'none';
    ceps2El('ceps2Avisos').innerHTML = av.map(a => '⚠️ ' + ceps2Esc(a)).join('<br>');
    ceps2El('ceps2Avisos').style.display = av.length ? 'block' : 'none';

    ceps2El('ceps2Para').value = previo ? previo.para : d.para.correo;
    ceps2El('ceps2Cc').value = previo ? previo.cc : '';
    ceps2El('ceps2Asunto').value = d.asunto;
    ceps2El('ceps2Cuerpo').value = d.cuerpo;
    const rem = ceps2El('ceps2Reemplazo');
    rem.style.display = d.reemplazo ? 'inline-block' : 'none';
    if (d.reemplazo) rem.textContent = `↪ ¿${d.para.nombre} no está? Enviar a ${d.reemplazo.nombre} (${d.reemplazo.correo})`;

    ceps2El('ceps2Adjuntos').innerHTML = (d.adjuntos || []).map(a => `<li>📎 ${ceps2Esc(a.nombre)} <span style="color:#94a3b8">— ${ceps2Esc(a.origen)}</span></li>`).join('');
    const previos = (d.previos || []).map(p => `${ceps2Esc(p.estado)} · ${ceps2Esc((p.enviado_at || '').slice(0, 16).replace('T', ' '))} → ${ceps2Esc(p.para)}`).join('<br>');
    ceps2El('ceps2Info').innerHTML = `Sale desde <strong>${ceps2Esc(d.buzon)}</strong> para <strong>${ceps2Esc(d.para.nombre)}</strong>. Si no hay respuesta, se avisa el ${ceps2Esc(d.vence)}.` +
        (previos ? `<br>Correos anteriores:<br>${previos}` : '');

    ceps2El('ceps2Firma').style.display = d.falta_firma ? 'block' : 'none';

    const btn = ceps2El('ceps2BtnEnviar');
    btn.disabled = prob.length > 0 || !!d.falta_firma;
    btn.textContent = prob.length ? '🚫 Resuelve lo que falta para enviar'
        : (d.falta_firma ? '📧 Enviar correo (falta la firma)' : '📧 Enviar correo');
}

// El formulario del contrato se abre aparte: ahí está el lienzo de la firma.
function firmarParaCorreoEps() {
    window.open(`/admin/afiliaciones/${ceps2ContratoId}/formulario/eps`, '_blank');
}

function usarReemplazoCorreoEps() {
    if (!ceps2Prep?.reemplazo) return;
    ceps2El('ceps2Para').value = ceps2Prep.reemplazo.correo;
    ceps2El('ceps2Cc').value = ceps2Prep.para.correo;
    ceps2El('ceps2Cuerpo').value = ceps2El('ceps2Cuerpo').value.replace(/^Un cordial saludo, [^.\n]+\./, 'Un cordial saludo, ' + ceps2Prep.reemplazo.nombre.split(' ')[0] + '.');
}

async function subirDocumentoCorreoEps() {
    const input = ceps2El('ceps2Archivo');
    if (!input.files.length) return;
    const fd = new FormData();
    fd.append('archivo', input.files[0]);
    const r = await fetch(`/admin/afiliaciones/${ceps2ContratoId}/correo-eps/${ceps2Entidad}/documento`, {
        method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CEPS2_CSRF },
    });
    const d = await r.json().catch(() => ({}));
    input.value = '';
    if (!r.ok || !d.ok) { alert(d.message || d.error || 'No se pudo subir el documento.'); return; }
    abrirCorreoEps(ceps2ContratoId, ceps2Entidad, true);
}

async function enviarCorreoEps() {
    const para = ceps2El('ceps2Para').value.trim();
    if (!para) { alert('Indica a quién va el correo.'); return; }
    if (!confirm(`¿Enviar la afiliación por correo a ${para}?\n\nSale desde ${ceps2Prep?.buzon} con ${(ceps2Prep?.adjuntos || []).length} adjuntos.`)) return;

    const btn = ceps2El('ceps2BtnEnviar');
    btn.disabled = true; btn.textContent = '⏳ Enviando correo...';
    let d;
    try {
        const r = await fetch(`/admin/afiliaciones/${ceps2ContratoId}/correo-eps/${ceps2Entidad}/enviar`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CEPS2_CSRF },
            body: JSON.stringify({ para, cc: ceps2El('ceps2Cc').value.trim(), asunto: ceps2El('ceps2Asunto').value, cuerpo: ceps2El('ceps2Cuerpo').value }),
        });
        d = await r.json();
    } catch (e) { d = { ok: false, error: 'Se perdió la conexión. Revisa en Gmail (Enviados) antes de reintentar.' }; }

    if (!d.ok) { btn.disabled = false; btn.textContent = '📧 Reintentar envío'; alert(d.error || d.message || 'No se pudo enviar.'); return; }

    ceps2El('ceps2Contenido').style.display = 'none';
    const caja = ceps2El('ceps2Resultado');
    caja.className = 'ceps2-ok';
    caja.innerHTML = `📧 Correo enviado a <strong>${ceps2Esc(d.para)}</strong>.<br><span style="color:#475569">El radicado de EPS quedó en trámite. Cuando ${ceps2Esc(ceps2Prep?.para?.nombre)} responda, el agente del buzón lo pasa a OK y avisa por WhatsApp. Si no responde, se avisa el ${ceps2Esc(d.vence)}.</span>`;
    caja.style.display = 'block';
    setTimeout(() => location.reload(), 5000);
}
</script>
