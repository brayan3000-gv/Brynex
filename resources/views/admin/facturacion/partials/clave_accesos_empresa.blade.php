{{-- ═══════════════════════════════════════════════════════════════ --}}
{{--   PANEL: Claves y Accesos — Empresa (Drawer lateral)          --}}
{{-- ═══════════════════════════════════════════════════════════════ --}}

{{-- Overlay --}}
<div id="emp-claves-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);z-index:1050;backdrop-filter:blur(2px);"
     onclick="ECA.cerrar()">
</div>

{{-- Panel lateral derecho --}}
<div id="emp-claves-panel"
     style="display:none;position:fixed;top:0;right:0;width:860px;max-width:97vw;height:100vh;
            background:#f8fafc;box-shadow:-8px 0 32px rgba(0,0,0,0.18);z-index:1051;
            flex-direction:column;transform:translateX(100%);transition:transform 0.28s cubic-bezier(.4,0,0.2,1);">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:1.4rem;">🔑</span>
            <div>
                <div style="font-size:0.95rem;font-weight:800;color:#1c1917;">Claves y Accesos</div>
                <div style="font-size:0.72rem;color:rgba(28,25,23,0.7);font-weight:500;">
                    🏢 {{ $empresa->empresa }}
                    @if($empresa->nit) &nbsp;·&nbsp; NIT {{ $empresa->nit }}@endif
                </div>
            </div>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <button onclick="ECA.abrirModal()" id="eca-btn-nueva"
                    style="display:inline-flex;align-items:center;gap:0.35rem;background:#fff;color:#92400e;
                           border:none;border-radius:8px;padding:0.4rem 0.9rem;font-size:0.8rem;font-weight:700;cursor:pointer;
                           box-shadow:0 2px 6px rgba(0,0,0,0.15);transition:background 0.15s;"
                    onmouseover="this.style.background='#fef3c7'" onmouseout="this.style.background='#fff'">
                ➕ Nueva Clave
            </button>
            <button onclick="ECA.cerrar()"
                    style="background:rgba(255,255,255,0.2);border:none;border-radius:8px;
                           width:34px;height:34px;color:#1c1917;font-size:1.1rem;cursor:pointer;
                           display:flex;align-items:center;justify-content:center;font-weight:700;">✕</button>
        </div>
    </div>

    {{-- Notificación inline --}}
    <div id="eca-notif" style="display:none;margin:0.5rem 1rem 0;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;"></div>

    {{-- Contenido (tabla) --}}
    <div style="flex:1;overflow-y:auto;padding:1rem 1.25rem;">

        {{-- Loading --}}
        <div id="eca-loading" style="display:none;text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem;">⏳ Cargando claves...</div>

        {{-- Tabla --}}
        <div id="eca-tabla-wrap">
            <table style="width:100%;border-collapse:collapse;font-size:0.8rem;" id="eca-tabla">
                <thead>
                    <tr style="background:#fef9c3;border-bottom:2px solid #fde68a;">
                        <th class="eca-th">Tipo</th>
                        <th class="eca-th">Entidad / Portal</th>
                        <th class="eca-th">Usuario</th>
                        <th class="eca-th">Contraseña</th>
                        <th class="eca-th" style="text-align:center;">Link</th>
                        <th class="eca-th">Correo</th>
                        <th class="eca-th">Observación</th>
                        <th class="eca-th" style="text-align:center;">Estado</th>
                        <th class="eca-th" style="text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="eca-tbody">
                    <tr>
                        <td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem;">
                            No hay claves registradas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ═══ MODAL: Crear / Editar Clave ═══════════════════════════════ --}}
<div id="eca-modal-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1100;
            align-items:center;justify-content:center;"
     onclick="if(event.target===this) ECA.cerrarModal()">
    <div style="background:#fff;border-radius:16px;padding:0;width:560px;max-width:96vw;
                box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
        {{-- Modal header --}}
        <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:0.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:0.9rem;font-weight:800;color:#1c1917;" id="eca-modal-titulo">🔑 Nueva Clave — {{ $empresa->empresa }}</div>
            <button onclick="ECA.cerrarModal()" style="background:rgba(255,255,255,0.25);border:none;border-radius:7px;width:28px;height:28px;cursor:pointer;font-size:0.9rem;font-weight:700;color:#1c1917;">✕</button>
        </div>
        {{-- Modal body --}}
        <div style="padding:1.25rem;">
            <input type="hidden" id="eca-modal-id">
            <input type="hidden" id="eca-modal-empresa-id" value="{{ $empresa->id }}">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                {{-- Tipo --}}
                <div>
                    <label class="eca-lbl">Tipo *</label>
                    <select id="eca-f-tipo" class="eca-inp">
                        <option value="Portal">Portal Web</option>
                        <option value="Correo">Correo Electrónico</option>
                        <option value="EPS">EPS</option>
                        <option value="ARL">ARL</option>
                        <option value="AFP">AFP / Pensión</option>
                        <option value="CAJA">Caja de Compensación</option>
                        <option value="DIAN">DIAN</option>
                        <option value="MinTrabajo">Min. Trabajo (PILA)</option>
                        <option value="Banco">Banco / Entidad Financiera</option>
                        <option value="Operadores">Operadores</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                {{-- Entidad --}}
                <div>
                    <label class="eca-lbl">Entidad / Portal *</label>
                    <input type="text" id="eca-f-entidad" class="eca-inp" placeholder="Ej: Portal DIAN, Gmail empresa...">
                    @include('admin.partials._entidad_clave')
                    <script>EntidadClave.mejorar('eca-f-tipo', 'eca-f-entidad');</script>
                </div>
                {{-- Usuario --}}
                <div>
                    <label class="eca-lbl">Usuario / Login</label>
                    <input type="text" id="eca-f-usuario" class="eca-inp" placeholder="Nombre de usuario o email">
                </div>
                {{-- Contraseña --}}
                <div>
                    <label class="eca-lbl">Contraseña</label>
                    <div style="display:flex;gap:0.3rem;align-items:center;">
                        <input type="password" id="eca-f-contrasena" class="eca-inp" placeholder="••••••••" style="flex:1;">
                        <button type="button" onclick="ECA.togglePass()"
                                style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:0.35rem 0.5rem;cursor:pointer;font-size:0.8rem;flex-shrink:0;"
                                title="Mostrar/Ocultar">👁</button>
                    </div>
                </div>
                {{-- Link --}}
                <div style="grid-column:span 2;">
                    <label class="eca-lbl">Link / URL de acceso</label>
                    <input type="text" id="eca-f-link" class="eca-inp" placeholder="https://...">
                </div>
                {{-- Correo entidad --}}
                <div>
                    <label class="eca-lbl">Correo de la entidad</label>
                    <input type="text" id="eca-f-correo" class="eca-inp" placeholder="contacto@entidad.com">
                </div>
                {{-- Activo --}}
                <div style="display:flex;align-items:center;gap:0.5rem;padding-top:1.2rem;">
                    <input type="checkbox" id="eca-f-activo" style="width:16px;height:16px;cursor:pointer;" checked>
                    <label for="eca-f-activo" style="font-size:0.8rem;font-weight:600;color:#475569;cursor:pointer;">Activo</label>
                </div>
                {{-- Observación --}}
                <div style="grid-column:span 2;">
                    <label class="eca-lbl">Observación</label>
                    <textarea id="eca-f-obs" class="eca-inp" rows="2" placeholder="Notas adicionales..." style="resize:vertical;"></textarea>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-top:1rem;">
                <button onclick="ECA.cerrarModal()"
                        style="padding:0.45rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.82rem;font-weight:500;cursor:pointer;">
                    Cancelar
                </button>
                <button onclick="ECA.guardar()"
                        style="padding:0.45rem 1.2rem;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:8px;
                               color:#1c1917;font-size:0.84rem;font-weight:800;cursor:pointer;box-shadow:0 2px 8px rgba(245,158,11,0.4);">
                    💾 Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.eca-th { padding:0.5rem 0.65rem;font-size:0.72rem;font-weight:700;color:#92400e;white-space:nowrap;text-align:left; }
.eca-td { padding:0.38rem 0.65rem;color:#1c1917;vertical-align:middle; }
.eca-lbl { display:block;font-size:0.7rem;font-weight:700;color:#475569;margin-bottom:0.18rem;text-transform:uppercase;letter-spacing:0.02em; }
.eca-inp { width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;color:#0f172a;box-sizing:border-box; }
.eca-inp:focus { outline:none;border-color:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,0.2); }
</style>

<script>
(function(){
    var ECA = window.ECA = {
        empresaId: {{ $empresa->id }},

        // ── Abrir / Cerrar panel ──────────────────────────────────────
        abrir: function() {
            var panel   = document.getElementById('emp-claves-panel');
            var overlay = document.getElementById('emp-claves-overlay');
            overlay.style.display = 'block';
            panel.style.display   = 'flex';
            setTimeout(function(){ panel.style.transform = 'translateX(0)'; }, 10);
            ECA.cargar();
        },

        cerrar: function() {
            var panel   = document.getElementById('emp-claves-panel');
            var overlay = document.getElementById('emp-claves-overlay');
            panel.style.transform = 'translateX(100%)';
            setTimeout(function(){
                panel.style.display   = 'none';
                overlay.style.display = 'none';
            }, 300);
        },

        // ── Cargar claves de la empresa ──────────────────────────────
        cargar: function() {
            ECA.mostrarLoading(true);
            fetch('/admin/clave-accesos/empresa/' + ECA.empresaId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                ECA.mostrarLoading(false);
                ECA.renderTabla(data);
            })
            .catch(function() {
                ECA.mostrarLoading(false);
                ECA.notif('Error al cargar las claves.', 'error');
            });
        },

        // ── Renderizar tabla ─────────────────────────────────────────
        renderTabla: function(claves) {
            ECA.clavesActuales = claves;
            var tbody = document.getElementById('eca-tbody');
            tbody.innerHTML = '';
            if (!claves || claves.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem;">No hay claves registradas. Use ➕ Nueva Clave para agregar.</td></tr>';
                return;
            }
            claves.forEach(function(c) {
                var tr = document.createElement('tr');
                tr.style.cssText = 'border-bottom:1px solid #fef3c7;transition:background 0.12s;';
                tr.onmouseover = function(){ this.style.background='#fffbeb'; };
                tr.onmouseout  = function(){ this.style.background='transparent'; };

                var tipoBadge   = ECA.tipoBadge(c.tipo);
                var linkBtn     = c.link_acceso
                    ? '<a href="' + c.link_acceso + '" target="_blank" title="Abrir link" style="display:inline-flex;align-items:center;gap:0.2rem;background:#eff6ff;color:#2563eb;padding:0.18rem 0.5rem;border-radius:5px;font-size:0.7rem;font-weight:600;border:1px solid #bfdbfe;text-decoration:none;">🔗 Abrir</a>'
                    : '<span style="color:#cbd5e1;font-size:0.72rem;">—</span>';
                var estadoBadge = c.activo
                    ? '<span style="background:#dcfce7;color:#16a34a;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">ACTIVO</span>'
                    : '<span style="background:#fee2e2;color:#dc2626;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">INACTIVO</span>';

                tr.innerHTML =
                    '<td class="eca-td">' + tipoBadge + '</td>' +
                    '<td class="eca-td" style="font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (c.entidad||'') + '">' + (c.entidad||'—') + '</td>' +
                    '<td class="eca-td" style="font-family:monospace;font-size:0.77rem;">' + (c.usuario||'<span style="color:#cbd5e1;">—</span>') + '</td>' +
                    '<td class="eca-td">' + ECA.passField(c.id, c.contrasena) + '</td>' +
                    '<td class="eca-td" style="text-align:center;">' + linkBtn + '</td>' +
                    '<td class="eca-td" style="font-size:0.75rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (c.correo_entidad||'') + '">' + (c.correo_entidad||'<span style="color:#cbd5e1;">—</span>') + '</td>' +
                    '<td class="eca-td" style="font-size:0.73rem;color:#64748b;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (c.observacion||'') + '">' + (c.observacion||'') + '</td>' +
                    '<td class="eca-td" style="text-align:center;">' + estadoBadge + '</td>' +
                    '<td class="eca-td" style="text-align:center;white-space:nowrap;">' +
                        '<button onclick="ECA.abrirModalPorId(' + c.id + ')" style="background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#92400e;" title="Editar">✏️</button> ' +
                        '<button onclick="ECA.eliminar(' + c.id + ')" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#dc2626;" title="Eliminar">🗑</button>' +
                    '</td>';
                tbody.appendChild(tr);
            });
        },

        abrirModalPorId: function(id) {
            var clave = ECA.clavesActuales.find(x => x.id == id);
            if (clave) {
                ECA.abrirModal(clave);
            }
        },

        // ── Badge de tipo ────────────────────────────────────────────
        tipoBadge: function(tipo) {
            var colores = {
                'Portal': ['#eff6ff','#1d4ed8'], 'Correo': ['#fef3c7','#92400e'],
                'EPS': ['#dcfce7','#15803d'], 'ARL': ['#fce7f3','#9d174d'],
                'AFP': ['#e0e7ff','#3730a3'], 'CAJA': ['#fff7ed','#c2410c'],
                'DIAN': ['#fef9c3','#713f12'], 'MinTrabajo': ['#f0fdf4','#166534'],
                'Banco': ['#f5f3ff','#6d28d9'], 'Operadores': ['#f3e8ff','#7e22ce'], 'Otro': ['#f1f5f9','#475569']
            };
            var c = colores[tipo] || ['#f1f5f9','#475569'];
            return '<span style="background:' + c[0] + ';color:' + c[1] + ';padding:0.15rem 0.5rem;border-radius:999px;font-size:0.68rem;font-weight:700;white-space:nowrap;">' + (tipo||'—') + '</span>';
        },

        // ── Campo contraseña con toggle ──────────────────────────────
        passField: function(id, pass) {
            if (!pass) return '<span style="color:#cbd5e1;font-size:0.72rem;">—</span>';
            var masked = '•'.repeat(Math.min(pass.length, 8));
            return '<span id="eca-pass-' + id + '" style="font-family:monospace;font-size:0.77rem;cursor:pointer;" ' +
                   'onclick="ECA.verPass(this,' + id + ',\'' + btoa(unescape(encodeURIComponent(pass))) + '\')" title="Click para revelar">' + masked + ' 👁</span>';
        },

        verPass: function(el, id, b64) {
            var actual = el.dataset.visible === '1';
            if (actual) {
                el.textContent = '•'.repeat(8) + ' 👁';
                el.dataset.visible = '0';
            } else {
                try { el.textContent = decodeURIComponent(escape(atob(b64))) + ' 👁'; } catch(e){ el.textContent = atob(b64) + ' 👁'; }
                el.dataset.visible = '1';
            }
        },

        // ── Toggle contraseña en modal ───────────────────────────────
        togglePass: function() {
            var inp = document.getElementById('eca-f-contrasena');
            inp.type = inp.type === 'password' ? 'text' : 'password';
        },

        // ── Abrir modal (crear o editar) ─────────────────────────────
        abrirModal: function(clave) {
            var modal = document.getElementById('eca-modal-overlay');
            modal.style.display = 'flex';

            if (clave && clave.id) {
                document.getElementById('eca-modal-titulo').textContent = '✏️ Editar Clave #' + clave.id;
                document.getElementById('eca-modal-id').value           = clave.id;
                document.getElementById('eca-f-tipo').value             = clave.tipo || 'Portal';
                document.getElementById('eca-f-entidad').value          = clave.entidad || ''; EntidadClave.refrescar('eca-f-entidad');
                document.getElementById('eca-f-usuario').value          = clave.usuario || '';
                document.getElementById('eca-f-contrasena').value       = clave.contrasena || '';
                document.getElementById('eca-f-link').value             = clave.link_acceso || '';
                document.getElementById('eca-f-correo').value           = clave.correo_entidad || '';
                document.getElementById('eca-f-obs').value              = clave.observacion || '';
                document.getElementById('eca-f-activo').checked         = clave.activo == 1 || clave.activo === true;
            } else {
                document.getElementById('eca-modal-titulo').textContent = '🔑 Nueva Clave — {{ $empresa->empresa }}';
                document.getElementById('eca-modal-id').value           = '';
                document.getElementById('eca-f-tipo').value             = 'Portal';
                document.getElementById('eca-f-entidad').value          = ''; EntidadClave.refrescar('eca-f-entidad');
                document.getElementById('eca-f-usuario').value          = '';
                document.getElementById('eca-f-contrasena').value       = '';
                document.getElementById('eca-f-link').value             = '';
                document.getElementById('eca-f-correo').value           = '';
                document.getElementById('eca-f-obs').value              = '';
                document.getElementById('eca-f-activo').checked         = true;
            }
        },

        cerrarModal: function() {
            document.getElementById('eca-modal-overlay').style.display = 'none';
        },

        // ── Guardar (crear o actualizar) ─────────────────────────────
        guardar: function() {
            var id      = document.getElementById('eca-modal-id').value;
            var empId   = document.getElementById('eca-modal-empresa-id').value;
            var entidad = document.getElementById('eca-f-entidad').value.trim();
            var tipo    = document.getElementById('eca-f-tipo').value;

            if (!entidad) { ECA.notif('Ingresa el nombre de la entidad o portal.', 'error'); return; }

            var body = {
                _token:         '{{ csrf_token() }}',
                empresa_id:     parseInt(empId),
                tipo:           tipo,
                entidad:        entidad,
                usuario:        document.getElementById('eca-f-usuario').value.trim(),
                contrasena:     document.getElementById('eca-f-contrasena').value,
                link_acceso:    document.getElementById('eca-f-link').value.trim(),
                correo_entidad: document.getElementById('eca-f-correo').value.trim(),
                observacion:    document.getElementById('eca-f-obs').value.trim(),
                activo:         document.getElementById('eca-f-activo').checked ? 1 : 0,
            };

            var url    = id ? '/admin/clave-accesos/' + id : '/admin/clave-accesos';
            var method = id ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(body)
            })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res.success) {
                    ECA.cerrarModal();
                    ECA.notif(res.message || 'Guardado correctamente.', 'success');
                    ECA.cargar();
                } else {
                    ECA.notif('Error: ' + (res.message || 'Error al guardar.'), 'error');
                }
            })
            .catch(function() {
                ECA.notif('Error de conexión al guardar.', 'error');
            });
        },

        // ── Eliminar ─────────────────────────────────────────────────
        eliminar: function(id) {
            if (!confirm('¿Eliminar esta clave de acceso? Esta acción no se puede deshacer.')) return;
            fetch('/admin/clave-accesos/' + id, {
                method: 'DELETE',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res.success) {
                    ECA.notif(res.message || 'Eliminada correctamente.', 'success');
                    ECA.cargar();
                } else {
                    ECA.notif('Error al eliminar.', 'error');
                }
            })
            .catch(function(){ ECA.notif('Error de conexión.', 'error'); });
        },

        // ── Helpers ──────────────────────────────────────────────────
        mostrarLoading: function(show) {
            document.getElementById('eca-loading').style.display      = show ? 'block' : 'none';
            document.getElementById('eca-tabla-wrap').style.display   = show ? 'none'  : 'block';
        },

        notif: function(msg, tipo) {
            var el = document.getElementById('eca-notif');
            el.style.display = 'block';
            if (tipo === 'success') {
                el.style.cssText = 'display:block;margin:0.5rem 1rem 0;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:#065f46;';
                el.textContent = '✅ ' + msg;
            } else {
                el.style.cssText = 'display:block;margin:0.5rem 1rem 0;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;';
                el.textContent = '❌ ' + msg;
            }
            setTimeout(function(){ el.style.display = 'none'; }, 4000);
        }
    };

    // Alias global para llamar desde el botón de la vista
    window.abrirClavesEmpresa = function() { ECA.abrir(); };
})();
</script>
