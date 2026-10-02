{{--
    Portales de entidades de una razón social: qué EPS, ARL y caja tienen
    clave, cuáles se trabajan con el asesor por correo y cuáles faltan.

    Lo usan la pestaña «Portales» de la ficha de la razón social y el panel 🔑
    de Afiliaciones. Se incluye una sola vez por página y se monta con
        Portales.montar(elemento, rsId, { eps, arl, caja }, { soloConDatos })
    donde eps/arl/caja (opcionales) son las del contrato, para resaltarlas, y
    soloConDatos deja fuera las entidades sin clave ni asesor (el panel de
    Afiliaciones es para entrar a los portales; lo que falta se llena en la
    ficha de la razón social). La del contrato se muestra igual si falta.
    Los datos salen de PortalEntidadController.
--}}
@once
<style>
.pe-resumen{display:flex;flex-wrap:wrap;align-items:baseline;gap:.5rem;margin-bottom:.6rem}
.pe-cifra{font-size:.8rem;font-weight:800;color:#0f172a}
.pe-cifra.mal{color:#b91c1c}
.pe-cifra.bien{color:#15803d}
.pe-barra{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem;margin-bottom:.4rem}
.pe-buscar{height:32px;padding:0 .65rem 0 1.9rem;border:1.5px solid #cbd5e1;border-radius:8px;font-size:.8rem;width:210px;max-width:100%;box-sizing:border-box;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%2394a3b8' stroke-width='2' viewBox='0 0 24 24'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m20 20-3.5-3.5'/%3E%3C/svg%3E") no-repeat .6rem center}
.pe-buscar:focus{outline:none;border-color:#3b82f6}
.pe-filtro{border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:20px;padding:.25rem .7rem;font-size:.72rem;font-weight:700;cursor:pointer}
.pe-filtro.activo{background:#0f172a;border-color:#0f172a;color:#fff}
.pe-sep{width:1px;height:20px;background:#e2e8f0;margin:0 .15rem}
.pe-grupo{font-size:.66rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:.9rem 0 .35rem}
.pe-aviso{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:.45rem .7rem;font-size:.72rem;margin-bottom:.4rem}
.pe-tabla{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.15rem}
.pe-fila{display:grid;grid-template-columns:118px minmax(0,1.5fr) minmax(0,1.1fr) minmax(0,.9fr) minmax(0,1.3fr) 36px 70px;gap:.6rem;align-items:center;padding:.45rem .55rem;border:1px solid transparent;border-bottom-color:#f1f5f9;font-size:.77rem}
.pe-fila:last-child{border-bottom-color:transparent}
.pe-fila.falta{border:1.5px solid #fca5a5;border-radius:8px;background:#fffafa;margin:.2rem 0}
.pe-fila.contrato{background:#eff6ff;box-shadow:inset 3px 0 0 #2563eb}
.pe-fila.falta.contrato{background:#fff1f2}
.pe-estado{display:inline-flex;align-items:center;gap:.3rem;border-radius:20px;padding:.12rem .5rem;font-size:.66rem;font-weight:800;white-space:nowrap;justify-self:start}
.pe-estado.portal{background:#dcfce7;color:#15803d}
.pe-estado.portal_correo{background:#dbeafe;color:#1d4ed8}
.pe-estado.correo{background:#fef9c3;color:#854d0e}
.pe-estado.falta{background:#fee2e2;color:#b91c1c}
.pe-estado.no_aplica{background:#f1f5f9;color:#64748b}
.pe-nombre{font-weight:700;color:#0f172a}
.pe-tipo{display:inline-block;background:#f1f5f9;color:#64748b;border-radius:4px;padding:0 .3rem;font-size:.58rem;font-weight:800;margin-right:.3rem;vertical-align:1px}
.pe-sub{font-size:.65rem;color:#94a3b8;margin-top:.05rem}
.pe-chip{display:inline-block;border-radius:5px;padding:.02rem .35rem;font-size:.6rem;font-weight:800;margin-left:.25rem;vertical-align:middle}
.pe-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.74rem;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pe-vacio{color:#cbd5e1}
.pe-ojo{border:none;background:none;cursor:pointer;color:#64748b;padding:0 .25rem;vertical-align:middle;line-height:1}
.pe-ojo:hover{color:#0f172a}
.pe-ojo svg{width:16px;height:16px;display:block}
.pe-btn{border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:.2rem .5rem;font-size:.7rem;font-weight:700;cursor:pointer;color:#334155;white-space:nowrap}
.pe-btn.llenar{background:#fef2f2;border-color:#fca5a5;color:#b91c1c}
.pe-msg{padding:1.2rem;text-align:center;color:#94a3b8;font-size:.8rem}
@media (max-width:720px){
  .pe-fila{grid-template-columns:minmax(0,1fr) auto;row-gap:.3rem}
  .pe-fila>*{grid-column:1/-1}
  .pe-fila>:nth-child(1){grid-column:1;grid-row:1}
  .pe-fila>:last-child{grid-column:2;grid-row:1}
  .pe-fila>:nth-child(6):empty{display:none}
}

.pe-ov{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:11000;align-items:flex-start;justify-content:center;padding:2rem 1rem;overflow-y:auto}
.pe-box{background:#fff;border-radius:14px;max-width:560px;width:100%;box-shadow:0 20px 45px rgba(0,0,0,.3);overflow:hidden}
.pe-head{background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center}
.pe-body{padding:1rem 1.1rem}
.pe-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.pe-full{grid-column:1/-1}
.pe-lbl{display:block;font-size:.64rem;font-weight:700;color:#475569;margin-bottom:.15rem;text-transform:uppercase;letter-spacing:.02em}
.pe-inp{width:100%;padding:.4rem .55rem;border:1.5px solid #cbd5e1;border-radius:7px;font-size:.82rem;box-sizing:border-box}
.pe-inp:focus{outline:none;border-color:#3b82f6}
.pe-sec{font-size:.68rem;font-weight:800;color:#0f172a;margin:.9rem 0 .4rem;padding-bottom:.25rem;border-bottom:1.5px solid #f1f5f9}
.pe-check{display:flex;align-items:flex-start;gap:.45rem;font-size:.76rem;color:#334155;cursor:pointer;margin-top:.5rem}
.pe-foot{display:flex;justify-content:space-between;align-items:center;gap:.6rem;padding:.75rem 1.1rem;border-top:1px solid #f1f5f9;background:#f8fafc}
.pe-error{display:none;background:#fee2e2;color:#b91c1c;border-radius:7px;padding:.45rem .65rem;font-size:.75rem;margin-bottom:.6rem}
@media (max-width:640px){.pe-grid{grid-template-columns:1fr}.pe-filtros{margin-left:0}}
</style>

<div id="peModal" class="pe-ov" onclick="if(event.target===this) Portales.cerrar()">
    <div class="pe-box">
        <div class="pe-head">
            <div>
                <div style="font-size:.9rem;font-weight:800" id="peTitulo">Portal</div>
                <div style="font-size:.7rem;color:#cbd5e1" id="peSubtitulo"></div>
            </div>
            <button type="button" onclick="Portales.cerrar()" style="background:none;border:none;color:#cbd5e1;font-size:1.2rem;cursor:pointer">✕</button>
        </div>
        <form id="peForm" onsubmit="Portales.guardar(event)">
            <div class="pe-body">
                <div class="pe-error" id="peError"></div>
                <div class="pe-aviso" id="peSura" style="display:none"></div>

                {{-- Solo para correos, operadores y otros portales fuera del catálogo --}}
                <div id="peLibre" class="pe-grid" style="margin-bottom:.6rem">
                    <div>
                        <label class="pe-lbl">Tipo</label>
                        <select class="pe-inp" name="libre_tipo">
                            <option value="Correo">Correo</option>
                            <option value="Operadores">Operador de planilla</option>
                            <option value="Portal">Otro portal</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label class="pe-lbl">Nombre</label>
                        <input class="pe-inp" name="libre_entidad" maxlength="150" placeholder="Gmail, Aportes en Línea…">
                    </div>
                </div>

                <div class="pe-sec" style="margin-top:0" id="pePortalTitulo">🔐 Portal de empleador</div>
                <div class="pe-grid">
                    <div>
                        <label class="pe-lbl">Usuario</label>
                        <input class="pe-inp" name="usuario" maxlength="150" autocomplete="off">
                    </div>
                    <div>
                        <label class="pe-lbl">Contraseña</label>
                        <input class="pe-inp" name="contrasena" maxlength="200" autocomplete="new-password" placeholder="">
                    </div>
                    <div class="pe-full">
                        <label class="pe-lbl">Link para entrar</label>
                        <input class="pe-inp" name="link_acceso" maxlength="350" placeholder="https://…">
                    </div>
                </div>
                {{-- El asesor no aplica a portales como el SAT: ahí no hay a quién escribir. --}}
                <div id="peAsesor">
                    <label class="pe-check">
                        <input type="checkbox" name="sin_portal" value="1">
                        <span>Esta entidad <strong>no tiene portal</strong> para la empresa: se afilia por correo con el asesor.</span>
                    </label>

                    <div class="pe-sec">✉️ Asesor <span style="font-weight:500;color:#94a3b8">— si el portal falla, la afiliación va por correo</span></div>
                    <div class="pe-grid">
                        <div class="pe-full">
                            <label class="pe-lbl">Nombre</label>
                            <input class="pe-inp" name="asesor_nombre" maxlength="150">
                        </div>
                        <div>
                            <label class="pe-lbl">Correo</label>
                            <input class="pe-inp" name="asesor_correo" type="email" maxlength="150">
                        </div>
                        <div>
                            <label class="pe-lbl">Teléfono</label>
                            <input class="pe-inp" name="asesor_telefono" maxlength="50">
                        </div>
                    </div>
                    <details id="peReemplazo" style="margin-top:.55rem">
                        <summary style="font-size:.72rem;font-weight:700;color:#475569;cursor:pointer">Asesor de reemplazo (vacaciones)</summary>
                        <div class="pe-grid" style="margin-top:.45rem">
                            <div class="pe-full">
                                <label class="pe-lbl">Nombre</label>
                                <input class="pe-inp" name="asesor2_nombre" maxlength="150">
                            </div>
                            <div>
                                <label class="pe-lbl">Correo</label>
                                <input class="pe-inp" name="asesor2_correo" type="email" maxlength="150">
                            </div>
                            <div>
                                <label class="pe-lbl">Teléfono</label>
                                <input class="pe-inp" name="asesor2_telefono" maxlength="50">
                            </div>
                        </div>
                    </details>

                </div>

                <div class="pe-sec">📝 Otros</div>
                <label class="pe-lbl">Observación</label>
                <input class="pe-inp" name="observacion" maxlength="300">
                <label class="pe-check" id="peNoAplica">
                    <input type="checkbox" name="no_aplica" value="1">
                    <span><strong>No aplica</strong> para esta empresa (no trabaja con esta entidad).</span>
                </label>
            </div>
            <div class="pe-foot">
                <div style="font-size:.66rem;color:#94a3b8" id="peOrigen"></div>
                <div style="display:flex;gap:.5rem">
                    <button type="button" class="pe-btn" id="peEliminar" style="color:#b91c1c;display:none" onclick="Portales.eliminar()">🗑 Eliminar</button>
                    <button type="button" class="pe-btn" onclick="Portales.cerrar()">Cancelar</button>
                    <button type="submit" id="peGuardar" style="padding:.42rem 1.1rem;background:#2563eb;border:none;border-radius:8px;color:#fff;font-size:.8rem;font-weight:700;cursor:pointer">💾 Guardar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
window.Portales = (function () {
    const URL_BASE = @json(url('admin/clave-accesos/portales'));
    const ESTADOS = {
        portal:        '🟢 Portal',
        portal_correo: '🔵 Portal + correo',
        correo:        '🟡 Solo correo',
        falta:         '🔴 Falta',
        no_aplica:     '⚪ No aplica',
    };
    const GRUPOS = { ARL: '🛡️ ARL', CAJA: '🏠 Caja de compensación', OTRO: '🏛️ SAT (MinSalud)', EPS: '🏥 EPS',
        CORREO: '✉️ Correos', OTRAS: '🧾 Operadores y otros portales' };
    const ORDEN = ['ARL', 'CAJA', 'OTRO', 'EPS'];
    const CORTO = { OTRO: 'SAT' };

    let el = null, rsId = null, contrato = {}, datos = null, editando = null, soloConDatos = false, alVedar = null;
    // Filtros: tipo (''/ARL/CAJA/EPS), texto, solo las que faltan, solo las que la empresa usa.
    let filtro = { tipo: '', texto: '', faltan: false, usadas: false };

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
    const plano = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9 ]/gi, '').toLowerCase();
    const OJO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
    const OJO_NO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-2.2 3.2M6.6 6.6C3.8 8.3 2 12 2 12s3.5 7 10 7c1.9 0 3.6-.6 5-1.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m3 3 18 18"/></svg>';

    function montar(contenedor, id, delContrato, opciones) {
        el = typeof contenedor === 'string' ? document.getElementById(contenedor) : contenedor;
        rsId = id;
        contrato = delContrato || {};
        soloConDatos = !!opciones?.soloConDatos;
        alVedar = opciones?.alVedar || null;
        filtro = { tipo: '', texto: '', faltan: false, usadas: false };
        datos = null;
        el.innerHTML = '<div class="pe-msg">⏳ Cargando portales…</div>';
        recargar();
    }

    function recargar() {
        if (!el) return Promise.resolve();
        const q = new URLSearchParams();
        ['eps', 'arl', 'caja'].forEach(k => contrato[k] && q.set(k, contrato[k]));
        return fetch(`${URL_BASE}/${rsId}?${q}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(async r => {
                if (r.ok) return r.json();
                // Empresa prestada sin permiso de claves: quien la abrió decide qué más ocultar.
                if (r.status === 403 && alVedar) alVedar();
                return Promise.reject(r.status === 403 ? ((await r.json().catch(() => ({}))).message || 'Sin acceso.') : `No se pudieron cargar los portales (${r.status}).`);
            })
            .then(d => { const primera = !datos; datos = d; if (primera) esqueleto(); pintar(); })
            .catch(e => { el.innerHTML = `<div class="pe-msg">🔒 ${esc(e)}</div>`; datos = null; });
    }

    // La barra se pinta una sola vez: si se rehiciera con cada letra, el
    // buscador perdería el foco mientras se escribe.
    function esqueleto() {
        // El chip del SAT solo si el servidor lo manda como fila (hoy está oculto).
        const hayOtro = datos.filas.some(f => f.tipo === 'OTRO');
        const tipos = [['', 'Todas'], ['ARL', 'ARL'], ['CAJA', 'Caja'], ...(hayOtro ? [['OTRO', 'SAT']] : []), ['EPS', 'EPS'], ['CORREO', 'Correos'], ['OTRAS', 'Operadores']];
        el.innerHTML = `<div class="pe-resumen" data-pe="resumen"></div>
            <div class="pe-barra">
                <input type="search" class="pe-buscar" placeholder="Buscar entidad…" data-pe="buscar" autocomplete="off">
                ${tipos.map(([k, t]) => `<button type="button" class="pe-filtro ${k === '' ? 'activo' : ''}" data-tipo="${k}">${t}</button>`).join('')}
                <span class="pe-sep"></span>
                ${soloConDatos ? '' : '<button type="button" class="pe-filtro" data-solo="faltan">Solo faltan</button>'}
                <button type="button" class="pe-filtro" data-solo="usadas">Con afiliados</button>
            </div>
            <div data-pe="lista"></div>`;

        el.querySelector('[data-pe="buscar"]').addEventListener('input', e => { filtro.texto = e.target.value; pintar(); });
        el.querySelectorAll('[data-tipo]').forEach(b => b.addEventListener('click', () => {
            filtro.tipo = b.dataset.tipo;
            el.querySelectorAll('[data-tipo]').forEach(x => x.classList.toggle('activo', x === b));
            pintar();
        }));
        el.querySelectorAll('[data-solo]').forEach(b => b.addEventListener('click', () => {
            filtro[b.dataset.solo] = !filtro[b.dataset.solo];
            b.classList.toggle('activo', filtro[b.dataset.solo]);
            pintar();
        }));
    }

    function pasaFiltro(f) {
        // El SAT se muestra siempre, también en el panel de Afiliaciones.
        if (soloConDatos && ['falta', 'no_aplica'].includes(f.estado) && !f.del_contrato && f.tipo !== 'OTRO') return false;
        if (filtro.tipo && f.tipo !== filtro.tipo) return false;
        if (filtro.faltan && f.estado !== 'falta') return false;
        if (filtro.usadas && !(f.tipo !== 'EPS' || f.afiliados > 0 || f.del_contrato)) return false;
        const q = plano(filtro.texto).trim();
        return !q || plano(f.nombre + ' ' + (CORTO[f.tipo] || '')).includes(q);
    }

    function pintar() {
        if (!el || !datos) return;
        const r = datos.resumen;
        el.querySelector('[data-pe="resumen"]').innerHTML = `
            <span class="pe-cifra ${r.faltan_con_afiliados ? 'mal' : 'bien'}">${r.faltan_con_afiliados
                ? `🔴 Faltan ${r.faltan_con_afiliados} que la empresa usa`
                : '✅ Las entidades que la empresa usa tienen datos'}</span>
            <span class="pe-sub" style="margin:0">${soloConDatos
                ? 'Aquí solo se ven las que tienen datos; las que faltan se llenan en la pestaña Portales de la razón social.'
                : `${r.faltan} de ${r.total} sin datos en total`}</span>`;

        let h = '';
        let hay = 0;
        ORDEN.forEach(tipo => {
            if (filtro.tipo && filtro.tipo !== tipo) return;
            const filas = datos.filas.filter(f => f.tipo === tipo && pasaFiltro(f));
            const aviso = tipo === 'ARL' && datos.sin_arl ? 'La empresa no tiene ARL configurada (pestaña Datos generales).'
                : tipo === 'CAJA' && datos.sin_caja ? 'La empresa no tiene caja configurada (pestaña Datos generales).' : '';
            // Buscando, solo se muestran los grupos con resultados.
            if (!filas.length && (filtro.texto || filtro.faltan || filtro.usadas || !aviso)) return;
            hay += filas.length;
            h += `<div class="pe-grupo">${GRUPOS[tipo]}${filas.length ? ` · ${filas.length}` : ''}</div>`;
            if (aviso) h += `<div class="pe-aviso">${aviso}</div>`;
            if (filas.length) h += '<div class="pe-tabla">' + filas.map(fila).join('') + '</div>';
        });

        // Correos, operadores y otros portales: no son de una entidad, pero
        // van en la misma tabla (antes estaban en la lista vieja de claves).
        if (!filtro.faltan) {
            const q = plano(filtro.texto).trim();
            ['CORREO', 'OTRAS'].forEach(grupo => {
                if (filtro.tipo && filtro.tipo !== grupo) return;
                const otras = (datos.otras || []).filter(c => c.grupo === grupo && (!q || plano(c.entidad + ' ' + c.tipo + ' ' + (c.usuario || '')).includes(q)));
                // Vacío, el grupo sale igual (con su botón de agregar) para quien puede gestionar.
                if (!otras.length && (q || (filtro.tipo && filtro.tipo !== grupo) || !datos.puede_gestionar)) return;
                hay += otras.length;
                h += `<div class="pe-grupo">${GRUPOS[grupo]}${otras.length ? ` · ${otras.length}` : ''}</div>`;
                if (otras.length) h += '<div class="pe-tabla">' + otras.map(otra).join('') + '</div>';
                if (datos.puede_gestionar && !q) {
                    h += `<button type="button" class="pe-btn" style="margin-top:.35rem" onclick="Portales.editarOtra(null, '${grupo === 'CORREO' ? 'Correo' : 'Operadores'}')">➕ Agregar ${grupo === 'CORREO' ? 'correo' : 'operador u otro portal'}</button>`;
                }
            });
        }

        if (!hay && (filtro.texto || filtro.faltan || filtro.usadas || filtro.tipo)) {
            h += '<div class="pe-msg">Ninguna entidad con ese filtro.</div>';
        }

        if (datos.sin_clasificar.length && !filtro.texto && !filtro.tipo && !soloConDatos) {
            h += `<div class="pe-grupo">❓ Claves sin clasificar</div>
                <div class="pe-aviso">Se guardaron con un nombre que no dice la entidad (p. ej. «EPS» o «Caja»). Asígnalas para que cuenten arriba.</div>
                <div class="pe-tabla">${datos.sin_clasificar.map(sinClasificar).join('')}</div>`;
        }

        el.querySelector('[data-pe="lista"]').innerHTML = h;
    }

    function claveHtml(pass) {
        if (!pass) return '<span class="pe-vacio">—</span>';
        if (pass === '__oculta__') return '<span class="pe-sub" style="margin:0" title="No tienes permiso para ver contraseñas">🔒 guardada</span>';
        return `<span style="display:inline-flex;align-items:center;max-width:100%"><span class="pe-mono" data-p="${esc(pass)}">••••••</span><button type="button" class="pe-ojo" title="Ver clave" aria-label="Ver clave" onclick="Portales.ojo(this)">${OJO}</button></span>`;
    }

    function ojo(btn) {
        const span = btn.previousElementSibling;
        const ver = span.textContent === '••••••';
        span.textContent = ver ? span.dataset.p : '••••••';
        btn.innerHTML = ver ? OJO_NO : OJO;
        btn.title = ver ? 'Ocultar clave' : 'Ver clave';
    }

    function fila(f) {
        const c = f.clave || {};
        const usuario = c.usuario || f.sura?.usuario;
        const pass = c.contrasena || f.sura?.contrasena;
        let asesor = [c.asesor_nombre, c.asesor_correo, c.asesor_telefono].filter(Boolean);
        const general = !asesor.length && f.asesor_general;
        if (general) asesor = [f.asesor_general.nombre, f.asesor_general.correo];
        const sub = [];
        if (f.tipo === 'EPS') sub.push(f.afiliados ? `${f.afiliados} afiliado${f.afiliados === 1 ? '' : 's'} activo${f.afiliados === 1 ? '' : 's'}` : 'sin afiliados');
        if (['ARL', 'CAJA'].includes(f.tipo) && !f.configurada) sub.push('no es la configurada en la empresa');
        if (f.tipo === 'OTRO' && !c.usuario) sub.push('entra el representante legal o un delegado, con su cédula');
        if (c.sin_portal) sub.push('sin portal');
        if (f.sura && !c.usuario) sub.push('usuario del portal de Sura');
        if (f.sura?.error) sub.push('⚠️ ' + f.sura.error);
        if (c.cargada_por) sub.push('↔ ' + c.cargada_por);
        if (f.otras) sub.push(`+${f.otras} clave${f.otras === 1 ? '' : 's'} más`);

        const llenar = f.estado === 'falta';
        const btn = datos.puede_gestionar
            ? `<button type="button" class="pe-btn ${llenar ? 'llenar' : ''}" onclick="Portales.editar('${f.tipo}', ${f.id})">${llenar ? '➕ Llenar' : '✏️ Editar'}</button>`
            : '';

        return `<div class="pe-fila ${llenar ? 'falta' : ''} ${f.del_contrato ? 'contrato' : ''}">
            <span class="pe-estado ${f.estado}">${ESTADOS[f.estado]}</span>
            <div style="min-width:0">
                <div class="pe-nombre"><span class="pe-tipo">${CORTO[f.tipo] || f.tipo}</span>${esc(f.nombre)}${f.del_contrato ? '<span class="pe-chip" style="background:#2563eb;color:#fff">DEL CONTRATO</span>' : ''}</div>
                ${sub.length ? `<div class="pe-sub">${sub.map(esc).join(' · ')}</div>` : ''}
            </div>
            <div class="pe-mono" title="${esc(usuario || '')}">${usuario ? esc(usuario) : '<span class="pe-vacio">—</span>'}</div>
            <div style="min-width:0">${claveHtml(pass)}</div>
            <div style="font-size:.72rem;color:#475569;min-width:0;overflow:hidden;text-overflow:ellipsis">${asesor.length ? asesor.map(esc).join('<br>') : '<span class="pe-vacio">—</span>'}${general ? '<div class="pe-sub">asesor general</div>' : ''}</div>
            <div>${c.link_acceso ? `<a href="${esc(c.link_acceso)}" target="_blank" rel="noopener" class="pe-btn" style="text-decoration:none" title="Abrir el portal">🔗</a>` : ''}</div>
            <div style="text-align:right">${btn}</div>
        </div>`;
    }

    function otra(c) {
        const sub = [c.tipo];
        if (c.cargada_por) sub.push('↔ ' + c.cargada_por);
        return `<div class="pe-fila">
            <span class="pe-estado portal">🔑 Guardada</span>
            <div style="min-width:0">
                <div class="pe-nombre">${esc(c.entidad)}</div>
                <div class="pe-sub">${sub.map(esc).join(' · ')}</div>
            </div>
            <div class="pe-mono" title="${esc(c.usuario || '')}">${c.usuario ? esc(c.usuario) : '<span class="pe-vacio">—</span>'}</div>
            <div style="min-width:0">${claveHtml(c.contrasena)}</div>
            <div style="font-size:.72rem;color:#475569;min-width:0;overflow:hidden;text-overflow:ellipsis" title="${esc(c.observacion || '')}">${c.observacion ? esc(c.observacion) : '<span class="pe-vacio">—</span>'}</div>
            <div>${c.link_acceso ? `<a href="${esc(c.link_acceso)}" target="_blank" rel="noopener" class="pe-btn" style="text-decoration:none" title="Abrir el portal">🔗</a>` : ''}</div>
            <div style="text-align:right">${datos.puede_gestionar ? `<button type="button" class="pe-btn" onclick="Portales.editarOtra(${c.id})">✏️ Editar</button>` : ''}</div>
        </div>`;
    }

    function sinClasificar(c) {
        const opciones = ['EPS', 'ARL', 'CAJA'].map(t =>
            `<optgroup label="${t}">${(datos.catalogo[t] || []).map(e => `<option value="${t}|${e.id}">${esc(e.nombre)}</option>`).join('')}</optgroup>`
        ).join('') + (datos.filas.some(f => f.tipo === 'OTRO' && f.id === 1) ? '<option value="OTRO|1">SAT (Sistema de Afiliación Transaccional)</option>' : '')
            + '<option value="OTRO|">Otro portal (no es EPS, ARL ni caja)</option>';

        return `<div class="pe-fila">
            <span class="pe-chip" style="background:#f1f5f9;color:#475569;margin:0;justify-self:start">${esc(c.tipo)}</span>
            <div style="min-width:0"><div class="pe-nombre">${esc(c.entidad)}</div>${c.cargada_por ? `<div class="pe-sub">↔ ${esc(c.cargada_por)}</div>` : ''}</div>
            <div class="pe-mono">${c.usuario ? esc(c.usuario) : '<span class="pe-vacio">—</span>'}</div>
            <div style="font-size:.7rem;color:#64748b">${esc(c.observacion || '')}</div>
            <div style="grid-column:span 3;text-align:right;white-space:nowrap">
                ${datos.puede_gestionar ? `<select class="pe-inp" style="width:auto;max-width:170px;display:inline-block;font-size:.72rem;padding:.2rem .35rem" id="peAsig${c.id}">
                    <option value="">Asignar a…</option>${opciones}</select>
                <button type="button" class="pe-btn" onclick="Portales.asignar(${c.id})">OK</button>` : ''}
            </div>
        </div>`;
    }

    function editar(tipo, id) {
        const f = datos.filas.find(x => x.tipo === tipo && x.id === id);
        if (!f) return;
        editando = f;
        const c = f.clave || {};
        const form = document.getElementById('peForm');
        form.reset();
        modoLibre(false, c);
        document.getElementById('peError').style.display = 'none';
        document.getElementById('peTitulo').textContent = `${GRUPOS[tipo]} · ${f.nombre}`;
        document.getElementById('peSubtitulo').textContent = datos.razon_social.nombre + (datos.razon_social.nit ? ` · NIT ${datos.razon_social.nit}` : '');

        ['usuario', 'link_acceso', 'observacion', 'asesor_nombre', 'asesor_correo', 'asesor_telefono',
         'asesor2_nombre', 'asesor2_correo', 'asesor2_telefono'].forEach(k => form.elements[k].value = c[k] || '');
        if (!c.usuario && f.sura) form.elements.usuario.value = f.sura.usuario || '';
        // La contraseña no se precarga: en blanco conserva la que hay.
        form.elements.contrasena.value = '';
        form.elements.contrasena.placeholder = (c.contrasena || f.sura?.contrasena) ? 'En blanco conserva la actual' : '';
        form.elements.sin_portal.checked = !!c.sin_portal;
        form.elements.no_aplica.checked = !!c.no_aplica;
        document.getElementById('peReemplazo').open = !!(c.asesor2_nombre || c.asesor2_correo);
        document.getElementById('peAsesor').style.display = tipo === 'OTRO' ? 'none' : '';

        const sura = document.getElementById('peSura');
        sura.style.display = f.sura || (tipo === 'ARL' && id === 3) ? 'block' : 'none';
        sura.innerHTML = 'En ARL Sura la clave es <strong>del usuario</strong> que entra al portal: si la cambias aquí, queda al día en todas las empresas que ese usuario administra.';

        document.getElementById('peOrigen').textContent = c.id
            ? `Clave #${c.id}${c.cargada_por ? ' · la cargó ' + c.cargada_por : ''}${c.actualizada ? ' · ' + c.actualizada : ''}`
            : 'Nueva';
        document.getElementById('peModal').style.display = 'flex';
        setTimeout(() => form.elements.usuario.focus(), 50);
    }

    function cerrar() {
        document.getElementById('peModal').style.display = 'none';
        editando = null;
    }

    // Correos, operadores y otros portales: el mismo formulario sin asesor ni «no aplica».
    function editarOtra(id, tipoNuevo) {
        const c = (datos.otras || []).find(x => x.id === id) || { tipo: tipoNuevo };
        editando = { libre: true, clave: c };
        const form = document.getElementById('peForm');
        form.reset();
        modoLibre(true, c);
        document.getElementById('peError').style.display = 'none';
        document.getElementById('peTitulo').textContent = c.id ? `${c.entidad}` : 'Nueva clave';
        document.getElementById('peSubtitulo').textContent = datos.razon_social.nombre + (datos.razon_social.nit ? ` · NIT ${datos.razon_social.nit}` : '');
        const tipos = [...form.elements.libre_tipo.options].map(o => o.value);
        if (c.tipo && !tipos.includes(c.tipo)) form.elements.libre_tipo.add(new Option(c.tipo, c.tipo));
        form.elements.libre_tipo.value = c.tipo || 'Correo';
        form.elements.libre_entidad.value = c.entidad || '';
        ['usuario', 'link_acceso', 'observacion'].forEach(k => form.elements[k].value = c[k] || '');
        form.elements.contrasena.value = '';
        form.elements.contrasena.placeholder = c.contrasena ? 'En blanco conserva la actual' : '';
        document.getElementById('peSura').style.display = 'none';
        document.getElementById('peOrigen').textContent = c.id
            ? `Clave #${c.id}${c.cargada_por ? ' · la cargó ' + c.cargada_por : ''}${c.actualizada ? ' · ' + c.actualizada : ''}`
            : 'Nueva';
        document.getElementById('peModal').style.display = 'flex';
        setTimeout(() => (c.id ? form.elements.usuario : form.elements.libre_entidad).focus(), 50);
    }

    function modoLibre(libre, c) {
        document.getElementById('peLibre').style.display = libre ? '' : 'none';
        document.getElementById('pePortalTitulo').style.display = libre ? 'none' : '';
        document.getElementById('peNoAplica').style.display = libre ? 'none' : '';
        if (libre) document.getElementById('peAsesor').style.display = 'none';
        document.getElementById('peEliminar').style.display = datos.puede_eliminar && c?.id ? '' : 'none';
    }

    function eliminar() {
        const c = editando?.libre ? editando.clave : editando?.clave;
        if (!c?.id || !confirm('¿Eliminar esta clave? Desaparece para todos los aliados que comparten la empresa.')) return;
        fetch(`${URL_BASE.replace(/\/portales$/, '')}/${c.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        }).then(async r => {
            const d = await r.json().catch(() => ({}));
            if (!r.ok) throw (r.status === 404 ? 'Solo la puede eliminar el aliado que la cargó.' : (d.message || `Error ${r.status}`));
            cerrar(); recargar().then(() => avisar('Clave eliminada.'));
        }).catch(msg => {
            const e = document.getElementById('peError');
            e.textContent = msg; e.style.display = 'block';
        });
    }

    function guardar(ev) {
        ev.preventDefault();
        if (!editando) return;
        const form = ev.target;
        if (editando.libre) return guardarOtra(form);
        const fd = new FormData(form);
        fd.set('tipo', editando.tipo);
        fd.set('entidad_id', editando.id);
        fd.set('sin_portal', form.elements.sin_portal.checked ? '1' : '0');
        fd.set('no_aplica', form.elements.no_aplica.checked ? '1' : '0');

        const btn = document.getElementById('peGuardar');
        btn.disabled = true; btn.textContent = 'Guardando…';
        enviar(`${URL_BASE}/${rsId}`, fd)
            .then(d => { cerrar(); recargar().then(() => avisar(d.message)); })
            .catch(msg => {
                const e = document.getElementById('peError');
                e.textContent = msg; e.style.display = 'block';
            })
            .finally(() => { btn.disabled = false; btn.textContent = '💾 Guardar'; });
    }

    function guardarOtra(form) {
        const fd = new FormData();
        if (editando.clave.id) fd.set('clave_id', editando.clave.id);
        fd.set('tipo', form.elements.libre_tipo.value);
        fd.set('entidad', form.elements.libre_entidad.value);
        ['usuario', 'contrasena', 'link_acceso', 'observacion'].forEach(k => fd.set(k, form.elements[k].value));
        const btn = document.getElementById('peGuardar');
        btn.disabled = true; btn.textContent = 'Guardando…';
        enviar(`${URL_BASE}/${rsId}/otra`, fd)
            .then(d => { cerrar(); recargar().then(() => avisar(d.message)); })
            .catch(msg => {
                const e = document.getElementById('peError');
                e.textContent = msg; e.style.display = 'block';
            })
            .finally(() => { btn.disabled = false; btn.textContent = '💾 Guardar'; });
    }

    function asignar(claveId) {
        const v = document.getElementById('peAsig' + claveId)?.value;
        if (!v) return;
        const [tipo, entidad] = v.split('|');
        const fd = new FormData();
        fd.set('clave_id', claveId);
        fd.set('tipo', tipo);
        if (entidad) fd.set('entidad_id', entidad);
        enviar(`${URL_BASE}/${rsId}/asignar`, fd)
            .then(d => recargar().then(() => avisar(d.message)))
            .catch(msg => alert(msg));
    }

    function enviar(url, fd) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd,
        }).then(async r => {
            const d = await r.json().catch(() => ({}));
            if (!r.ok) {
                const errores = d.errors ? Object.values(d.errors).flat().join(' ') : null;
                return Promise.reject(errores || d.message || `Error ${r.status}`);
            }
            return d;
        });
    }

    function avisar(msg) {
        if (!el || !msg) return;
        const n = document.createElement('div');
        n.style.cssText = 'background:#dcfce7;color:#15803d;border-radius:8px;padding:.45rem .7rem;font-size:.76rem;font-weight:700;margin-bottom:.6rem';
        n.textContent = '✅ ' + msg;
        el.prepend(n);
        setTimeout(() => n.remove(), 4000);
    }

    return { montar, recargar, editar, editarOtra, eliminar, cerrar, guardar, asignar, ojo };
})();
</script>
@endonce
