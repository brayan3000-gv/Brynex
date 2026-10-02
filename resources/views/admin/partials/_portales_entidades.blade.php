{{--
    Portales de entidades de una razón social: qué EPS, ARL y caja tienen
    clave, cuáles se trabajan con el asesor por correo y cuáles faltan.

    Lo usan la pestaña «Portales» de la ficha de la razón social y el panel 🔑
    de Afiliaciones. Se incluye una sola vez por página y se monta con
        Portales.montar(elemento, rsId, { eps, arl, caja })
    donde eps/arl/caja (opcionales) son las del contrato, para resaltarlas.
    Los datos salen de PortalEntidadController.
--}}
@once
<style>
.pe-resumen{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-bottom:.8rem}
.pe-cifra{font-size:.8rem;font-weight:800;color:#0f172a}
.pe-cifra.mal{color:#b91c1c}
.pe-cifra.bien{color:#15803d}
.pe-filtros{display:flex;gap:.3rem;margin-left:auto}
.pe-filtro{border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:20px;padding:.2rem .65rem;font-size:.7rem;font-weight:700;cursor:pointer}
.pe-filtro.activo{background:#0f172a;border-color:#0f172a;color:#fff}
.pe-grupo{font-size:.66rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:.9rem 0 .35rem}
.pe-aviso{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:.45rem .7rem;font-size:.72rem;margin-bottom:.4rem}
.pe-tabla{width:100%;border-collapse:collapse;font-size:.77rem;background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
.pe-tabla td{padding:.42rem .6rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.pe-tabla tr:last-child td{border-bottom:none}
.pe-tabla tr.contrato td{background:#eff6ff}
.pe-tabla tr.contrato td:first-child{box-shadow:inset 3px 0 0 #2563eb}
.pe-estado{display:inline-flex;align-items:center;gap:.3rem;border-radius:20px;padding:.12rem .5rem;font-size:.66rem;font-weight:800;white-space:nowrap}
.pe-estado.portal{background:#dcfce7;color:#15803d}
.pe-estado.portal_correo{background:#dbeafe;color:#1d4ed8}
.pe-estado.correo{background:#fef9c3;color:#854d0e}
.pe-estado.falta{background:#fee2e2;color:#b91c1c}
.pe-estado.no_aplica{background:#f1f5f9;color:#64748b}
.pe-nombre{font-weight:700;color:#0f172a}
.pe-sub{font-size:.65rem;color:#94a3b8;margin-top:.05rem}
.pe-chip{display:inline-block;border-radius:5px;padding:.02rem .35rem;font-size:.6rem;font-weight:800;margin-left:.25rem;vertical-align:middle}
.pe-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.74rem;color:#334155}
.pe-vacio{color:#cbd5e1}
.pe-btn{border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:.2rem .5rem;font-size:.7rem;font-weight:700;cursor:pointer;color:#334155;white-space:nowrap}
.pe-btn.llenar{background:#fef2f2;border-color:#fca5a5;color:#b91c1c}
.pe-msg{padding:1.2rem;text-align:center;color:#94a3b8;font-size:.8rem}
.pe-scroll{overflow-x:auto}

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

                <div class="pe-sec" style="margin-top:0">🔐 Portal de empleador</div>
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

                <div class="pe-sec">📝 Otros</div>
                <label class="pe-lbl">Observación</label>
                <input class="pe-inp" name="observacion" maxlength="300">
                <label class="pe-check">
                    <input type="checkbox" name="no_aplica" value="1">
                    <span><strong>No aplica</strong> para esta empresa (no trabaja con esta entidad).</span>
                </label>
            </div>
            <div class="pe-foot">
                <div style="font-size:.66rem;color:#94a3b8" id="peOrigen"></div>
                <div style="display:flex;gap:.5rem">
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
    const GRUPOS = { ARL: '🛡️ ARL', CAJA: '🏠 Caja de compensación', EPS: '🏥 EPS' };

    let el = null, rsId = null, contrato = {}, datos = null, filtro = 'todas', editando = null;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

    function montar(contenedor, id, delContrato) {
        el = typeof contenedor === 'string' ? document.getElementById(contenedor) : contenedor;
        rsId = id;
        contrato = delContrato || {};
        // Abierto desde un contrato, lo primero es ver lo que le falta.
        filtro = 'todas';
        datos = null;
        recargar();
    }

    function recargar() {
        if (!el) return Promise.resolve();
        if (!datos) el.innerHTML = '<div class="pe-msg">⏳ Cargando portales…</div>';
        const q = new URLSearchParams();
        ['eps', 'arl', 'caja'].forEach(k => contrato[k] && q.set(k, contrato[k]));
        return fetch(`${URL_BASE}/${rsId}?${q}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(d => { datos = d; pintar(); })
            .catch(e => { el.innerHTML = `<div class="pe-msg">No se pudieron cargar los portales (${esc(e)}).</div>`; });
    }

    function pasaFiltro(f) {
        if (filtro === 'faltan') return f.estado === 'falta';
        if (filtro === 'usadas') return f.tipo !== 'EPS' || f.afiliados > 0 || f.del_contrato;
        return true;
    }

    function pintar() {
        const r = datos.resumen;
        let h = `<div class="pe-resumen">
            <span class="pe-cifra ${r.faltan_con_afiliados ? 'mal' : 'bien'}">${r.faltan_con_afiliados
                ? `🔴 Faltan ${r.faltan_con_afiliados} que la empresa usa`
                : '✅ Las entidades que la empresa usa tienen datos'}</span>
            <span class="pe-sub" style="margin:0">${r.faltan} de ${r.total} sin datos en total</span>
            <div class="pe-filtros">
                ${[['todas', 'Todas'], ['faltan', 'Faltan'], ['usadas', 'Con afiliados']].map(([k, t]) =>
                    `<button type="button" class="pe-filtro ${filtro === k ? 'activo' : ''}" onclick="Portales.filtrar('${k}')">${t}</button>`).join('')}
            </div>
        </div>`;

        ['ARL', 'CAJA', 'EPS'].forEach(tipo => {
            const filas = datos.filas.filter(f => f.tipo === tipo && pasaFiltro(f));
            h += `<div class="pe-grupo">${GRUPOS[tipo]}</div>`;
            if (tipo === 'ARL' && datos.sin_arl) h += '<div class="pe-aviso">La empresa no tiene ARL configurada (pestaña Datos generales).</div>';
            if (tipo === 'CAJA' && datos.sin_caja) h += '<div class="pe-aviso">La empresa no tiene caja configurada (pestaña Datos generales).</div>';
            if (!filas.length) {
                if (!(tipo === 'ARL' && datos.sin_arl) && !(tipo === 'CAJA' && datos.sin_caja)) h += '<div class="pe-msg" style="padding:.5rem">Nada con este filtro.</div>';
                return;
            }
            h += '<div class="pe-scroll"><table class="pe-tabla"><tbody>' + filas.map(fila).join('') + '</tbody></table></div>';
        });

        if (datos.sin_clasificar.length) {
            h += `<div class="pe-grupo">❓ Claves sin clasificar</div>
                <div class="pe-aviso">Se guardaron con un nombre que no dice la entidad (p. ej. «EPS» o «Caja»). Asígnalas para que cuenten arriba.</div>
                <div class="pe-scroll"><table class="pe-tabla"><tbody>${datos.sin_clasificar.map(sinClasificar).join('')}</tbody></table></div>`;
        }

        el.innerHTML = h;
    }

    function fila(f) {
        const c = f.clave || {};
        const usuario = c.usuario || f.sura?.usuario;
        const pass = c.contrasena || f.sura?.contrasena;
        const asesor = [c.asesor_nombre, c.asesor_correo, c.asesor_telefono].filter(Boolean);
        const sub = [];
        if (f.tipo === 'EPS') sub.push(f.afiliados ? `${f.afiliados} afiliado${f.afiliados === 1 ? '' : 's'} activo${f.afiliados === 1 ? '' : 's'}` : 'sin afiliados');
        if (f.tipo !== 'EPS' && !f.configurada) sub.push('no es la configurada en la empresa');
        if (c.sin_portal) sub.push('sin portal');
        if (f.sura && !c.usuario) sub.push('usuario del portal de Sura');
        if (f.sura?.error) sub.push('⚠️ ' + f.sura.error);
        if (c.cargada_por) sub.push('↔ ' + c.cargada_por);
        if (f.otras) sub.push(`+${f.otras} clave${f.otras === 1 ? '' : 's'} más`);

        const passHtml = !pass ? '<span class="pe-vacio">—</span>'
            : pass === '__oculta__' ? '<span class="pe-sub" title="No tienes permiso para ver contraseñas">guardada</span>'
            : `<span class="pe-mono" style="cursor:pointer" title="Clic para ver" data-p="${esc(pass)}" onclick="this.textContent=this.dataset.p">••••••</span>`;

        const llenar = f.estado === 'falta';
        const btn = datos.puede_gestionar
            ? `<button type="button" class="pe-btn ${llenar ? 'llenar' : ''}" onclick="Portales.editar('${f.tipo}', ${f.id})">${llenar ? '➕ Llenar' : '✏️'}</button>`
            : '';

        return `<tr class="${f.del_contrato ? 'contrato' : ''}">
            <td style="width:120px"><span class="pe-estado ${f.estado}">${ESTADOS[f.estado]}</span></td>
            <td>
                <div class="pe-nombre">${esc(f.nombre)}${f.del_contrato ? '<span class="pe-chip" style="background:#2563eb;color:#fff">DEL CONTRATO</span>' : ''}</div>
                ${sub.length ? `<div class="pe-sub">${sub.map(esc).join(' · ')}</div>` : ''}
            </td>
            <td class="pe-mono">${usuario ? esc(usuario) : '<span class="pe-vacio">—</span>'}</td>
            <td>${passHtml}</td>
            <td style="font-size:.72rem;color:#475569;max-width:200px">${asesor.length ? asesor.map(esc).join('<br>') : '<span class="pe-vacio">—</span>'}</td>
            <td style="text-align:center">${c.link_acceso ? `<a href="${esc(c.link_acceso)}" target="_blank" rel="noopener" class="pe-btn" style="text-decoration:none">🔗</a>` : ''}</td>
            <td style="text-align:right">${btn}</td>
        </tr>`;
    }

    function sinClasificar(c) {
        const opciones = ['EPS', 'ARL', 'CAJA'].map(t =>
            `<optgroup label="${t}">${(datos.catalogo[t] || []).map(e => `<option value="${t}|${e.id}">${esc(e.nombre)}</option>`).join('')}</optgroup>`
        ).join('') + '<option value="OTRO|">Otro portal (no es EPS, ARL ni caja)</option>';

        return `<tr>
            <td><span class="pe-chip" style="background:#f1f5f9;color:#475569;margin:0">${esc(c.tipo)}</span></td>
            <td><div class="pe-nombre">${esc(c.entidad)}</div>${c.cargada_por ? `<div class="pe-sub">↔ ${esc(c.cargada_por)}</div>` : ''}</td>
            <td class="pe-mono">${c.usuario ? esc(c.usuario) : '<span class="pe-vacio">—</span>'}</td>
            <td style="font-size:.7rem;color:#64748b">${esc(c.observacion || '')}</td>
            <td colspan="3" style="text-align:right;white-space:nowrap">
                ${datos.puede_gestionar ? `<select class="pe-inp" style="width:auto;display:inline-block;font-size:.72rem;padding:.2rem .35rem" id="peAsig${c.id}">
                    <option value="">Asignar a…</option>${opciones}</select>
                <button type="button" class="pe-btn" onclick="Portales.asignar(${c.id})">OK</button>` : ''}
            </td>
        </tr>`;
    }

    function editar(tipo, id) {
        const f = datos.filas.find(x => x.tipo === tipo && x.id === id);
        if (!f) return;
        editando = f;
        const c = f.clave || {};
        const form = document.getElementById('peForm');
        form.reset();
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

    function guardar(ev) {
        ev.preventDefault();
        if (!editando) return;
        const form = ev.target;
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

    function filtrar(k) { filtro = k; pintar(); }

    return { montar, recargar, editar, cerrar, guardar, asignar, filtrar };
})();
</script>
@endonce
