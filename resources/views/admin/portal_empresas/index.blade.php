@extends('layouts.app')
@section('modulo', 'Portal de empresas')

@section('contenido')
<style>
.pe-wrap{display:flex;flex-direction:column;gap:1rem}
.pe-head{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;color:#fff;padding:1.2rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.pe-head h1{margin:0;font-size:1.25rem;font-weight:800}
.pe-head p{margin:.25rem 0 0;color:#93c5fd;font-size:.8rem;max-width:640px}
.pe-btn{display:inline-flex;align-items:center;gap:.35rem;background:#2563eb;color:#fff;border-radius:8px;padding:.5rem .9rem;font-size:.8rem;font-weight:700;text-decoration:none;transition:background .15s,transform .1s}
.pe-btn:hover{background:#3b82f6;transform:translateY(-1px);color:#fff}
.pe-btn.sec{background:rgba(59,130,246,.18);border:1px solid rgba(147,197,253,.45)}
.pe-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.8rem}
.pe-kpi{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:.9rem 1rem;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.pe-kpi small{display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.pe-kpi b{display:block;font-size:1.5rem;color:#0f172a;margin-top:.2rem}
.pe-kpi.alerta{border-color:#93c5fd;background:#eff6ff}
.pe-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden}
.pe-card h2{margin:0;font-size:.85rem;font-weight:800;color:#0f172a;padding:.8rem 1rem;border-bottom:1px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em}
.pe-tabla{width:100%;border-collapse:collapse;font-size:.8rem}
.pe-tabla th{text-align:left;font-size:.66rem;text-transform:uppercase;color:#64748b;padding:.55rem 1rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.pe-tabla td{padding:.6rem 1rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.pe-tabla tr:hover td{background:#f8fbff}
.pe-chip{display:inline-block;border-radius:999px;padding:.12rem .55rem;font-size:.68rem;font-weight:700;white-space:nowrap}
.ok{background:#dcfce7;color:#15803d}.off{background:#fee2e2;color:#b91c1c}.info{background:#dbeafe;color:#1d4ed8}.warn{background:#fef3c7;color:#92400e}.gris{background:#f1f5f9;color:#475569}
.pe-link{color:#1d4ed8;font-weight:600;text-decoration:none}
.pe-link:hover{text-decoration:underline}
.pe-vacio{padding:1.5rem;text-align:center;color:#94a3b8;font-size:.82rem}
.pe-scroll{overflow-x:auto}
</style>

@php
    $activos = $accesos->where('activo', true)->count();
    $totalAbiertas = $abiertas->count();
@endphp

<div class="pe-wrap">
    <div class="pe-head">
        <div>
            <h1>🌐 Portal de empresas</h1>
            <p>Las empresas que entran con su NIT a ver a sus trabajadores y a pedir ingresos, retiros e incapacidades.
               Cada trámite llega como tarea al encargado de la empresa. El acceso se da desde «Editar empresa».</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <a class="pe-btn sec" href="{{ route('admin.facturacion.index') }}">🏢 Dar acceso a una empresa</a>
            <a class="pe-btn" href="{{ route('admin.tareas.index') }}">📌 Ver en Tareas</a>
        </div>
    </div>

    <div class="pe-kpis">
        <div class="pe-kpi {{ $nuevas ? 'alerta' : '' }}"><small>Solicitudes sin ver</small><b>{{ $nuevas }}</b></div>
        <div class="pe-kpi"><small>Trámites abiertos</small><b>{{ $totalAbiertas }}</b></div>
        <div class="pe-kpi"><small>Empresas con acceso</small><b>{{ $activos }}</b></div>
    </div>

    <div class="pe-card">
        <h2>Trámites abiertos</h2>
        @if($abiertas->isEmpty())
            <div class="pe-vacio">No hay trámites de empresas pendientes. 🎉</div>
        @else
            <div class="pe-scroll">
            <table class="pe-tabla">
                <thead><tr><th>Empresa</th><th>Trámite</th><th>Recibido</th><th>Encargado</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                @foreach($abiertas as $t)
                    @php $s = $t->solicitudEmpresa; @endphp
                    <tr>
                        <td style="font-weight:700">{{ $empresas[$t->empresa_id] ?? '—' }}</td>
                        <td>
                            {{ $t->tipoLabel() }}
                            @if($s?->datos['nombre'] ?? null)<div style="font-size:.72rem;color:#64748b">{{ $s->datos['nombre'] }}</div>@endif
                        </td>
                        <td style="white-space:nowrap">{{ $t->created_at?->format('d/m/Y h:i a') }}</td>
                        <td>{{ $t->encargado?->nombre ?? '—' }}</td>
                        <td>
                            @if($s && ! $s->vista_at)
                                <span class="pe-chip info">Nueva</span>
                            @else
                                <span class="pe-chip warn">{{ \App\Models\Tarea::ESTADOS[$t->estado] ?? ucfirst(str_replace('_', ' ', $t->estado)) }}</span>
                            @endif
                        </td>
                        <td style="text-align:right"><a class="pe-link" href="{{ route('admin.tareas.index', ['empresa_id' => $t->empresa_id, 'tarea' => $t->id]) }}">Abrir →</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="pe-card">
        <h2>Empresas con acceso</h2>
        @if($accesos->isEmpty())
            <div class="pe-vacio">Ninguna empresa tiene acceso todavía. Se da desde Empresas → la empresa → Editar → «Portal de la empresa».</div>
        @else
            <div class="pe-scroll">
            <table class="pe-tabla">
                <thead><tr><th>Empresa</th><th>Usuario (NIT)</th><th>Acceso</th><th>Valores</th><th>Último ingreso</th><th>Trámites abiertos</th><th></th></tr></thead>
                <tbody>
                @foreach($accesos as $a)
                    @php $n = (int) ($abiertasPorEmpresa[$a->empresa_id] ?? 0); @endphp
                    <tr>
                        <td style="font-weight:700">{{ $a->empresa?->empresa ?? '—' }}</td>
                        <td style="font-family:monospace">{{ $a->usuario }}</td>
                        <td>
                            <span class="pe-chip {{ $a->activo ? 'ok' : 'off' }}">{{ $a->activo ? 'Activo' : 'Desactivado' }}</span>
                            @if($a->debe_cambiar_clave)<span class="pe-chip gris" title="Aún no ha puesto su clave">Clave temporal</span>@endif
                        </td>
                        <td>{{ $a->ver_discriminado ? 'Discriminado' : 'Solo total' }}</td>
                        <td style="white-space:nowrap">{{ $a->ultimo_acceso_at ? $a->ultimo_acceso_at->diffForHumans() : 'Nunca' }}</td>
                        <td>
                            @if($n)
                                <a class="pe-chip info" style="text-decoration:none" href="{{ route('admin.tareas.index', ['empresa_id' => $a->empresa_id]) }}">🌐 {{ $n }}</a>
                            @else
                                <span style="color:#cbd5e1">—</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <a class="pe-link" href="{{ route('admin.facturacion.empresa.edit', $a->empresa_id) }}#portal-empresa">Configurar</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
@endsection
