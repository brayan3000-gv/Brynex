@extends('layouts.app')
@section('modulo', 'Configuración de Operadores de Planilla')

@section('contenido')
<style>
.op-header { background:linear-gradient(135deg,#0f172a,#164e63 60%,#0891b2);color:#fff;
             border-radius:14px;padding:1.1rem 1.5rem;margin-bottom:1.25rem;
             display:flex;align-items:center;justify-content:space-between;gap:1rem }
.op-header h1 { font-size:1.15rem;font-weight:800;margin:0 0 .2rem }
.op-header p  { font-size:.74rem;color:rgba(255,255,255,.55);margin:0 }
.op-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:.85rem;align-items:start }
.op-card {
    background:#fff;border:2px solid #e2e8f0;border-radius:14px;
    padding:1.5rem 1.2rem 1.1rem;
    display:flex;flex-direction:column;
    transition:all .18s;position:relative;overflow:hidden;
}
/* Fila superior: icono + datos + interruptor */
.op-card-head { display:flex;align-items:center;gap:1rem }
.op-card.activo   { border-color:#10b981; }
.op-card.inactivo { border-color:#e2e8f0;opacity:.7; }
.op-card:hover    { box-shadow:0 4px 18px rgba(0,0,0,.09);transform:translateY(-1px); }

.op-icon { font-size:1.8rem;line-height:1;flex-shrink:0 }

.op-info { flex:1;min-width:0 }
.op-nombre { font-size:.95rem;font-weight:700;color:#0f172a;margin-bottom:.15rem }
.op-codigo { font-size:.7rem;color:#64748b }
.op-ni { display:inline-block;background:#eff6ff;color:#1d4ed8;
         font-size:.62rem;font-weight:700;padding:.12rem .45rem;
         border-radius:20px;margin-left:.35rem }

.op-toggle {
    position:relative;width:46px;height:26px;flex-shrink:0;
    background:#e2e8f0;border:none;border-radius:20px;cursor:pointer;
    transition:background .2s;outline:none;
}
.op-toggle::after {
    content:'';position:absolute;top:3px;left:3px;
    width:20px;height:20px;border-radius:50%;background:#fff;
    transition:transform .2s,background .2s;box-shadow:0 1px 4px rgba(0,0,0,.2);
}
.op-toggle.on { background:#10b981; }
.op-toggle.on::after { transform:translateX(20px); }
.op-toggle:disabled { opacity:.5;cursor:wait; }

/* Credenciales de API: bloque propio, ancho completo, debajo del interruptor */
.op-cred {
    border-top:1px solid #f1f5f9;
    margin-top:.9rem;
    padding-top:.75rem;
}
.op-cred-titulo {
    font-size:.6rem;font-weight:700;color:#94a3b8;
    text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem;
}
.op-cred-estado {
    font-size:.74rem;font-weight:700;margin-bottom:.6rem;
    display:flex;flex-wrap:wrap;gap:.3rem;align-items:baseline;
}
.op-cred-estado.sin     { color:#94a3b8; }
.op-cred-estado.ok      { color:#166534; }
.op-cred-estado.vencida { color:#dc2626; }
.op-cred-vence { font-weight:600;color:#94a3b8;font-size:.68rem }
.op-cred-acciones { display:flex;gap:.4rem;flex-wrap:wrap;align-items:center; }
.op-btn {
    padding:.35rem .7rem;border-radius:7px;font-size:.72rem;font-weight:700;cursor:pointer;
    display:inline-flex;align-items:center;gap:.25rem;white-space:nowrap;
}
.op-btn.morado { background:#ede9fe;border:1px solid #ddd6fe;color:#6d28d9; }
.op-btn.azul   { background:#dbeafe;border:1px solid #bfdbfe;color:#1d4ed8; }
.op-btn.rojo   { background:#fee2e2;border:1px solid #fca5a5;color:#dc2626; }
.op-btn:hover  { filter:brightness(.96); }

.op-badge {
    position:absolute;top:.55rem;right:.6rem;
    font-size:.6rem;font-weight:700;padding:.12rem .45rem;border-radius:20px;
    text-transform:uppercase;letter-spacing:.04em;
}
.badge-on  { background:#d1fae5;color:#065f46 }
.badge-off { background:#fee2e2;color:#991b1b }

.notice {
    background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
    padding:.7rem 1rem;margin-bottom:1rem;font-size:.78rem;color:#1d4ed8;
    display:flex;align-items:flex-start;gap:.55rem;
}
.notice-icon { font-size:1rem;flex-shrink:0 }

.toast-op { display:none;position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;
    background:#0f172a;color:#f1f5f9;padding:.5rem 1.1rem;border-radius:10px;
    font-size:.8rem;font-weight:600;box-shadow:0 4px 18px rgba(0,0,0,.3);
    transition:opacity .3s;pointer-events:none; }
</style>

{{-- ENCABEZADO --}}
<div class="op-header">
    <div>
        <a href="{{ route('admin.configuracion.hub') }}"
           style="color:rgba(255,255,255,.55);font-size:.73rem;text-decoration:none;display:block;margin-bottom:.3rem">
            ← Configuración
        </a>
        <h1>🏦 Operadores de Planilla SS</h1>
        <p>Active o desactive los operadores que aparecerán en el selector al descargar la planilla Excel.</p>
    </div>
    <div style="text-align:right;font-size:.75rem;color:rgba(255,255,255,.55)">
        @if($tieneConfig)
            <span style="background:rgba(255,255,255,.12);border-radius:8px;padding:.3rem .7rem;">
                ⚙️ Configuración personalizada
            </span>
        @else
            <span style="background:rgba(255,255,255,.08);border-radius:8px;padding:.3rem .7rem;">
                📋 Usando configuración global
            </span>
        @endif
    </div>
</div>

@if(session('success'))
<div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;color:#166534;padding:.55rem 1rem;margin-bottom:.9rem;font-size:.82rem">
    ✓ {{ session('success') }}
</div>
@endif

<div class="notice">
    <span class="notice-icon">ℹ️</span>
    <div>
        <strong>¿Cómo funciona?</strong>
        Por defecto todos los operadores globales están activos.
        Al desactivar uno, <strong>no aparecerá en el dropdown</strong> cuando sus usuarios descarguen la planilla Excel.
        Los cambios aplican de inmediato.
    </div>
</div>

@if(session('cred_error'))
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:9px;padding:.6rem .9rem;margin-bottom:1rem;font-size:.8rem;color:#991b1b;line-height:1.45">
    {{ session('cred_error') }}
</div>
@endif

{{-- GRID DE OPERADORES --}}
<div class="op-grid" id="op-grid">
    @foreach($operadoresGlobales as $op)
    @php
        // Si tiene config guardada, usa pivot; si no, por defecto activo
        $estaActivo = $tieneConfig
            ? (bool)($pivotActivo[$op->id] ?? false)
            : true;

        $iconos = [
            'SIMPLE'  => '🟢', 'MIPLANI' => '🔵', 'ASOPAGO' => '🟣',
            'APL'     => '🟡', 'ARUS'    => '🔴', 'ENLACE'  => '🟠',
            'SOI'     => '⚫', 'OTROS'   => '⚪',
        ];
        $icono = $iconos[$op->codigo] ?? '🏦';
    @endphp
    <div class="op-card {{ $estaActivo ? 'activo' : 'inactivo' }}"
         id="card-op-{{ $op->id }}">

        <span class="op-badge {{ $estaActivo ? 'badge-on' : 'badge-off' }}"
              id="badge-op-{{ $op->id }}">
            {{ $estaActivo ? 'Activo' : 'Inactivo' }}
        </span>

        <div class="op-card-head">
            <div class="op-icon">{{ $icono }}</div>

            <div class="op-info">
                <div class="op-nombre">{{ $op->nombre }}</div>
                {{-- El `codigo` (ARUS, SIMPLE, APL…) es interno: no se muestra.
                     ARUS, por ejemplo, ya no dice nada del operador, que hoy es Enlace. --}}
                <div class="op-codigo">
                    @if($op->codigo_ni)
                        <span class="op-ni">PILA: {{ $op->codigo_ni }}</span>
                    @else
                        <span style="color:#94a3b8;font-size:.65rem">Código PILA pendiente</span>
                    @endif
                </div>
            </div>

            <button class="op-toggle {{ $estaActivo ? 'on' : '' }}"
                    id="toggle-op-{{ $op->id }}"
                    title="{{ $estaActivo ? 'Click para desactivar' : 'Click para activar' }}"
                    onclick="toggleOperador({{ $op->id }}, this)">
            </button>
        </div>

        {{-- Credenciales de API: solo para los operadores ya integrados.
             La cuenta es del aliado y cubre todos los aportantes que ese
             usuario administre en el operador. --}}
        @if(\App\Services\SuaporteApiService::soportaOperador($op->codigo))
        @php $cred = $credenciales[$op->id] ?? null; @endphp
        <div class="op-cred">
            <div class="op-cred-titulo">🔐 Credenciales de API</div>

            @if(!$cred)
                <div class="op-cred-estado sin">Sin configurar</div>
            @elseif($cred->claveSecretaVencida())
                <div class="op-cred-estado vencida">
                    <span>⚠️ Clave vencida</span>
                    <span class="op-cred-vence">el {{ $cred->clave_secreta_expira_at->format('d/m/Y') }}</span>
                </div>
            @else
                <div class="op-cred-estado ok">
                    <span>✓ {{ $cred->usuario }}</span>
                    @if($cred->clave_secreta_expira_at)
                        <span class="op-cred-vence">vence {{ $cred->clave_secreta_expira_at->format('d/m/Y') }}</span>
                    @endif
                </div>
            @endif

            <div class="op-cred-acciones">
                <button type="button" class="op-btn morado"
                        onclick="abrirModalCred({{ $op->id }}, @js($op->nombre), {{ $cred ? 'true' : 'false' }}, @js($cred?->usuario), @js(optional($cred?->clave_secreta_expira_at)->format('Y-m-d')))">
                    🔑 {{ $cred ? 'Editar' : 'Configurar' }}
                </button>

                @if($cred)
                <form method="POST" action="{{ route('admin.configuracion.operadores.credenciales.probar', $op->id) }}" style="display:inline">
                    @csrf
                    <button type="submit" class="op-btn azul" title="Hace login real y revisa sobre qué razones sociales tiene permisos">🔌 Probar</button>
                </form>
                <form method="POST" action="{{ route('admin.configuracion.operadores.credenciales.destroy', $op->id) }}"
                      style="display:inline" onsubmit="return confirm('¿Eliminar las credenciales de {{ $op->nombre }}? Ninguna razón social podrá liquidar por API con este operador.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="op-btn rojo">🗑️</button>
                </form>
                @endif
            </div>
        </div>
        @endif
    </div>
    @endforeach
</div>

{{-- Modal de credenciales del aliado --}}
<div id="modalCred" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:14px;max-width:480px;width:100%;box-shadow:0 20px 45px rgba(0,0,0,.25);overflow:hidden">
        <div style="background:linear-gradient(135deg,#6d28d9,#8b5cf6);padding:.9rem 1.1rem;display:flex;justify-content:space-between;align-items:center">
            <h3 style="color:#fff;font-size:.9rem;font-weight:800;margin:0">🔑 Credenciales — <span id="credNombre"></span></h3>
            <button type="button" onclick="cerrarModalCred()" style="background:none;border:none;color:#ddd6fe;font-size:1.1rem;cursor:pointer;line-height:1">✕</button>
        </div>

        <form method="POST" id="credForm" style="padding:1.1rem">
            @csrf
            <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:9px;padding:.6rem .8rem;margin-bottom:.9rem;font-size:.72rem;color:#5b21b6;line-height:1.45">
                El <strong>usuario</strong> es la unión del tipo y número de documento con el que ingresa al
                portal del operador (ej. <code>CC1234567</code>). La <strong>contraseña</strong> son 4 dígitos.
                La <strong>clave secreta</strong> se genera desde el tablero del operador y vence al año.
                Esta cuenta cubre <strong>todas las razones sociales</strong> que ese usuario administre.
            </div>

            <div style="margin-bottom:.8rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">Usuario *</label>
                <input type="text" name="usuario" id="credUsuario" required autocomplete="off" placeholder="CC1234567"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
            </div>

            <div style="margin-bottom:.8rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">Contraseña <span id="credOblig1">*</span></label>
                <input type="password" name="contrasena" id="credPass" autocomplete="new-password" placeholder="••••"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
            </div>

            <div style="margin-bottom:.8rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">Clave secreta <span id="credOblig2">*</span></label>
                <input type="password" name="clave_secreta" id="credClave" autocomplete="new-password" placeholder="Clave generada en el tablero del operador"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
            </div>

            <div style="margin-bottom:1rem">
                <label style="display:block;font-size:.7rem;font-weight:700;color:#475569;margin-bottom:.25rem">Vencimiento de la clave secreta</label>
                <input type="date" name="clave_secreta_expira_at" id="credExpira"
                       style="width:100%;padding:.5rem .65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
                <div style="font-size:.66rem;color:#94a3b8;margin-top:.2rem">Por defecto, un año desde hoy.</div>
            </div>

            <div id="credAvisoEdit" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:.55rem .75rem;margin-bottom:.9rem;font-size:.7rem;color:#64748b">
                Deje la contraseña y la clave secreta en blanco para conservar las actuales.
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.6rem">
                <button type="button" onclick="cerrarModalCred()"
                        style="padding:.5rem .9rem;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;color:#475569;font-size:.8rem;font-weight:700;cursor:pointer">Cancelar</button>
                <button type="submit"
                        style="padding:.5rem 1rem;background:linear-gradient(135deg,#6d28d9,#8b5cf6);border:none;border-radius:8px;color:#fff;font-size:.8rem;font-weight:700;cursor:pointer">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="toast-op" id="toast-op"></div>

@push('scripts')
<script>
const TOGGLE_URL = '{{ url("admin/configuracion/operadores-planilla") }}';
const CSRF       = document.querySelector('meta[name="csrf-token"]').content;

// ── Modal de credenciales de API ──────────────────────────────────────
function abrirModalCred(operadorId, nombre, configurado, usuario, expira) {
    document.getElementById('credForm').action  = `${TOGGLE_URL}/${operadorId}/credenciales`;
    document.getElementById('credNombre').textContent = nombre;
    document.getElementById('credAvisoEdit').style.display = configurado ? 'block' : 'none';

    // Al editar, los secretos son opcionales: en blanco se conservan.
    document.getElementById('credOblig1').style.display = configurado ? 'none' : '';
    document.getElementById('credOblig2').style.display = configurado ? 'none' : '';

    document.getElementById('credUsuario').value = usuario || '';
    document.getElementById('credPass').value    = '';
    document.getElementById('credClave').value   = '';

    if (expira) {
        document.getElementById('credExpira').value = expira;
    } else {
        const d = new Date();
        d.setFullYear(d.getFullYear() + 1);
        document.getElementById('credExpira').value = d.toISOString().substring(0, 10);
    }

    document.getElementById('modalCred').style.display = 'flex';
    document.getElementById('credUsuario').focus();
}

function cerrarModalCred() {
    // No dejar secretos en el DOM al cerrar.
    document.getElementById('credPass').value  = '';
    document.getElementById('credClave').value = '';
    document.getElementById('modalCred').style.display = 'none';
}

async function toggleOperador(id, btn) {
    btn.disabled = true;
    try {
        const resp = await fetch(`${TOGGLE_URL}/${id}/toggle`, {
            method : 'PATCH',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        });
        const data = await resp.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error');

        const card  = document.getElementById(`card-op-${id}`);
        const badge = document.getElementById(`badge-op-${id}`);

        if (data.activo) {
            btn.classList.add('on');
            card.classList.replace('inactivo','activo');
            badge.className = 'op-badge badge-on';
            badge.textContent = 'Activo';
            btn.title = 'Click para desactivar';
        } else {
            btn.classList.remove('on');
            card.classList.replace('activo','inactivo');
            badge.className = 'op-badge badge-off';
            badge.textContent = 'Inactivo';
            btn.title = 'Click para activar';
        }

        showToast(data.activo
            ? `✅ "${data.nombre}" activado`
            : `🔴 "${data.nombre}" desactivado`
        );
    } catch (e) {
        showToast('❌ Error al cambiar el estado. Intente de nuevo.');
    } finally {
        btn.disabled = false;
    }
}

function showToast(msg) {
    const t = document.getElementById('toast-op');
    t.textContent = msg;
    t.style.display = 'block';
    t.style.opacity = '1';
    setTimeout(() => {
        t.style.opacity = '0';
        setTimeout(() => t.style.display = 'none', 300);
    }, 2800);
}
</script>
@endpush

@endsection
