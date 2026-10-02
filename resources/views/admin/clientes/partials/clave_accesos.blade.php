{{-- ═══════════════════════════════════════════════════════════════ --}}
{{--   PANEL: Claves y Accesos (Drawer lateral)                    --}}
{{-- ═══════════════════════════════════════════════════════════════ --}}

{{-- Overlay --}}
<div id="claves-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);z-index:1050;backdrop-filter:blur(2px);"
     onclick="CA.cerrar()">
</div>

{{-- Panel lateral derecho --}}
<div id="claves-panel"
     style="display:none;position:fixed;top:0;right:0;width:860px;max-width:97vw;height:100vh;
            background:#f8fafc;box-shadow:-8px 0 32px rgba(0,0,0,0.18);z-index:1051;
            display:flex;flex-direction:column;transform:translateX(100%);transition:transform 0.28s cubic-bezier(.4,0,0.2,1);">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:1.4rem;">🔑</span>
            <div>
                <div style="font-size:0.95rem;font-weight:800;color:#1c1917;">Claves y Accesos</div>
                <div id="ca-subtitulo" style="font-size:0.72rem;color:rgba(28,25,23,0.7);font-weight:500;">
                    {{ nombre_oracion(trim(($cliente->primer_nombre ?? '').' '.($cliente->primer_apellido ?? '')).' — CC '.$cliente->cedula) }}
                </div>
            </div>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <button onclick="CA.abrirModal()" id="ca-btn-nueva"
                    style="display:inline-flex;align-items:center;gap:0.35rem;background:#fff;color:#92400e;
                           border:none;border-radius:8px;padding:0.4rem 0.9rem;font-size:0.8rem;font-weight:700;cursor:pointer;
                           box-shadow:0 2px 6px rgba(0,0,0,0.15);transition:background 0.15s;"
                    onmouseover="this.style.background='#fef3c7'" onmouseout="this.style.background='#fff'">
                ➕ Nueva Clave
            </button>
            <button onclick="CA.cerrar()"
                    style="background:rgba(255,255,255,0.2);border:none;border-radius:8px;
                           width:34px;height:34px;color:#1c1917;font-size:1.1rem;cursor:pointer;
                           display:flex;align-items:center;justify-content:center;font-weight:700;">✕</button>
        </div>
    </div>

    {{-- Tabs: Cliente / Razón Social --}}
    <div style="background:#fff;border-bottom:2px solid #e2e8f0;padding:0 1.25rem;display:flex;gap:0;flex-shrink:0;">
        <button onclick="CA.cambiarTab('cliente')" id="ca-tab-cliente"
                class="ca-tab ca-tab-active"
                style="padding:0.6rem 1.1rem;border:none;background:none;font-size:0.8rem;font-weight:700;cursor:pointer;
                       color:#92400e;border-bottom:2.5px solid #f59e0b;margin-bottom:-2px;">
            👤 Cliente
        </button>
        @if(!empty($cliente->cod_empresa) && $cliente->cod_empresa != 1)
        <button onclick="CA.cambiarTab('empresa')" id="ca-tab-empresa"
                class="ca-tab"
                style="padding:0.6rem 1.1rem;border:none;background:none;font-size:0.8rem;font-weight:600;cursor:pointer;
                       color:#64748b;border-bottom:2.5px solid transparent;margin-bottom:-2px;">
            🏢 Empresa / Razón Social
        </button>
        @endif
    </div>

    {{-- Notificación inline --}}
    <div id="ca-notif" style="display:none;margin:0.5rem 1rem 0;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;"></div>

    {{-- Contenido (tabla) --}}
    <div style="flex:1;overflow-y:auto;padding:1rem 1.25rem;">

        {{-- Loading --}}
        <div id="ca-loading" style="display:none;text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem;">⏳ Cargando claves...</div>

        {{-- Tabla claves --}}
        <div id="ca-tabla-wrap">
            <table style="width:100%;border-collapse:collapse;font-size:0.8rem;" id="ca-tabla">
                <thead>
                    <tr style="background:#fef9c3;border-bottom:2px solid #fde68a;">
                        <th class="ca-th">Tipo</th>
                        <th class="ca-th">Entidad / Portal</th>
                        <th class="ca-th">Usuario</th>
                        <th class="ca-th">Contraseña</th>
                        <th class="ca-th" style="text-align:center;">Link</th>
                        <th class="ca-th">Correo</th>
                        <th class="ca-th">Observación</th>
                        <th class="ca-th" style="text-align:center;">Estado</th>
                        <th class="ca-th" style="text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="ca-tbody">
                    <tr id="ca-empty-row">
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
<div id="ca-modal-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1100;
            align-items:center;justify-content:center;"
     onclick="if(event.target===this) CA.cerrarModal()">
    <div style="background:#fff;border-radius:16px;padding:0;width:560px;max-width:96vw;
                box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
        {{-- Modal header --}}
        <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:0.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:0.9rem;font-weight:800;color:#1c1917;" id="ca-modal-titulo">🔑 Nueva Clave</div>
            <button onclick="CA.cerrarModal()" style="background:rgba(255,255,255,0.25);border:none;border-radius:7px;width:28px;height:28px;cursor:pointer;font-size:0.9rem;font-weight:700;color:#1c1917;">✕</button>
        </div>
        {{-- Modal body --}}
        <div style="padding:1.25rem;">
            <input type="hidden" id="ca-modal-id">
            <input type="hidden" id="ca-modal-cedula" value="{{ $cliente->cedula }}">
            <input type="hidden" id="ca-modal-rs-id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                {{-- Tipo --}}
                <div>
                    <label class="ca-lbl">Tipo *</label>
                    <select id="ca-f-tipo" class="ca-inp">
                        <option value="Portal">Portal Web</option>
                        <option value="Correo">Correo Electrónico</option>
                        <option value="EPS">EPS</option>
                        <option value="ARL">ARL</option>
                        <option value="AFP">AFP / Pensión</option>
                        <option value="CAJA">Caja de Compensación</option>
                        <option value="DIAN">DIAN</option>
                        <option value="Banco">Banco / Entidad Financiera</option>
                        <option value="Operadores">Operadores PILA</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                {{-- Entidad --}}
                <div>
                    <label class="ca-lbl">Entidad / Portal *</label>
                    <input type="text" id="ca-f-entidad" class="ca-inp" placeholder="Ej: Portal EPS Sura, Gmail...">
                    @include('admin.partials._entidad_clave')
                    <script>EntidadClave.mejorar('ca-f-tipo', 'ca-f-entidad');</script>
                </div>
                {{-- Usuario --}}
                <div>
                    <label class="ca-lbl">Usuario / Login</label>
                    <input type="text" id="ca-f-usuario" class="ca-inp" placeholder="Nombre de usuario o email">
                </div>
                {{-- Contraseña --}}
                <div>
                    <label class="ca-lbl">Contraseña</label>
                    <div style="display:flex;gap:0.3rem;align-items:center;">
                        <input type="password" id="ca-f-contrasena" class="ca-inp" placeholder="••••••••" style="flex:1;">
                        <button type="button" onclick="CA.togglePass()"
                                style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:0.35rem 0.5rem;cursor:pointer;font-size:0.8rem;flex-shrink:0;"
                                title="Mostrar/Ocultar">👁</button>
                    </div>
                </div>
                {{-- Link --}}
                <div style="grid-column:span 2;">
                    <label class="ca-lbl">Link / URL de acceso</label>
                    <input type="text" id="ca-f-link" class="ca-inp" placeholder="https://...">
                </div>
                {{-- Correo entidad --}}
                <div>
                    <label class="ca-lbl">Correo de la entidad</label>
                    <input type="text" id="ca-f-correo" class="ca-inp" placeholder="contacto@entidad.com">
                </div>
                {{-- Activo --}}
                <div style="display:flex;align-items:center;gap:0.5rem;padding-top:1.2rem;">
                    <input type="checkbox" id="ca-f-activo" style="width:16px;height:16px;cursor:pointer;" checked>
                    <label for="ca-f-activo" style="font-size:0.8rem;font-weight:600;color:#475569;cursor:pointer;">Activo</label>
                </div>
                {{-- Observación --}}
                <div style="grid-column:span 2;">
                    <label class="ca-lbl">Observación</label>
                    <textarea id="ca-f-obs" class="ca-inp" rows="2" placeholder="Notas adicionales..." style="resize:vertical;"></textarea>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-top:1rem;">
                <button onclick="CA.cerrarModal()"
                        style="padding:0.45rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.82rem;font-weight:500;cursor:pointer;">
                    Cancelar
                </button>
                <button onclick="CA.guardar()"
                        style="padding:0.45rem 1.2rem;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:8px;
                               color:#1c1917;font-size:0.84rem;font-weight:800;cursor:pointer;box-shadow:0 2px 8px rgba(245,158,11,0.4);">
                    💾 Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.ca-th { padding:0.5rem 0.65rem;font-size:0.72rem;font-weight:700;color:#92400e;white-space:nowrap;text-align:left; }
.ca-td { padding:0.38rem 0.65rem;color:#1c1917;vertical-align:middle; }
.ca-lbl { display:block;font-size:0.7rem;font-weight:700;color:#475569;margin-bottom:0.18rem;text-transform:uppercase;letter-spacing:0.02em; }
.ca-inp { width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;color:#0f172a;box-sizing:border-box; }
.ca-inp:focus { outline:none;border-color:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,0.2); }
.ca-tab { transition:color 0.15s,border-color 0.15s; }
.ca-tab:hover { color:#92400e !important; }
</style>

<script>
(function(){
    var CA = window.CA = {
        tabActual: 'cliente',
        cedula:    {{ $cliente->cedula }},
        rsId:      null,

        // ── Abrir/Cerrar panel ──────────────────────────────────────
        abrir: function() {
            var panel = document.getElementById('claves-panel');
            var overlay = document.getElementById('claves-overlay');
            overlay.style.display = 'block';
            panel.style.display = 'flex';
            setTimeout(function(){ panel.style.transform = 'translateX(0)'; }, 10);
            CA.cambiarTab('cliente');
        },

        cerrar: function() {
            var panel = document.getElementById('claves-panel');
            var overlay = document.getElementById('claves-overlay');
            panel.style.transform = 'translateX(100%)';
            setTimeout(function(){
                panel.style.display = 'none';
                overlay.style.display = 'none';
            }, 300);
        },

        // ── Tabs ────────────────────────────────────────────────────
        cambiarTab: function(tab) {
            CA.tabActual = tab;
            // Actualizar estilos de tabs
            document.querySelectorAll('.ca-tab').forEach(function(el){
                el.style.color = '#64748b';
                el.style.borderBottomColor = 'transparent';
                el.style.fontWeight = '600';
            });
            var active = document.getElementById('ca-tab-' + tab);
            if (active) {
                active.style.color = '#92400e';
                active.style.borderBottomColor = '#f59e0b';
                active.style.fontWeight = '700';
            }

            if (tab === 'cliente') {
                CA.cargarPorCedula();
            } else if (tab === 'empresa') {
                @if(!empty($cliente->cod_empresa) && $cliente->cod_empresa != 1)
                // Obtener razon_social_id a partir de la empresa del cliente
                CA.cargarPorEmpresa({{ $cliente->cod_empresa }});
                @endif
            }
        },

        // ── Cargar claves por cédula de cliente ─────────────────────
        cargarPorCedula: function() {
            CA.mostrarLoading(true);
            fetch('/admin/clave-accesos?cedula=' + CA.cedula, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                CA.mostrarLoading(false);
                document.getElementById('ca-modal-cedula').value = CA.cedula;
                document.getElementById('ca-modal-rs-id').value = '';
                CA.renderTabla(data);
            })
            .catch(() => {
                CA.mostrarLoading(false);
                CA.notif('Error al cargar las claves.', 'error');
            });
        },

        // ── Cargar claves de razón social via empresa ────────────────
        cargarPorEmpresa: function(empresaId) {
            CA.mostrarLoading(true);
            // Buscar razon_social_id por empresa
            fetch('/admin/clave-accesos/razon-social/' + empresaId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                CA.mostrarLoading(false);
                document.getElementById('ca-modal-cedula').value = '';
                document.getElementById('ca-modal-rs-id').value = empresaId;
                CA.renderTabla(data);
            })
            .catch(() => {
                CA.mostrarLoading(false);
                CA.notif('Error al cargar las claves de la empresa.', 'error');
            });
        },

        // ── Renderizar tabla ─────────────────────────────────────────
        renderTabla: function(claves) {
            CA.clavesActuales = claves;
            var tbody = document.getElementById('ca-tbody');
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

                var tipoBadge = CA.tipoBadge(c.tipo);
                var linkBtn   = c.link_acceso
                    ? '<a href="' + c.link_acceso + '" target="_blank" title="Abrir link" style="display:inline-flex;align-items:center;gap:0.2rem;background:#eff6ff;color:#2563eb;padding:0.18rem 0.55rem;border-radius:5px;font-size:0.7rem;font-weight:600;border:1px solid #bfdbfe;text-decoration:none;">🔗 Abrir</a>'
                    : '<span style="color:#cbd5e1;font-size:0.72rem;">—</span>';
                var estadoBadge = c.activo
                    ? '<span style="background:#dcfce7;color:#16a34a;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">ACTIVO</span>'
                    : '<span style="background:#fee2e2;color:#dc2626;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">INACTIVO</span>';

                tr.innerHTML =
                    '<td class="ca-td">' + tipoBadge + '</td>' +
                    '<td class="ca-td" style="font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (c.entidad||'') + '">' + (c.entidad||'—') + '</td>' +
                    '<td class="ca-td" style="font-family:monospace;font-size:0.77rem;">' + (c.usuario||'<span style="color:#cbd5e1;">—</span>') + '</td>' +
                    '<td class="ca-td">' + CA.passField(c.id, c.contrasena) + '</td>' +
                    '<td class="ca-td" style="text-align:center;">' + linkBtn + '</td>' +
                    '<td class="ca-td" style="font-size:0.75rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (c.correo_entidad||'') + '">' + (c.correo_entidad||'<span style="color:#cbd5e1;">—</span>') + '</td>' +
                    '<td class="ca-td" style="font-size:0.73rem;color:#64748b;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (c.observacion||'') + '">' + (c.observacion||'') + '</td>' +
                    '<td class="ca-td" style="text-align:center;">' + estadoBadge + '</td>' +
                    '<td class="ca-td" style="text-align:center;white-space:nowrap;">' +
                        '<button onclick="CA.abrirModalPorId(' + c.id + ')" style="background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#92400e;" title="Editar">✏️</button> ' +
                        '<button onclick="CA.eliminar(' + c.id + ')" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#dc2626;" title="Eliminar">🗑</button>' +
                    '</td>';
                tbody.appendChild(tr);
            });
        },

        abrirModalPorId: function(id) {
            var clave = CA.clavesActuales.find(x => x.id == id);
            if (clave) {
                CA.abrirModal(clave);
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
            return '<span id="ca-pass-' + id + '" style="font-family:monospace;font-size:0.77rem;cursor:pointer;" ' +
                   'onclick="CA.verPass(this,' + id + ',\'' + btoa(unescape(encodeURIComponent(pass))) + '\')" title="Click para revelar">' + masked + ' 👁</span>';
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
            var inp = document.getElementById('ca-f-contrasena');
            inp.type = inp.type === 'password' ? 'text' : 'password';
        },

        // ── Abrir modal (crear o editar) ─────────────────────────────
        abrirModal: function(clave) {
            var modal = document.getElementById('ca-modal-overlay');
            modal.style.display = 'flex';

            if (clave && clave.id) {
                document.getElementById('ca-modal-titulo').textContent  = '✏️ Editar Clave #' + clave.id;
                document.getElementById('ca-modal-id').value            = clave.id;
                document.getElementById('ca-f-tipo').value              = clave.tipo || 'Portal';
                document.getElementById('ca-f-entidad').value           = clave.entidad || ''; EntidadClave.refrescar('ca-f-entidad');
                document.getElementById('ca-f-usuario').value           = clave.usuario || '';
                document.getElementById('ca-f-contrasena').value        = clave.contrasena || '';
                document.getElementById('ca-f-link').value              = clave.link_acceso || '';
                document.getElementById('ca-f-correo').value            = clave.correo_entidad || '';
                document.getElementById('ca-f-obs').value               = clave.observacion || '';
                document.getElementById('ca-f-activo').checked          = clave.activo == 1 || clave.activo === true;
            } else {
                document.getElementById('ca-modal-titulo').textContent = '🔑 Nueva Clave';
                document.getElementById('ca-modal-id').value           = '';
                document.getElementById('ca-f-tipo').value             = 'Portal';
                document.getElementById('ca-f-entidad').value          = ''; EntidadClave.refrescar('ca-f-entidad');
                document.getElementById('ca-f-usuario').value          = '';
                document.getElementById('ca-f-contrasena').value       = '';
                document.getElementById('ca-f-link').value             = '';
                document.getElementById('ca-f-correo').value           = '';
                document.getElementById('ca-f-obs').value              = '';
                document.getElementById('ca-f-activo').checked         = true;
            }
        },

        cerrarModal: function() {
            document.getElementById('ca-modal-overlay').style.display = 'none';
        },

        // ── Guardar (crear o actualizar) ─────────────────────────────
        guardar: function() {
            var id      = document.getElementById('ca-modal-id').value;
            var cedula  = document.getElementById('ca-modal-cedula').value;
            var rsId    = document.getElementById('ca-modal-rs-id').value;
            var entidad = document.getElementById('ca-f-entidad').value.trim();
            var tipo    = document.getElementById('ca-f-tipo').value;

            if (!entidad) { CA.notif('Ingresa el nombre de la entidad o portal.', 'error'); return; }

            var body = {
                _token:          '{{ csrf_token() }}',
                tipo:            tipo,
                entidad:         entidad,
                usuario:         document.getElementById('ca-f-usuario').value.trim(),
                contrasena:      document.getElementById('ca-f-contrasena').value,
                link_acceso:     document.getElementById('ca-f-link').value.trim(),
                correo_entidad:  document.getElementById('ca-f-correo').value.trim(),
                observacion:     document.getElementById('ca-f-obs').value.trim(),
                activo:          document.getElementById('ca-f-activo').checked ? 1 : 0,
            };

            // Asociar al cliente o a la empresa según el tab
            if (CA.tabActual === 'cliente' && cedula) {
                body.cedula = cedula;
            } else if (rsId) {
                body.razon_social_id = rsId;
            }

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
            .then(r => r.json())
            .then(function(res) {
                if (res.success) {
                    CA.cerrarModal();
                    CA.notif(res.message || 'Guardado correctamente.', 'success');
                    if (CA.tabActual === 'cliente') CA.cargarPorCedula();
                    else CA.cargarPorEmpresa(rsId);
                } else {
                    CA.notif('Error: ' + (res.message || 'Error al guardar.'), 'error');
                }
            })
            .catch(function() {
                CA.notif('Error de conexión al guardar.', 'error');
            });
        },

        // ── Eliminar ─────────────────────────────────────────────────
        eliminar: function(id) {
            if (!confirm('¿Eliminar esta clave de acceso? Esta acción no se puede deshacer.')) return;
            var rsId = document.getElementById('ca-modal-rs-id').value;

            fetch('/admin/clave-accesos/' + id, {
                method: 'DELETE',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(r => r.json())
            .then(function(res) {
                if (res.success) {
                    CA.notif(res.message || 'Eliminada correctamente.', 'success');
                    if (CA.tabActual === 'cliente') CA.cargarPorCedula();
                    else CA.cargarPorEmpresa(rsId);
                } else {
                    CA.notif('Error al eliminar.', 'error');
                }
            })
            .catch(() => CA.notif('Error de conexión.', 'error'));
        },

        // ── Helpers ──────────────────────────────────────────────────
        mostrarLoading: function(show) {
            document.getElementById('ca-loading').style.display = show ? 'block' : 'none';
            document.getElementById('ca-tabla-wrap').style.display = show ? 'none' : 'block';
        },

        notif: function(msg, tipo) {
            var el = document.getElementById('ca-notif');
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

    // Exponer abrirPanelClaves globalmente
    window.abrirPanelClaves = function() { CA.abrir(); };
})();
</script>
