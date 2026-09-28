{{--
    Acceso de la empresa a su portal. Lo incluye empresa_edit dentro de
    @can('facturacion.portal_empresas'). Recibe $empresa y $accesoPortal.
--}}
<style>
.pe-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.4rem;margin-bottom:1rem}
.pe-title{display:flex;align-items:center;justify-content:space-between;gap:.5rem;font-size:.85rem;font-weight:800;color:#0f172a;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em}
.pe-txt{font-size:.8rem;color:#475569;line-height:1.5;margin:0 0 .9rem}
.pe-opciones{display:grid;grid-template-columns:1fr;gap:.5rem;margin-bottom:1rem}
.pe-op{display:flex;gap:.55rem;align-items:flex-start;border:1.5px solid #e2e8f0;border-radius:10px;padding:.7rem .8rem;cursor:pointer;transition:border-color .15s,background .15s}
.pe-op:hover{border-color:#93c5fd}
.pe-op input{margin-top:.15rem}
.pe-op b{display:block;font-size:.8rem;color:#0f172a}
.pe-op span{font-size:.72rem;color:#64748b}
.pe-op:has(input:checked){border-color:#2563eb;background:#eff6ff}
.pe-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1.1rem;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.84rem;font-weight:700;cursor:pointer;transition:background .15s,transform .1s}
.pe-btn:hover{background:#1d4ed8;transform:translateY(-1px)}
.pe-btn:disabled{background:#94a3b8;cursor:not-allowed;transform:none}
.pe-btn-sec{background:#f1f5f9;color:#334155}
.pe-btn-sec:hover{background:#e2e8f0}
.pe-badge{display:inline-block;border-radius:999px;padding:.15rem .6rem;font-size:.68rem;font-weight:700;text-transform:none;letter-spacing:0}
.pe-ok{background:#dcfce7;color:#15803d}
.pe-off{background:#fee2e2;color:#b91c1c}
.pe-datos{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:.5rem;margin-bottom:.8rem}
.pe-dato{background:#f8fafc;border-radius:8px;padding:.55rem .7rem}
.pe-dato small{display:block;font-size:.64rem;font-weight:700;color:#64748b;text-transform:uppercase}
.pe-dato div{font-size:.82rem;font-weight:600;color:#0f172a;margin-top:.1rem;overflow-wrap:anywhere}
.pe-sw{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem 0;border-top:1px solid #f1f5f9;font-size:.82rem;color:#334155;cursor:pointer}
/* Interruptor en vez de casilla */
.pe-sw input{appearance:none;-webkit-appearance:none;width:38px;height:22px;border-radius:999px;background:#cbd5e1;position:relative;cursor:pointer;flex-shrink:0;transition:background .2s;margin:0}
.pe-sw input::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.pe-sw input:checked{background:#2563eb}
.pe-sw input:checked::after{transform:translateX(16px)}
.pe-botones{display:flex;gap:.5rem;justify-content:space-between;align-items:center;margin-top:.8rem;padding-top:.8rem;border-top:1px solid #f1f5f9}
.pe-sw small{display:block;color:#64748b;font-size:.72rem}
.pe-clave{background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;border-radius:12px;padding:1.1rem 1.2rem;margin-bottom:1rem;animation:pe-entra .35s ease-out}
.pe-clave .fila{display:flex;gap:1.5rem;flex-wrap:wrap;margin:.6rem 0}
.pe-clave small{display:block;font-size:.66rem;color:#93c5fd;text-transform:uppercase;font-weight:700}
.pe-clave code{font-size:1.15rem;font-weight:800;letter-spacing:.06em;color:#fff}
.pe-alerta{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.6rem .9rem;margin-bottom:1rem;font-size:.82rem;color:#b91c1c}
@keyframes pe-entra{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
@media (max-width:560px){.pe-opciones{grid-template-columns:1fr}}
</style>

<div class="pe-card" id="portal-empresa">
    <div class="pe-title">
        <span>🌐 Portal de la empresa</span>
        @if($accesoPortal)
            <span class="pe-badge {{ $accesoPortal->activo ? 'pe-ok' : 'pe-off' }}">
                {{ $accesoPortal->activo ? 'Activo' : 'Desactivado' }}
            </span>
        @endif
    </div>

    @if(session('error'))
        <div class="pe-alerta">{{ session('error') }}</div>
    @endif

    @if($clave = session('clave_portal'))
        @php
            $mensajePortal = "Portal BryNex de {$empresa->empresa}\n".url('/')."\nUsuario (NIT): {$clave['usuario']}\nClave temporal: {$clave['clave']}\nAl entrar te pedirá cambiarla.";
        @endphp
        <div class="pe-clave" x-data="{ copiado: false }">
            <div style="font-weight:800">🔐 Entrégale estos datos a la empresa</div>
            <div style="font-size:.75rem;color:#cbd5e1">La clave no se vuelve a mostrar. Si se pierde, se restablece.</div>
            <div class="fila">
                <div><small>Usuario (NIT)</small><code>{{ $clave['usuario'] }}</code></div>
                <div><small>Clave temporal</small><code>{{ $clave['clave'] }}</code></div>
            </div>
            <button type="button" class="pe-btn"
                    @click="navigator.clipboard.writeText(@js($mensajePortal)).then(() => { copiado = true; setTimeout(() => copiado = false, 2000) })">
                <span x-show="!copiado">📋 Copiar mensaje para enviar</span>
                <span x-show="copiado" x-cloak>✅ Copiado</span>
            </button>
        </div>
    @endif

    @if(! $accesoPortal)
        @php $nitUsuario = \App\Models\EmpresaAcceso::normalizarUsuario((string) $empresa->nit); @endphp
        <p class="pe-txt">
            La empresa entra a <b>{{ url('/') }}</b> con su NIT y ve sus trabajadores, a qué plan pertenecen,
            qué ya se facturó y pagó en cada período, sus planillas en PDF, facturas, saldo e incapacidades.
            <b>No puede facturar ni cambiar ningún dato.</b>
        </p>

        <form method="POST" action="{{ route('admin.facturacion.empresa.portal.store', $empresa->id) }}">
            @csrf
            <div class="flb" style="margin-bottom:.4rem">¿Cómo ve los valores de cada trabajador?</div>
            <div class="pe-opciones">
                <label class="pe-op">
                    <input type="radio" name="ver_discriminado" value="0" checked>
                    <div><b>Solo el total</b><span>Seguridad social + administración en una sola cifra.</span></div>
                </label>
                <label class="pe-op">
                    <input type="radio" name="ver_discriminado" value="1">
                    <div><b>Discriminado</b><span>EPS, ARL, pensión, caja y administración por separado.</span></div>
                </label>
            </div>
            @if(strlen($nitUsuario) < 5)
                <div class="pe-alerta">Esta empresa no tiene NIT en su ficha. Ponlo arriba y guarda: es el usuario con que entra.</div>
                <button type="submit" class="pe-btn" disabled>🌐 Crear acceso al portal</button>
            @else
                <button type="submit" class="pe-btn" style="width:100%;justify-content:center">🌐 Crear acceso al portal <span style="opacity:.75;font-weight:500">· usuario {{ $nitUsuario }}</span></button>
            @endif
        </form>
    @else
        <div class="pe-datos">
            <div class="pe-dato"><small>Usuario (NIT)</small><div>{{ $accesoPortal->usuario }}</div></div>
            <div class="pe-dato"><small>Último ingreso</small>
                <div>{{ $accesoPortal->ultimo_acceso_at ? $accesoPortal->ultimo_acceso_at->diffForHumans() : 'Nunca ha entrado' }}</div>
            </div>
            <div class="pe-dato"><small>Clave</small>
                <div>{{ $accesoPortal->debe_cambiar_clave ? 'Temporal (sin cambiar)' : 'Propia' }}</div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.facturacion.empresa.portal.update', $empresa->id) }}"
              x-data="{ activo: {{ $accesoPortal->activo ? 'true' : 'false' }}, disc: {{ $accesoPortal->ver_discriminado ? 'true' : 'false' }} }">
            @csrf
            @method('PATCH')
            <input type="hidden" name="activo" :value="activo ? 1 : 0">
            <input type="hidden" name="ver_discriminado" :value="disc ? 1 : 0">
            <label class="pe-sw">
                <div>Acceso activo<small>Si lo desactivas, la empresa no puede entrar.</small></div>
                <input type="checkbox" x-model="activo">
            </label>
            <label class="pe-sw">
                <div>Ver valores discriminados<small>Apagado: solo ve el total de seguridad social + administración.</small></div>
                <input type="checkbox" x-model="disc">
            </label>
            <div class="pe-botones">
                {{-- Es del formulario de abajo (form=…): los dos botones quedan en una fila. --}}
                <button type="submit" form="pe-restablecer" class="pe-btn pe-btn-sec"
                        onclick="return confirm('¿Generar una clave nueva? La actual deja de funcionar y se cierra la sesión que la empresa tenga abierta.')">🔑 Restablecer clave</button>
                <button type="submit" class="pe-btn">💾 Guardar</button>
            </div>
        </form>

        <form method="POST" action="{{ route('admin.facturacion.empresa.portal.restablecer', $empresa->id) }}" id="pe-restablecer" hidden>
            @csrf
        </form>
    @endif
</div>
