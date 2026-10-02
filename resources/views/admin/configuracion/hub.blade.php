@extends('layouts.app')
@section('modulo','Configuración')

@section('contenido')
<style>
.cfg-wrap { max-width:1000px;margin:0 auto }
.cfg-hdr  { background:linear-gradient(135deg,#0f172a,#1e3a5f 60%,#1e40af);color:#fff;
            border-radius:14px;padding:1.2rem 1.6rem;margin-bottom:1.5rem;
            display:flex;align-items:center;gap:1rem }
.cfg-hdr .icon { font-size:2rem;line-height:1 }
.cfg-hdr h1 { font-size:1.3rem;font-weight:800;margin:0 0 .15rem }
.cfg-hdr p  { font-size:.78rem;color:rgba(255,255,255,.55);margin:0 }
.cfg-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem }
.cfg-card { background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;
            padding:1.2rem 1.4rem;text-decoration:none;color:inherit;
            transition:all .18s;display:block;position:relative;overflow:hidden }
.cfg-card::before { content:'';position:absolute;inset:0;background:var(--c);opacity:0;transition:opacity .18s }
.cfg-card:hover { border-color:var(--bc);box-shadow:0 6px 22px rgba(0,0,0,.1);transform:translateY(-2px) }
.cfg-card:hover::before { opacity:.04 }
.cfg-card .c-icon { font-size:1.6rem;margin-bottom:.5rem }
.cfg-card .c-title { font-size:.98rem;font-weight:700;color:#0f172a;margin-bottom:.2rem }
.cfg-card .c-desc  { font-size:.75rem;color:#64748b;line-height:1.45 }
.cfg-card .c-badge { position:absolute;top:.75rem;right:.75rem;
                     font-size:.6rem;font-weight:700;padding:.18rem .55rem;
                     border-radius:20px;text-transform:uppercase }
.cfg-sep { grid-column:1/-1;border:none;border-top:1px solid #e2e8f0;margin:.25rem 0 }
.cfg-sep-label { grid-column:1/-1;font-size:.65rem;font-weight:700;color:#94a3b8;
                 text-transform:uppercase;letter-spacing:.08em }
</style>

@php
    // Qué tarjetas puede abrir este usuario. Al hub se entra con cualquiera de los
    // permisos que viven aquí (Gate `entrar-configuracion`), así que quien recibió uno
    // suelto ve solo lo suyo. Cada condición es el permiso de la ruta de la tarjeta;
    // las de administración del aliado siguen colgando de `configuracion.ver`.
    $u = auth()->user();
    $admin = $u->can('configuracion.ver');
    $ver = [
        'parametros' => $admin,
        'anulados'   => $admin,
        'cuentas'    => $u->can('cuentas_bancarias.gestionar'),
        'seguros'    => $admin,
        'fe'         => $u->can('facturacion_electronica.ver'),
        'arl_sura'   => $u->can('operadores_planilla.credenciales'),
        'usuarios'   => $u->can('usuarios.ver'),
        'asesores'   => $admin,
        'publico'    => $admin,
        'razones'    => $admin || $u->can('razones_sociales.gestionar') || $u->can('razones_sociales.documentos'),
        'operadores' => $admin,
    ];
    $esBrynexSuper = $u->hasRole('superadmin') && $u->es_brynex;
    $secFacturacion = $ver['parametros'] || $ver['anulados'] || $ver['cuentas'] || $ver['seguros'] || $ver['fe'] || $ver['arl_sura'];
    $secUsuarios = $ver['usuarios'] || $ver['asesores'] || $ver['publico'] || $esBrynexSuper;
    $secContratos = $ver['razones'] || $ver['operadores'] || $esBrynexSuper;
@endphp

<div class="cfg-wrap">
    <div class="cfg-hdr">
        <div class="icon">⚙️</div>
        <div>
            <h1>Centro de Configuración</h1>
            <p>{{ $admin ? 'Administración del sistema' : 'Las secciones de configuración a las que tienes acceso' }}</p>
        </div>
    </div>

    <div class="cfg-grid">

        {{-- ── FACTURACIÓN ─────────────────────────────────── --}}
        @if($secFacturacion)
        <div class="cfg-sep-label">📄 Facturación</div>
        @endif

        @if($ver['parametros'])
        <a class="cfg-card" href="{{ route('admin.configuracion.index') }}?seccion=parametros"
           style="--c:#7c3aed;--bc:#c4b5fd">
            <span class="c-badge" style="background:#ede9fe;color:#6d28d9">Parámetros</span>
            <div class="c-icon">🔧</div>
            <div class="c-title">Parámetros del Sistema</div>
            <div class="c-desc">Salario mínimo, porcentajes SS, tarifas ARL, comisiones y costos de administración por plan.</div>
        </a>
        @endif

        @if($ver['anulados'])
        <a class="cfg-card" href="{{ route('admin.facturacion.anuladas') }}"
           style="--c:#dc2626;--bc:#fca5a5">
            <span class="c-badge" style="background:#fee2e2;color:#991b1b">Auditoría</span>
            <div class="c-icon">🗑</div>
            <div class="c-title">Recibos Anulados</div>
            <div class="c-desc">Historial de facturas anuladas con motivo, fecha, usuario y opción de restaurar.</div>
        </a>
        @endif

        @if($ver['cuentas'])
        <a class="cfg-card" href="{{ route('admin.configuracion.cuentas') }}"
           style="--c:#0891b2;--bc:#67e8f9">
            <span class="c-badge" style="background:#cffafe;color:#0e7490">Bancario</span>
            <div class="c-icon">🏦</div>
            <div class="c-title">Cuentas Bancarias</div>
            <div class="c-desc">Gestionar cuentas bancarias del aliado. Marcar cuáles aparecen en la <strong>Cuenta de Cobro</strong> (campo 💳 Para Cobro).</div>
        </a>
        @endif

        @if($ver['seguros'])
        <a class="cfg-card" href="{{ route('admin.configuracion.seguros') }}"
           style="--c:#7c3aed;--bc:#c4b5fd">
            <span class="c-badge" style="background:#ede9fe;color:#5b21b6">Seguros</span>
            <div class="c-icon">💼</div>
            <div class="c-title">Seguros</div>
            <div class="c-desc">Los seguros que vendes aparte de la seguridad social (plan exequial, mascotas, vida) y cuánto vale cada uno al mes.</div>
        </a>
        @endif

        @if($ver['fe'])
        <a class="cfg-card" href="{{ route('admin.facturacion.electronica.index') }}"
           style="--c:#2563eb;--bc:#93c5fd">
            <span class="c-badge" style="background:#dbeafe;color:#1e40af">Electrónica</span>
            <div class="c-icon">🧾</div>
            <div class="c-title">Facturación Electrónica</div>
            <div class="c-desc">Gestión y control de Facturación Electrónica a través del proveedor tecnológico Dataico.</div>
        </a>
        @endif

        @if($ver['fe'])
        <a class="cfg-card" href="{{ route('admin.facturacion.dataico.index') }}"
           style="--c:#0e4d2f;--bc:#6ee7b7">
            <span class="c-badge" style="background:#d1fae5;color:#065f46">Dataico API</span>
            <div class="c-icon">🔗</div>
            <div class="c-title">Dataico por API</div>
            <div class="c-desc">Emisión automática ante la DIAN de lo que entra por la cuenta de la razón social emisora. Reemplaza la subida manual del Excel.</div>
        </a>
        @endif


        @if($ver['arl_sura'])
        <a class="cfg-card" href="{{ route('admin.configuracion.arl-sura.index') }}"
           style="--c:#0c4a6e;--bc:#7dd3fc">
            <span class="c-badge" style="background:#e0f2fe;color:#075985">ARL Sura</span>
            <div class="c-icon">🛡️</div>
            <div class="c-title">Conexión con ARL Sura</div>
            <div class="c-desc">Credenciales del portal para afiliar, retirar y bajar carné y soporte sin entrar a Servicios en Línea. Se registran una sola vez.</div>
        </a>
        @endif


        {{-- ── USUARIOS Y ACCESO ────────────────────────────── --}}
        @if($secUsuarios)
        @if($secFacturacion)<hr class="cfg-sep">@endif
        <div class="cfg-sep-label">👤 Usuarios y Acceso</div>
        @endif

        @if($ver['usuarios'])
        <a class="cfg-card" href="{{ route('admin.usuarios.index') }}"
           style="--c:#0369a1;--bc:#7dd3fc">
            <span class="c-badge" style="background:#e0f2fe;color:#0369a1">Usuarios</span>
            <div class="c-icon">👥</div>
            <div class="c-title">Gestión de Usuarios</div>
            <div class="c-desc">Crear, editar y controlar el acceso de los usuarios al sistema según roles.</div>
        </a>
        @endif

        @if($ver['asesores'])
        <a class="cfg-card" href="{{ route('admin.asesores.index') }}"
           style="--c:#f59e0b;--bc:#fcd34d">
            <span class="c-badge" style="background:#fffbeb;color:#b45309">Red Comercial</span>
            <div class="c-icon">🤝</div>
            <div class="c-title">Asesores</div>
            <div class="c-desc">Registrar y gestionar asesores comerciales. Configure su comisión por afiliación y por planilla (fija o porcentaje).</div>
        </a>
        @endif

        @if($ver['asesores'])
        <a class="cfg-card" href="{{ route('admin.configuracion.niveles.index') }}"
           style="--c:#0369a1;--bc:#7dd3fc">
            <span class="c-badge" style="background:#e0f2fe;color:#0369a1">Red Comercial</span>
            <div class="c-icon">🎚️</div>
            <div class="c-title">Niveles de Asesores</div>
            <div class="c-desc">Plantillas de comisión por tamaño de cartera. Defina una vez cuánto gana cada nivel por plan, modalidad y riesgo ARL, y aplíquelo a los asesores nuevos sin configurarlos uno por uno.</div>
        </a>
        @endif

        @if($ver['publico'])
        <a class="cfg-card" href="{{ route('admin.pagina.index') }}"
           style="--c:#2563eb;--bc:#93c5fd">
            <span class="c-badge" style="background:#dbeafe;color:#1e40af">Público</span>
            <div class="c-icon">🌐</div>
            <div class="c-title">Página Web Pública</div>
            <div class="c-desc">Edita el encabezado, secciones visibles, mensaje de WhatsApp, SEO y preguntas frecuentes de tu página{{ $aliadoActivo?->slug ? ' en brynex.co/aliado/' . $aliadoActivo->slug : '' }}.</div>
        </a>
        @endif

        @if($ver['publico'])
        <a class="cfg-card" href="{{ route('admin.redes-sociales.index') }}"
           style="--c:#db2777;--bc:#f9a8d4">
            <span class="c-badge" style="background:#fce7f3;color:#be185d">Público</span>
            <div class="c-icon">📲</div>
            <div class="c-title">Redes Sociales</div>
            <div class="c-desc">Conecta Facebook e Instagram para publicar contenido desde Brynex hacia tus cuentas.</div>
        </a>
        @endif

        @if($ver['publico'])
        <a class="cfg-card" href="{{ route('admin.publicidad.index') }}"
           style="--c:#7c3aed;--bc:#c4b5fd">
            <span class="c-badge" style="background:#ede9fe;color:#6d28d9">Público</span>
            <div class="c-icon">🎨</div>
            <div class="c-title">Generador de Publicidad</div>
            <div class="c-desc">Crea piezas con plantillas o IA, apruébalas y publícalas en la página web y en redes sociales.</div>
        </a>
        @endif

        @if(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
        <a class="cfg-card" href="{{ route('admin.aliados.index') }}"
           style="--c:#0f172a;--bc:#94a3b8">
            <span class="c-badge" style="background:#f1f5f9;color:#475569">Solo BryNex</span>
            <div class="c-icon">🏢</div>
            <div class="c-title">Aliados</div>
            <div class="c-desc">Gestionar aliados/franquicias que operan en la plataforma BryNex.</div>
        </a>
        @endif

        {{-- ── AUDITORÍA — solo BryNex superadmin ────────────── --}}
        @if(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
        <hr class="cfg-sep">
        <div class="cfg-sep-label">🔍 Auditoría</div>

        <a class="cfg-card" href="{{ route('admin.bitacora.index') }}"
           style="--c:#7c3aed;--bc:#a78bfa">
            <span class="c-badge" style="background:#ede9fe;color:#6d28d9">Solo BryNex</span>
            <div class="c-icon">👁️</div>
            <div class="c-title">Bitácora de Auditoría</div>
            <div class="c-desc">Registro completo de todas las acciones realizadas: creaciones, ediciones, eliminaciones y restauraciones.</div>
        </a>
        @endif

        @if(($primeraEps ?? null) && auth()->user()->can('formularios_pdf.editar'))
        @if(!Auth::user()->hasRole('superadmin'))
        <hr class="cfg-sep">
        <div class="cfg-sep-label">🔍 Auditoría</div>
        @endif
        <a class="cfg-card" href="{{ route('admin.configuracion.eps.formulario', $primeraEps) }}"
           style="--c:#0891b2;--bc:#67e8f9">
            <span class="c-badge" style="background:#cffafe;color:#0e7490">Solo BryNex</span>
            <div class="c-icon">🗺️</div>
            <div class="c-title">Editor de Formularios EPS</div>
            <div class="c-desc">Sube el PDF de cada EPS y arrastra los campos para definir dónde se escriben los datos del cotizante automáticamente.</div>
        </a>
        @endif

        @if(($primeraPension ?? null) && auth()->user()->can('formularios_pdf.editar'))
        <a class="cfg-card" href="{{ route('admin.configuracion.pensiones.formulario', $primeraPension) }}"
           style="--c:#0891b2;--bc:#67e8f9">
            <span class="c-badge" style="background:#cffafe;color:#0e7490">Solo BryNex</span>
            <div class="c-icon">🗺️</div>
            <div class="c-title">Editor de Formularios Pensión</div>
            <div class="c-desc">Igual que el de EPS, pero para los fondos de pensión: COLPENSIONES y las demás AFP con formulario propio de afiliación.</div>
        </a>
        @endif

        @if(($primerOperador ?? null) && auth()->user()->can('formularios_pdf.editar'))
        <a class="cfg-card" href="{{ route('admin.configuracion.operadores.formulario', $primerOperador) }}"
           style="--c:#0f172a;--bc:#94a3b8">
            <span class="c-badge" style="background:#f1f5f9;color:#475569">Solo BryNex</span>
            <div class="c-icon">📑</div>
            <div class="c-title">Editor de Planillas de Pago</div>
            <div class="c-desc">Sube el PDF de planilla en blanco y arrastra los campos para rellenar los datos y aportes del plano de forma configurable.</div>
        </a>
        @endif

        {{-- ── CONTRATOS ────────────────────────────────────── --}}
        @if($secContratos)
        @if($secFacturacion || $secUsuarios)<hr class="cfg-sep">@endif
        <div class="cfg-sep-label">📑 Contratos y Afiliaciones</div>
        @endif

        @if($ver['razones'])
        <a class="cfg-card" href="{{ route('admin.configuracion.razones.index') }}"
           style="--c:#059669;--bc:#6ee7b7">
            <span class="c-badge" style="background:#d1fae5;color:#065f46">Empresas</span>
            <div class="c-icon">🏭</div>
            <div class="c-title">Razones Sociales</div>
            <div class="c-desc">Administre las empresas a través de las cuales afilia trabajadores. Configure ARL, Caja y si son de tipo independiente.</div>
        </a>
        @endif

        @if(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
        <a class="cfg-card" href="{{ route('admin.configuracion.modalidades') }}"
           style="--c:#0369a1;--bc:#7dd3fc">
            <span class="c-badge" style="background:#e0f2fe;color:#0369a1">Solo BryNex</span>
            <div class="c-icon">🎛️</div>
            <div class="c-title">Modalidades y Planes</div>
            <div class="c-desc">Configure qué planes de seguridad social son válidos para cada tipo de modalidad y marque sus RS independientes.</div>
        </a>
        @endif

        @if($ver['operadores'])
        <a class="cfg-card" href="{{ route('admin.configuracion.operadores.index') }}"
           style="--c:#0891b2;--bc:#67e8f9">
            <span class="c-badge" style="background:#cffafe;color:#0e7490">Planillas SS</span>
            <div class="c-icon">🏦</div>
            <div class="c-title">Operadores de Planilla</div>
            <div class="c-desc">Active o desactive los operadores (Simple, Enlace, SOI, etc.) que aparecen en el selector al descargar la planilla Excel de seguridad social.</div>
        </a>
        @endif



    </div>
</div>
@endsection
