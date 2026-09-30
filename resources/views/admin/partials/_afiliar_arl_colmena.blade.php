{{--
    Modal de afiliación a ARL Colmena, autocontenido.

    Se incluye en el listado de afiliaciones y solo necesita que algo llame a
    `abrirAfiliarColmena(contratoId)`. Los endpoints viven en
    admin.afiliaciones.colmena.* y van detrás del Gate `automatizar-arl`.

    Dos avisos que no son decorativos, porque son reglas del portal:
    la vigencia no puede empezar hoy, y la anulación caduca un día calendario
    después de que empieza.
--}}
<style>
.acm-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;padding:1rem }
.acm-bg.open { display:flex }
.acm-box { background:#fff;border-radius:14px;max-width:520px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden }
.acm-head { background:linear-gradient(135deg,#b45309,#f59e0b);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center }
.acm-head h3 { color:#fff;font-size:.92rem;font-weight:800;margin:0 }
.acm-x { background:none;border:none;color:#fef3c7;font-size:1.15rem;cursor:pointer;line-height:1 }
.acm-body { padding:1.1rem }
.acm-resumen { background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.65rem .8rem;font-size:.76rem;line-height:1.65;margin-bottom:.8rem }
.acm-resumen span { color:#64748b }
.acm-prob { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#991b1b }
.acm-prob ul { margin:.35rem 0 0 1rem;padding:0 }
.acm-aviso { background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:.65rem .8rem;margin-bottom:.8rem;font-size:.75rem;color:#92400e }
.acm-ok { background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:.8rem .9rem;font-size:.8rem;color:#166534;line-height:1.6 }
.acm-label { display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem }
.acm-input { width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit }
.acm-btn { width:100%;margin-top:.9rem;background:linear-gradient(135deg,#b45309,#f59e0b);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-size:.86rem;font-weight:700;cursor:pointer }
.acm-btn:disabled { opacity:.5;cursor:not-allowed }
.acm-prog { padding:.6rem .4rem;font-size:.8rem;color:#475569 }
.acm-prog-titulo { font-weight:800;color:#92400e;margin-bottom:.6rem;display:flex;justify-content:space-between;align-items:center }
.acm-seg { background:#fef3c7;color:#92400e;border-radius:999px;padding:.1rem .6rem;font-variant-numeric:tabular-nums;font-size:.75rem }
.acm-paso { display:flex;gap:.5rem;align-items:flex-start;padding:.2rem 0;line-height:1.35 }
.acm-paso.hecho { color:#166534 }
.acm-paso.actual { color:#0f172a;font-weight:700 }
@keyframes acm-giro { to { transform:rotate(360deg) } }
.acm-giro { display:inline-block;animation:acm-giro 1.1s linear infinite }
</style>

<div class="acm-bg" id="acmModal">
  <div class="acm-box">
    <div class="acm-head">
      <h3>🐝 Afiliar en ARL Colmena</h3>
      <button class="acm-x" onclick="cerrarAfiliarColmena()">✕</button>
    </div>
    <div class="acm-body">
      <div id="acmCargando" class="acm-prog"></div>

      <div id="acmContenido" style="display:none">
        <div class="acm-resumen" id="acmResumen"></div>

        <div class="acm-prob" id="acmProblemas" style="display:none">
          <strong>Faltan datos para poder afiliar:</strong>
          <ul id="acmProblemasLista"></ul>
        </div>

        {{-- Sin clave en el módulo de claves no hay nada que hacer aquí: se
             carga allá, que es donde el equipo ya las administra. --}}
        <div class="acm-aviso" id="acmSinClave" style="display:none"></div>

        <div class="acm-aviso" id="acmCobertura" style="display:none"></div>

        <div id="acmFechaGrupo">
          <label class="acm-label">El trabajador iniciará vigencia el *</label>
          <input type="date" class="acm-input" id="acmFecha">
          <span style="font-size:.68rem;color:#94a3b8">Colmena no cubre el mismo día: lo más pronto es mañana.</span>
        </div>

        <button class="acm-btn" id="acmBtn" onclick="confirmarAfiliarColmena()">🐝 Afiliar en Colmena</button>
      </div>

      <div id="acmResultado" class="acm-ok" style="display:none"></div>
    </div>
  </div>
</div>

<script>
let acmContratoId = null;
const ACM_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

function cerrarAfiliarColmena() { document.getElementById('acmModal').classList.remove('open'); }

// Abrir sesión en el portal (un navegador dentro del servidor) y radicar pueden
// tardar más de un minuto. El servidor anota cada paso bajo un id y aquí se lee
// cada segundo, junto con el segundero: así se ve que el robot sigue trabajando.
function acmProgreso(titulo) {
    const id = (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
        : Date.now() + '-' + Math.random().toString(16).slice(2);
    const caja = document.getElementById('acmCargando');
    const desde = Date.now();
    let pasos = [];

    const pintar = () => {
        const seg = Math.round((Date.now() - desde) / 1000);
        const filas = pasos.map((p, i) => i < pasos.length - 1
            ? `<div class="acm-paso hecho"><span>✅</span><span>${p}</span></div>`
            : `<div class="acm-paso actual"><span class="acm-giro">⏳</span><span>${p}…</span></div>`).join('');
        caja.innerHTML = `<div class="acm-prog-titulo"><span>${titulo}</span><span class="acm-seg">⏱ ${seg}s</span></div>` +
            (filas || `<div class="acm-paso actual"><span class="acm-giro">⏳</span><span>Conectando con el servidor…</span></div>`);
    };

    const consultar = async () => {
        try {
            const r = await fetch(`/admin/afiliaciones/colmena/progreso/${id}`, { headers: { 'Accept': 'application/json' } });
            const d = await r.json();
            if (Array.isArray(d.pasos)) pasos = d.pasos;
        } catch (e) { /* un tic perdido no importa: el siguiente lo recupera */ }
        pintar();
    };

    pintar();
    const reloj = setInterval(consultar, 1000);
    return { id, parar: () => clearInterval(reloj) };
}

async function abrirAfiliarColmena(contratoId) {
    acmContratoId = contratoId;
    document.getElementById('acmCargando').style.display  = 'block';
    document.getElementById('acmContenido').style.display = 'none';
    document.getElementById('acmResultado').style.display = 'none';
    document.getElementById('acmModal').classList.add('open');

    const prog = acmProgreso('Revisando el contrato en Colmena');
    let data;
    try {
        const r = await fetch(`/admin/afiliaciones/${contratoId}/colmena/precheck?progreso=${prog.id}`, { headers: { 'Accept': 'application/json' } });
        data = await r.json();
    } catch (e) {
        prog.parar();
        document.getElementById('acmCargando').textContent = '⚠️ No se pudo revisar el contrato.';
        return;
    }
    prog.parar();

    document.getElementById('acmCargando').style.display  = 'none';
    document.getElementById('acmContenido').style.display = 'block';

    const r = data.resumen || {};
    const li = (k, v) => v ? `<div><span>${k}:</span> <strong>${v}</strong></div>` : '';
    document.getElementById('acmResumen').innerHTML =
        li('Trabajador', `${r.trabajador} — ${r.documento}`) +
        li('Empresa', r.razon_social + (r.contrato_colmena ? ` (contrato ${r.contrato_colmena})` : '')) +
        li('Seguridad social', `${r.eps ?? '—'} / ${r.afp ?? '—'}`) +
        li('IBC', r.ibc ? '$' + Number(r.ibc).toLocaleString('es-CO') : null) +
        li('Cargo', r.cargo) +
        li('Riesgo', r.nivel_riesgo ? `${r.nivel_riesgo}${r.centro ? ' · ' + r.centro : ''}` : null);

    // Falta la clave del portal: se carga en el módulo de claves, no aquí.
    const cred = data.requiere_credencial;
    const sinClave = document.getElementById('acmSinClave');
    sinClave.style.display = cred ? 'block' : 'none';
    if (cred) {
        sinClave.innerHTML = `🔑 <strong>${cred.razon_social || 'Esta empresa'}</strong> no tiene clave de ARL Colmena cargada. ` +
            'Agrégala en el módulo de claves (tipo <strong>ARL</strong>, entidad <strong>COLMENA</strong>) y vuelve a intentarlo.';
        document.getElementById('acmFechaGrupo').style.display = 'none';
        document.getElementById('acmBtn').style.display = 'none';
        document.getElementById('acmProblemas').style.display = 'none';
        document.getElementById('acmCobertura').style.display = 'none';
        return;
    }
    document.getElementById('acmBtn').style.display = 'block';

    const problemas = data.problemas || [];
    document.getElementById('acmProblemas').style.display = problemas.length ? 'block' : 'none';
    document.getElementById('acmProblemasLista').innerHTML = problemas.map(p => `<li>${p}</li>`).join('');

    // Lo que Colmena ya tiene de esta persona. Es la única fuente confiable:
    // los afiliados a mano no tienen historial en BryNex.
    const cob = data.cobertura;
    const aviso = document.getElementById('acmCobertura');
    if (cob && cob.vigente) {
        aviso.innerHTML = `⚠️ Ya está <strong>vigente en Colmena</strong> desde <strong>${cob.desde}</strong>.` +
            (cob.se_puede_anular ? ' Todavía se puede anular el ingreso si fue un error.'
                                 : ' El plazo para anular ya pasó: para cerrarla hay que retirar.');
        aviso.style.display = 'block';
    } else if (cob) {
        aviso.innerHTML = `ℹ️ Estuvo afiliada antes: <strong>${cob.desde}</strong> → <strong>${cob.hasta}</strong> (retirada).`;
        aviso.style.display = 'block';
    } else {
        aviso.style.display = 'none';
    }

    document.getElementById('acmFecha').value = data.fecha_sugerida || '';
    // Colmena no habilita hoy ni nada anterior.
    document.getElementById('acmFecha').min = data.fecha_sugerida || '';

    const btn = document.getElementById('acmBtn');
    const bloqueado = problemas.length > 0 || (cob && cob.vigente);
    btn.disabled = bloqueado;
    btn.textContent = problemas.length ? '🚫 Completa los datos primero'
        : (cob && cob.vigente ? '🚫 Ya está vigente en Colmena' : '🐝 Afiliar en Colmena');
    document.getElementById('acmFechaGrupo').style.display = bloqueado ? 'none' : 'block';
}

async function confirmarAfiliarColmena() {
    const fecha = document.getElementById('acmFecha').value;
    if (!fecha) { alert('Selecciona la fecha de inicio de vigencia.'); return; }

    const btn = document.getElementById('acmBtn');
    // El formulario se esconde mientras trabaja el robot: así no se puede
    // pulsar dos veces y se ve el avance en su lugar.
    document.getElementById('acmContenido').style.display = 'none';
    document.getElementById('acmCargando').style.display  = 'block';
    const prog = acmProgreso('Afiliando en Colmena');

    let data;
    try {
        const res = await fetch(`/admin/afiliaciones/${acmContratoId}/colmena/afiliar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ACM_CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ fecha_inicio_cobertura: fecha, progreso: prog.id }),
        });
        data = await res.json();
    } catch (e) {
        // No se reintenta solo: el ingreso pudo quedar radicado y repetirlo
        // crearía un duplicado en Colmena.
        data = { ok: false, mensaje: 'Se perdió la conexión con el servidor. Antes de reintentar, revisa en el portal de Colmena si el ingreso quedó radicado.' };
    }

    prog.parar();
    document.getElementById('acmCargando').style.display = 'none';

    if (data.ok) {
        const caja = document.getElementById('acmResultado');
        caja.innerHTML = `✅ <strong>${data.mensaje}</strong><br>` +
            `Radicación <strong>${data.codigo_transaccion ?? '—'}</strong> · vigencia desde <strong>${data.fecha_display}</strong>` +
            (data.aviso ? `<br><span style="color:#b45309">⚠️ ${data.aviso}</span>` : '');
        caja.style.display = 'block';
        setTimeout(() => location.reload(), 3500);
    } else {
        document.getElementById('acmContenido').style.display = 'block';
        btn.disabled = false; btn.textContent = '🐝 Reintentar';
        alert(data.mensaje || 'No se pudo afiliar.');
    }
}

async function anularColmena(contratoId, btn) {
    if (!confirm('¿Anular el ingreso en ARL Colmena?\n\nLa vigencia desaparece —no queda como retiro— y el radicado vuelve a pendiente. ' +
                 'Colmena solo lo permite hasta un día calendario después de que empieza la vigencia.')) return;

    const textoOriginal = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Anulando...'; }

    let data;
    try {
        const res = await fetch(`/admin/afiliaciones/${contratoId}/colmena/anular`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ACM_CSRF, 'Accept': 'application/json' },
        });
        data = await res.json();
    } catch (e) {
        data = { ok: false, mensaje: 'No se pudo conectar con el servidor.' };
    }

    if (btn) { btn.disabled = false; btn.textContent = textoOriginal; }

    alert(data.mensaje || (data.ok ? 'Listo.' : 'No se pudo anular.'));
    if (data.ok) location.reload();
}
</script>
