@extends('layouts.app')

@section('modulo', 'Dashboard')

@push('styles')
<style>
    /* ── Dashboard responsive ─────────────────────────────────────────── */
    @media (max-width: 768px) {
        .dash-bienvenida {
            padding: 1.1rem 1.25rem !important;
        }
        .dash-bienvenida h1 {
            font-size: 1.05rem !important;
        }
        .dash-modulos {
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 0.65rem !important;
        }
        .dash-modulo-item {
            padding: 1.1rem 0.5rem !important;
            gap: 0.5rem !important;
        }
        .dash-modulo-item .mod-icon {
            font-size: 1.6rem !important;
        }
        .dash-modulo-item .mod-label {
            font-size: 0.72rem !important;
        }
    }
    @media (max-width: 380px) {
        .dash-modulos {
            grid-template-columns: repeat(2, 1fr) !important;
        }
    }
</style>
@endpush

@section('contenido')
<div style="display:flex; flex-direction:column; gap:1.5rem;">

    {{-- Bienvenida --}}
    <div class="dash-bienvenida" style="background:#fff; border-radius:14px; padding:1.5rem 1.75rem; box-shadow:0 1px 8px rgba(0,0,0,0.06); border-left:4px solid #2563eb;">
        <h1 style="font-size:1.25rem; font-weight:700; color:#0d2550; margin-bottom:0.25rem;">
            Bienvenido, {{ Auth::user()->nombre }} 👋
        </h1>
        <p style="color:#64748b; font-size:0.88rem;">
            Empresa activa: <strong style="color:#1e40af;">{{ $alidoActivo->nombre ?? 'BryNex' }}</strong>
            &nbsp;·&nbsp;
            @php
                \Carbon\Carbon::setLocale('es');
                $hoy = now();
                $diasEs = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
                $mesesEs = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
                $fechaEs = $diasEs[$hoy->dayOfWeek] . ' ' . $hoy->day . ' de ' . $mesesEs[$hoy->month - 1] . ' del ' . $hoy->year;
            @endphp
            {{ $fechaEs }}
        </p>
    </div>

    {{-- Accesos rápidos --}}
    <div class="dash-modulos" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:1rem;">
        @php
            $modulos = [
                ['icono'=>'👥', 'nombre'=>'Clientes',      'color'=>'#3b82f6', 'url'=>route('admin.clientes.index')],
                ['icono'=>'🏢', 'nombre'=>'Empresas',      'color'=>'#06b6d4', 'url'=>route('admin.facturacion.index')],
                ['icono'=>'🤝', 'nombre'=>'Afiliaciones',  'color'=>'#8b5cf6', 'url'=>route('admin.afiliaciones.index')],
                ['icono'=>'🛡️', 'nombre'=>'Gestión ARL',  'color'=>'#f97316', 'url'=>route('admin.gestion-arl.index')],
                ['icono'=>'📋', 'nombre'=>'Contratos',     'color'=>'#0ea5e9', 'url'=>route('admin.contratos.index')],
                ['icono'=>'📄', 'nombre'=>'Planos SS',     'color'=>'#a855f7', 'url'=>route('admin.planos.index')],
                ['icono'=>'💰', 'nombre'=>'Cobros',        'color'=>'#10b981', 'url'=>route('admin.cobros.index')],
                ['icono'=>'📌', 'nombre'=>'Tareas',        'color'=>'#f59e0b', 'url'=>route('admin.tareas.index')],
                ['icono'=>'🏥', 'nombre'=>'Incapacidades', 'color'=>'#ef4444', 'url'=>route('admin.incapacidades.index')],
                ['icono'=>'🧾', 'nombre'=>'Cuadre Caja',  'color'=>'#14b8a6', 'url'=>route('admin.cuadre-diario.index')],
                ['icono'=>'💬', 'nombre'=>'Cotizaciones', 'color'=>'#ec4899', 'url'=>route('admin.cotizaciones.index')],
            ];
            // Cobro en visita: cobrar puesto por puesto desde el celular.
            if(auth()->user()?->can('facturacion.generar')){
                array_splice($modulos, 10, 0, [['icono'=>'🛵', 'nombre'=>'Cobro en visita', 'color'=>'#16a34a', 'url'=>route('admin.visita.index')]]);
            }
            // Informes solo para admin, superadmin y contador
            if(auth()->user()?->can('informes.ver')){
                $modulos[] = ['icono'=>'📊', 'nombre'=>'Informes', 'color'=>'#6366f1', 'url'=>route('admin.informes.hub')];
            }
            // Comisiones Asesores para usuario, admin, superadmin, contador
            if(auth()->user()?->can('comisiones.ver')){
                $modulos[] = ['icono'=>'💼', 'nombre'=>'Comisiones', 'color'=>'#f59e0b', 'url'=>route('admin.informes.comisiones.index')];
            }
            // Marketing: solo si el aliado tiene el módulo contratado y activo con Brynex
            if(auth()->user()?->can('marketing.ver')){
                $moduloMarketingActivo = \App\Models\BrynexModuloAliado::where('aliado_id', session('aliado_id_activo'))
                    ->where('modulo_id', 9)
                    ->where('activo', true)
                    ->exists();
                if($moduloMarketingActivo){
                    $modulos[] = ['icono'=>'📣', 'nombre'=>'Marketing', 'color'=>'#d946ef', 'url'=>route('admin.marketing.index')];
                }
            }

        @endphp

        @foreach ($modulos as $mod)
        <a href="{{ $mod['url'] }}" class="dash-modulo-item" style="
            display:flex; flex-direction:column; align-items:center; gap:0.75rem;
            background:#fff; border-radius:14px; padding:1.5rem 1rem;
            text-decoration:none; border:2px solid transparent;
            box-shadow:0 1px 6px rgba(0,0,0,0.06);
            transition:border-color 0.15s, transform 0.15s, box-shadow 0.15s;
            cursor:pointer;
        "
        onmouseover="this.style.borderColor='{{ $mod['color'] }}'; this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.1)'"
        onmouseout="this.style.borderColor='transparent'; this.style.transform=''; this.style.boxShadow='0 1px 6px rgba(0,0,0,0.06)'">
            <div class="mod-icon" style="font-size:2rem;">{{ $mod['icono'] }}</div>
            <div class="mod-label" style="font-size:0.8rem; font-weight:600; color:#334155; text-align:center;">{{ $mod['nombre'] }}</div>
        </a>
        @endforeach
    </div>

</div>
@endsection
