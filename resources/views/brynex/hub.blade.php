@extends('layouts.app')

@section('titulo', 'BryNex')
@section('modulo', 'Configuración Global')

@section('contenido')
<div class="brynex-hub">

    {{-- Header --}}
    <div class="hub-header">
        <div class="hub-header-left">
            <div class="hub-logo">🔵</div>
            <div>
                <h1 class="hub-title">BryNex Global</h1>
                <p class="hub-sub">Panel de administración del sistema</p>
            </div>
        </div>
        <div class="hub-stats">
            <div class="hub-stat">
                <span class="hs-num">{{ $totalAliados }}</span>
                <span class="hs-label">Aliados</span>
            </div>
            <div class="hub-stat">
                <span class="hs-num">{{ $activos }}</span>
                <span class="hs-label">Activos</span>
            </div>
            <div class="hub-stat">
                <span class="hs-num">{{ $usuariosBrynex }}</span>
                <span class="hs-label">Usuarios BN</span>
            </div>
            <div class="hub-stat">
                <span class="hs-num" style="color:#4ade80">{{ $totalWaConfigurados }}</span>
                <span class="hs-label">WA Activos</span>
            </div>
        </div>
    </div>

    {{-- Grid de módulos --}}
    <div class="hub-grid">

        {{-- ── Aliados ──────────────────────────────────────────────────── --}}
        <div class="hub-section">
            <div class="hub-section-title">🏢 Aliados</div>
            <div class="hub-cards">
                <a href="{{ route('admin.aliados.index') }}" class="hub-card">
                    <div class="hc-icon">🏢</div>
                    <div class="hc-body">
                        <div class="hc-name">Ver Aliados</div>
                        <div class="hc-desc">Lista, creación y gestión de todos los aliados del sistema</div>
                    </div>
                    <div class="hc-arrow">→</div>
                </a>
                @if(auth()->user()->es_brynex && auth()->user()->hasRole('superadmin'))
                {{-- Los prospectos son del aliado que pauta el anuncio para asesores (Brygar, el
                     primero de config/alianzas.php): ?aliado= cambia el aliado activo a ese. --}}
                <a href="{{ route('admin.whatsapp.prospectos_aliados', ['aliado' => array_key_first(config('alianzas.contactos', [])) ?? 2]) }}" class="hub-card">
                    <div class="hc-icon">🤝</div>
                    <div class="hc-body">
                        <div class="hc-name">Interesados en ser aliados</div>
                        <div class="hc-desc">Asesores y empresas que escribieron: pendientes, en conversación, no interesados y los que adquirieron software o plan</div>
                    </div>
                    <div class="hc-arrow">→</div>
                </a>
                @endif
            </div>
        </div>

        {{-- ── Cobros y Facturación por Uso ────────────────────────────────────── --}}
        <div class="hub-section" style="border:1px solid #dbeafe">
            <div class="hub-section-title" style="color:#2563eb">💰 Cobros y Consumo por Uso</div>
            <div class="hub-cards">
                <a href="{{ route('brynex.consumo.index') }}" class="hub-card" style="border-color:#bfdbfe;background:#eff6ff">
                    <div class="hc-icon">📊</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#1d4ed8">Monitoreo de Consumos</div>
                        <div class="hc-desc">Ver consumos de tramos de contratos y uso de WhatsApp Business API por aliados</div>
                    </div>
                    <div class="hc-arrow" style="color:#2563eb">→</div>
                </a>
            </div>
        </div>

        {{-- ── Operaciones Masivas ────────────────────────────────────────── --}}
        <div class="hub-section" style="border:1px solid #ede9fe">
            <div class="hub-section-title" style="color:#7c3aed">🔄 Operaciones Masivas</div>
            <div class="hub-cards">
                <a href="{{ route('admin.traslados.index') }}" class="hub-card" style="border-color:#ddd6fe;background:#faf5ff">
                    <div class="hc-icon">🔄</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#6d28d9">Traslado de Razón Social</div>
                        <div class="hc-desc">Transfiere personas de una empresa a otra · Gestiona retiros y nuevas afiliaciones masivas</div>
                    </div>
                    <div class="hc-arrow" style="color:#7c3aed">→</div>
                </a>
            </div>
        </div>

        {{-- ── Consulta DIAN ───────────────────────────────────────────────── --}}
        @can('brynex_dian.ver')
        <div class="hub-section" style="border:1px solid #c7d2fe">
            <div class="hub-section-title" style="color:#4338ca">🔎 Consulta DIAN</div>
            <div class="hub-cards">
                <a href="{{ route('brynex.dian.index') }}" class="hub-card" style="border-color:#c7d2fe;background:#eef2ff">
                    <div class="hc-icon">🔎</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#4338ca">Consultar un documento en la DIAN</div>
                        <div class="hc-desc">El nombre y el correo como los tiene registrados la DIAN · Muestra en qué aliados ya existe esa cédula y en qué se diferencia de la ficha</div>
                    </div>
                    <div class="hc-arrow" style="color:#4338ca">→</div>
                </a>
            </div>
        </div>
        @endcan

        {{-- ── Razones sociales ante la DIAN ───────────────────────────────── --}}
        {{-- Ojo: esta tarjeta solo la ve quien entre al hub, y el hub exige
             `brynex_hub.ver`. El contable llega al módulo por el ítem suelto
             del sidebar, no por aquí. --}}
        @can('brynex_razones.ver')
        <div class="hub-section" style="border:1px solid #99f6e4">
            <div class="hub-section-title" style="color:#0f766e">🏛️ Razones Sociales</div>
            <div class="hub-cards">
                <a href="{{ route('brynex.razones.index') }}" class="hub-card" style="border-color:#99f6e4;background:#f0fdfa">
                    <div class="hc-icon">🏛️</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#0f766e">Razones Sociales de BryNex</div>
                        <div class="hc-desc">Todas las razones sociales agrupadas por NIT · Afiliados y movimientos de dinero consolidados sin importar el aliado · Claves de DIAN, banco y cámara</div>
                    </div>
                    <div class="hc-arrow" style="color:#0f766e">→</div>
                </a>
                <a href="{{ route('brynex.razones.tablero') }}" class="hub-card" style="border-color:#99f6e4;background:#f0fdfa">
                    <div class="hc-icon">🚨</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#0f766e">Vencimientos Tributarios</div>
                        <div class="hc-desc">Lo que ya se venció y lo que vence pronto ante la DIAN · Firmas electrónicas por caducar</div>
                    </div>
                    <div class="hc-arrow" style="color:#0f766e">→</div>
                </a>
                <a href="{{ route('brynex.razones.calendario') }}" class="hub-card" style="border-color:#99f6e4;background:#f0fdfa">
                    <div class="hc-icon">📅</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#0f766e">Calendario Tributario</div>
                        <div class="hc-desc">Fechas de vencimiento por último dígito del NIT · Aquí se cargan a mano la exógena y el ICA, que no vienen en el calendario de la DIAN</div>
                    </div>
                    <div class="hc-arrow" style="color:#0f766e">→</div>
                </a>
            </div>
        </div>
        @endcan

        {{-- ── Supervisión de planilla ─────────────────────────────────────── --}}
        @can('brynex_cierre.ver')
        <div class="hub-section" style="border:1px solid #c7d2fe">
            <div class="hub-section-title" style="color:#4338ca">🧾 Supervisión de Planilla</div>
            <div class="hub-cards">
                <a href="{{ route('admin.informes.validacion_cierre') }}" class="hub-card" style="border-color:#c7d2fe;background:#eef2ff">
                    <div class="hc-icon">🧾</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#4338ca">Validación de Cierre</div>
                        <div class="hc-desc">Contratos vigentes que se quedaron por fuera de la planilla del período, por razón social · Estado de la liquidación por API</div>
                    </div>
                    <div class="hc-arrow" style="color:#4338ca">→</div>
                </a>
                <a href="{{ route('brynex.conciliacion_arl') }}" class="hub-card" style="border-color:#c7d2fe;background:#eef2ff">
                    <div class="hc-icon">🛡️</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#4338ca">Conciliación ARL Sura</div>
                        <div class="hc-desc">Afiliados del portal de Sura contra los contratos vigentes de todos los aliados que comparten el NIT · Diferencias de nivel de riesgo</div>
                    </div>
                    <div class="hc-arrow" style="color:#4338ca">→</div>
                </a>
            </div>
        </div>
        @endcan

        {{-- ── Asistente Virtual IA ────────────────────────────────────────── --}}
        <div class="hub-section" style="border:1px solid #fde68a">
            <div class="hub-section-title" style="color:#b45309">🤖 Asistente Virtual IA</div>
            <div class="hub-cards">
                <a href="{{ route('brynex.ia.index') }}" class="hub-card" style="border-color:#fde68a;background:#fffbeb">
                    <div class="hc-icon">🤖</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#b45309">Configuración IA</div>
                        <div class="hc-desc">Proveedor de IA (Claude/OpenAI), API keys y activación del asistente por aliado</div>
                    </div>
                    <div class="hc-arrow" style="color:#b45309">→</div>
                </a>
            </div>
        </div>

        {{-- ── WhatsApp Business API ────────────────────────────────────────── --}}
        <div class="hub-section" style="border:1px solid #bbf7d0">
            <div class="hub-section-title" style="color:#16a34a">💬 WhatsApp Business API</div>
            <div class="hub-cards">
                <a href="{{ route('admin.whatsapp.config.index') }}" class="hub-card" style="border-color:#a7f3d0;background:#f0fdf4">
                    <div class="hc-icon">💬</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#15803d">Configuración WhatsApp</div>
                        <div class="hc-desc">Gestiona credenciales, números de teléfono y webhooks de Meta WhatsApp por aliado</div>
                    </div>
                    <div class="hc-arrow" style="color:#16a34a">→</div>
                </a>
            </div>
        </div>

        {{-- ── Superadmin únicamente ────────────────────────────────────── --}}
        @role('superadmin')
        <div class="hub-section">
            <div class="hub-section-title">🔐 Control de Accesos <span class="badge-sa">Solo Superadmin</span></div>
            <div class="hub-cards">
                <a href="{{ route('brynex.accesos') }}" class="hub-card">
                    <div class="hc-icon">👥</div>
                    <div class="hc-body">
                        <div class="hc-name">Accesos de Usuarios BryNex</div>
                        <div class="hc-desc">Configura qué usuarios BryNex pueden acceder a cada aliado</div>
                    </div>
                    <div class="hc-arrow">→</div>
                </a>
            </div>
        </div>

        <div class="hub-section">
            <div class="hub-section-title">⚙️ Configuración Global <span class="badge-sa">Solo Superadmin</span></div>
            <div class="hub-cards">
                <a href="{{ route('brynex.parametros') }}" class="hub-card" style="border-color:#bfdbfe;background:#eff6ff">
                    <div class="hc-icon">🔒</div>
                    <div class="hc-body">
                        <div class="hc-name">Parámetros BryNex</div>
                        <div class="hc-desc">Salario mínimo, porcentajes de seguridad social y tarifas ARL por nivel de riesgo</div>
                    </div>
                    <div class="hc-arrow">→</div>
                </a>
                <a href="{{ route('admin.bitacora.index') }}" class="hub-card">
                    <div class="hc-icon">👁️</div>
                    <div class="hc-body">
                        <div class="hc-name">Auditoría / Bitácora</div>
                        <div class="hc-desc">Registro de actividad global del sistema</div>
                    </div>
                    <div class="hc-arrow">→</div>
                </a>
                <a href="{{ route('brynex.backups') }}" class="hub-card">
                    <div class="hc-icon">💾</div>
                    <div class="hc-body">
                        <div class="hc-name">Copias de Seguridad</div>
                        <div class="hc-desc">Gestión y descarga de backups de base de datos y documentos</div>
                    </div>
                    <div class="hc-arrow">→</div>
                </a>
                @if(in_array(strtolower((string) auth()->user()->email), array_map('strtolower', (array) config('exportacion.correos_autorizados')), true))
                <a href="{{ route('brynex.exportaciones.index') }}" class="hub-card" style="border-color:#fed7aa;background:#fff7ed">
                    <div class="hc-icon">📦</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#c2410c">Entrega de Datos de un Aliado</div>
                        <div class="hc-desc">Paquete CSV/TXT con la información propia de un aliado que se va</div>
                    </div>
                    <div class="hc-arrow" style="color:#ea580c">→</div>
                </a>
                @endif
            </div>
        </div>

        @if(auth()->user()->cedula === config('finanzas.cedula_dueno'))
        <div class="hub-section" style="border:1px solid #c084fc">
            <div class="hub-section-title" style="color:#a855f7">💰 Finanzas Personales <span class="badge-sa" style="background:#a855f7">Privado</span></div>
            <div class="hub-cards">
                <a href="{{ route('finanzas.dashboard') }}" class="hub-card" style="border-color:#d8b4fe;background:#faf5ff">
                    <div class="hc-icon">💰</div>
                    <div class="hc-body">
                        <div class="hc-name" style="color:#7e22ce">Finanzas Personales</div>
                        <div class="hc-desc">Módulo privado de contabilidad personal, préstamos, inversiones y patrimonio</div>
                    </div>
                    <div class="hc-arrow" style="color:#a855f7">→</div>
                </a>
            </div>
        </div>
        @endif
        @endrole

    </div>
</div>
@endsection

@push('styles')
<style>
.brynex-hub { max-width: 960px; margin: 0 auto; }

/* Header */
.hub-header {
    display: flex; align-items: center; justify-content: space-between;
    background: linear-gradient(135deg, #0a1628 0%, #1e3a8a 100%);
    border-radius: 14px; padding: 1.5rem 2rem; margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(30,58,138,.25);
}
.hub-header-left { display: flex; align-items: center; gap: 1rem; }
.hub-logo { font-size: 2.5rem; line-height: 1; }
.hub-title { color: #fff; font-size: 1.4rem; font-weight: 700; margin: 0; }
.hub-sub { color: rgba(255,255,255,.5); font-size: .8rem; margin: 0; }
.hub-stats { display: flex; gap: 1.5rem; }
.hub-stat { text-align: center; }
.hs-num { display: block; color: #93c5fd; font-size: 1.6rem; font-weight: 700; line-height: 1; }
.hs-label { display: block; color: rgba(255,255,255,.45); font-size: .7rem; margin-top: .2rem; }

/* Sections */
.hub-grid { display: flex; flex-direction: column; gap: 1.25rem; }
.hub-section {
    background: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
    box-shadow: 0 1px 6px rgba(0,0,0,.07);
}
.hub-section-title {
    font-size: .8rem; font-weight: 700; color: #475569; text-transform: uppercase;
    letter-spacing: .06em; margin-bottom: .85rem;
    display: flex; align-items: center; gap: .5rem;
}
.badge-sa {
    background: #fef3c7; color: #92400e; border: 1px solid #fde68a;
    border-radius: 999px; padding: .1rem .6rem; font-size: .65rem;
    font-weight: 600; text-transform: none;
}

/* Cards */
.hub-cards { display: flex; flex-direction: column; gap: .6rem; }
.hub-card {
    display: flex; align-items: center; gap: 1rem;
    padding: .85rem 1rem; border-radius: 10px;
    border: 1px solid #e2e8f0; background: #f8fafc;
    text-decoration: none; transition: all .15s;
}
.hub-card:hover {
    border-color: #3b82f6; background: #eff6ff;
    transform: translateX(3px);
}
.hub-card.accent { border-color: #bfdbfe; background: #eff6ff; }
.hub-card.accent:hover { border-color: #3b82f6; background: #dbeafe; }
.hc-icon { font-size: 1.3rem; width: 38px; text-align: center; flex-shrink: 0; }
.hc-body { flex: 1; }
.hc-name { font-weight: 600; font-size: .9rem; color: #1e293b; }
.hc-desc { font-size: .75rem; color: #64748b; margin-top: .1rem; }
.hc-arrow { color: #3b82f6; font-weight: 700; font-size: 1rem; }

@media (max-width: 640px) {
    .hub-stats { gap: .85rem; }
    .hs-num { font-size: 1.2rem; }
    .hub-header { flex-direction: column; gap: 1rem; align-items: flex-start; }
}
</style>
@endpush
