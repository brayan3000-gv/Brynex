{{--
    Demo: «Radicar por API» en el modal del radicado de Afiliaciones.

    Lo mete InyectarDemo al final de la página. Envuelve abrirModalRadicado()
    para mostrar el botón en los radicados de ARL, EPS, caja y pensión que no
    están en OK, y abre un modal que recorre los pasos del trámite real, llama a
    /demo/radicar/{id} y enseña el PDF de muestra.
--}}
<style>
    #demoRad{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:2147482000;display:none;align-items:center;justify-content:center;padding:1rem}
    #demoRad .dr-caja{background:#fff;border-radius:14px;width:100%;max-width:760px;max-height:92vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#0f172a}
    #demoRad .dr-cab{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.2rem;border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#fff;z-index:1}
    #demoRad .dr-cab h3{margin:0;font-size:1.02rem}
    #demoRad .dr-x{border:none;background:none;font-size:1.3rem;color:#94a3b8;cursor:pointer}
    #demoRad .dr-cuerpo{padding:1rem 1.2rem}
    #demoRad .dr-aviso{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;border-radius:8px;padding:.5rem .7rem;font-size:.78rem;margin-bottom:.8rem}
    #demoRad .dr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.35rem .9rem;font-size:.8rem;margin-bottom:.8rem}
    #demoRad .dr-grid span{color:#64748b}
    #demoRad .dr-prob{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:.6rem .8rem;font-size:.8rem;margin-bottom:.8rem}
    #demoRad .dr-pasos{list-style:none;margin:.4rem 0 .8rem;padding:0;font-size:.84rem}
    #demoRad .dr-pasos li{display:flex;gap:.5rem;align-items:center;padding:.28rem 0;color:#94a3b8}
    #demoRad .dr-pasos li.activo{color:#1d4ed8;font-weight:600}
    #demoRad .dr-pasos li.hecho{color:#15803d}
    #demoRad .dr-ico{width:1.2rem;text-align:center}
    #demoRad details{font-size:.76rem;margin-bottom:.8rem}
    #demoRad pre{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:.7rem;overflow:auto;max-height:220px;font-size:.72rem}
    #demoRad .dr-acc{display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap}
    #demoRad .dr-btn{border:none;border-radius:8px;padding:.5rem 1rem;font-weight:700;font-size:.84rem;cursor:pointer}
    #demoRad .dr-prim{background:linear-gradient(135deg,#047857,#10b981);color:#fff}
    #demoRad .dr-sec{background:#f1f5f9;color:#334155}
    #demoRad .dr-ok{background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.8rem 1rem;margin-bottom:.8rem}
    #demoRad iframe{width:100%;height:440px;border:1px solid #e2e8f0;border-radius:8px}
    @keyframes drGiro{to{transform:rotate(360deg)}}
    #demoRad .dr-giro{display:inline-block;animation:drGiro 1s linear infinite}
</style>

<div id="demoRad" onclick="if(event.target===this) DemoRadicar.cerrar()">
    <div class="dr-caja">
        <div class="dr-cab">
            <h3 id="dr-titulo">🚀 Radicar por API</h3>
            <button class="dr-x" onclick="DemoRadicar.cerrar()">✕</button>
        </div>
        <div class="dr-cuerpo" id="dr-cuerpo"></div>
    </div>
</div>

<script>
(function () {
    const URL_PRECHECK = @json(route('demo.radicar.precheck', ['id' => '__ID__']));
    const URL_RADICAR  = @json(route('demo.radicar.ejecutar', ['id' => '__ID__']));
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || (typeof CSRF !== 'undefined' ? CSRF : '');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const pausa = (ms) => new Promise(r => setTimeout(r, ms));
    const pesos = (n) => '$' + Number(n || 0).toLocaleString('es-CO');
    const ETIQUETA = { arl: 'la ARL', eps: 'la EPS', caja: 'la caja', pension: 'el fondo' };

    let estado = {};

    const DemoRadicar = window.DemoRadicar = {
        cerrar() { document.getElementById('demoRad').style.display = 'none'; },

        async abrir(radId, contratoId) {
            estado = { radId, contratoId };
            const cuerpo = document.getElementById('dr-cuerpo');
            document.getElementById('demoRad').style.display = 'flex';
            cuerpo.innerHTML = '<p style="color:#64748b">⏳ Revisando los datos del contrato…</p>';

            let d;
            try {
                const r = await fetch(URL_PRECHECK.replace('__ID__', radId), { headers: { Accept: 'application/json' } });
                d = await r.json();
                if (!r.ok) throw new Error(d.message || 'No se pudo revisar el contrato.');
            } catch (e) {
                cuerpo.innerHTML = `<div class="dr-prob">❌ ${esc(e.message)}</div>`;
                return;
            }
            estado.d = d;

            const ent = d.entidad, s = d.resumen;
            document.getElementById('dr-titulo').textContent =
                (ent.tipo === 'arl' ? '🚀 Afiliar en ' : '🏥 Radicar en ') + ent.nombre + ' por API';

            const fila = (k, v) => v ? `<div><span>${k}:</span> <strong>${esc(v)}</strong></div>` : '';
            cuerpo.innerHTML = `
                <div class="dr-aviso">🧪 <strong>Simulación.</strong> Así radica BryNex de verdad ante ${esc(ent.nombre)}, pero en la demo
                    no se envía nada: el número de radicado es de prueba y el PDF dice <strong>MUESTRA</strong>.</div>
                <div class="dr-grid">
                    ${fila('Trabajador', s.trabajador)}${fila('Documento', s.documento)}
                    ${fila('Empleador', s.razon_social)}${fila('NIT', s.nit_empleador)}
                    ${fila('Modalidad', s.modalidad)}${fila('IBC', s.ibc ? pesos(s.ibc) : '')}
                    ${ent.tipo === 'arl' ? fila('Riesgo', s.nivel_riesgo) + fila('Cargo', s.cargo) : ''}
                    ${fila('Ingreso', s.fecha_ingreso)}${fila(ent.etiqueta, ent.nombre)}
                </div>
                ${d.problemas.length ? `<div class="dr-prob"><strong>Antes de radicar falta:</strong><ul style="margin:.3rem 0 0 1rem;padding:0">${d.problemas.map(p => `<li>${esc(p)}</li>`).join('')}</ul>
                    <div style="margin-top:.35rem">Complétalo en la ficha del cliente o del contrato y vuelve a intentarlo.</div></div>` : ''}
                ${ent.tipo === 'arl' ? `<label style="display:block;font-size:.8rem;margin-bottom:.8rem">Inicio de cobertura:
                    <input type="date" id="dr-fecha" value="${esc(d.fecha_sugerida)}" style="margin-left:.4rem;padding:.25rem .4rem;border:1px solid #cbd5e1;border-radius:6px"></label>` : ''}
                <ul class="dr-pasos" id="dr-pasos">${d.pasos.map(p => `<li><span class="dr-ico">○</span>${esc(p)}</li>`).join('')}</ul>
                <details><summary style="cursor:pointer;color:#475569">Ver lo que se le envía al API de ${esc(ent.nombre)}</summary>
                    <pre id="dr-payload">${esc(JSON.stringify(d.payload, null, 2))}</pre></details>
                <div class="dr-acc">
                    <button class="dr-btn dr-sec" onclick="DemoRadicar.cerrar()">Cancelar</button>
                    ${d.problemas.length ? '' : `<button class="dr-btn dr-prim" id="dr-ir" onclick="DemoRadicar.radicar()">${ent.tipo === 'arl' ? '🚀 Afiliar ahora' : '🏥 Radicar ahora'}</button>`}
                </div>`;
        },

        async radicar() {
            const boton = document.getElementById('dr-ir');
            if (boton) { boton.disabled = true; boton.textContent = 'Radicando…'; }
            const fecha = document.getElementById('dr-fecha')?.value || null;
            const pasos = [...document.querySelectorAll('#dr-pasos li')];

            // La llamada sale ya; los pasos se pintan mientras tanto.
            const peticion = fetch(URL_RADICAR.replace('__ID__', estado.radId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ fecha_cobertura: fecha }),
            }).then(async r => ({ r, d: await r.json().catch(() => ({})) }));

            for (const li of pasos) {
                li.className = 'activo';
                li.querySelector('.dr-ico').innerHTML = '<span class="dr-giro">◌</span>';
                await pausa(650 + Math.random() * 650);
                li.className = 'hecho';
                li.querySelector('.dr-ico').textContent = '✓';
            }

            const { r, d } = await peticion;
            const cuerpo = document.getElementById('dr-cuerpo');

            if (!r.ok || !d.ok) {
                cuerpo.insertAdjacentHTML('afterbegin', `<div class="dr-prob">❌ ${esc(d.mensaje || 'No se pudo simular la radicación.')}</div>`);
                if (boton) { boton.disabled = false; boton.textContent = 'Reintentar'; }
                return;
            }

            if (typeof pintarRadicadoEnLista === 'function') {
                try { pintarRadicadoEnLista(d.radicado, estado.contratoId); } catch (e) {}
            }

            const ent = estado.d.entidad;
            cuerpo.innerHTML = `
                <div class="dr-ok">
                    <div style="font-weight:800;color:#15803d;font-size:.95rem">✅ ${ent.tipo === 'arl' ? 'Afiliado' : 'Radicado'} en ${esc(ent.nombre)} (simulado)</div>
                    <div style="font-size:.82rem;margin-top:.25rem">N° de radicado: <strong>${esc(d.numero)}</strong>
                        ${ent.tipo === 'arl' ? ` · Cobertura desde <strong>${esc(d.fecha_cobertura)}</strong>` : ''}</div>
                    <div style="font-size:.76rem;color:#475569;margin-top:.25rem">El radicado quedó en OK con el soporte adjunto, igual que con la entidad real.</div>
                </div>
                <details><summary style="cursor:pointer;color:#475569">Respuesta del API (de prueba)</summary><pre>${esc(JSON.stringify(d.respuesta, null, 2))}</pre></details>
                <iframe src="${esc(d.pdf_url)}" title="PDF de muestra"></iframe>
                <div class="dr-acc" style="margin-top:.8rem">
                    <a class="dr-btn dr-sec" style="text-decoration:none" href="${esc(d.pdf_url)}" target="_blank" rel="noopener">👁️ Abrir PDF</a>
                    <a class="dr-btn dr-sec" style="text-decoration:none" href="${esc(d.pdf_descarga)}">⬇️ Descargar PDF</a>
                    <button class="dr-btn dr-prim" onclick="DemoRadicar.cerrar()">Listo</button>
                </div>`;
        },
    };

    // El botón dentro del modal del radicado, al lado de «Formulario».
    const zona = document.getElementById('seccionFormularioEps');
    if (!zona || typeof window.abrirModalRadicado !== 'function') return;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'btnRadicarDemo';
    btn.style.cssText = 'display:none;align-items:center;gap:.35rem;padding:.3rem .85rem;background:linear-gradient(135deg,#047857,#10b981);color:#fff;border:none;border-radius:7px;font-size:.75rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(16,185,129,.3);';
    btn.onclick = () => {
        if (typeof cerrarModal === 'function') cerrarModal('modalRadicado');
        DemoRadicar.abrir(btn._radId, btn._contratoId);
    };
    zona.appendChild(btn);

    const original = window.abrirModalRadicado;
    window.abrirModalRadicado = function (radId, radData, ctx = {}, contratoId = null) {
        const r = original.apply(this, arguments);
        const tipo = radData?.tipo;
        const aplica = ['arl', 'eps', 'caja', 'pension'].includes(tipo) && radData.estado !== 'ok';
        const nombre = { arl: ctx.arl, eps: ctx.eps, caja: ctx.caja, pension: ctx.pension }[tipo];
        btn.style.display = aplica ? 'inline-flex' : 'none';
        btn._radId = radId;
        btn._contratoId = contratoId || ctx.id || null;
        btn.textContent = (tipo === 'arl' ? '🚀 Afiliar en ' : '🏥 Radicar en ') + (nombre || ETIQUETA[tipo] || 'la entidad') + ' por API';
        return r;
    };
})();
</script>
