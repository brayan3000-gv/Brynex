@extends('layouts.app')
@section('modulo','Cómo van los asesores')

@section('contenido')
@php
    $tonos = [
        'ok' => ['#dcfce7', '#166534', '✅'],
        'sube' => ['#dbeafe', '#1e40af', '⬆️'],
        'aviso' => ['#fef3c7', '#92400e', '⚠️'],
        'info' => ['#f0f9ff', '#075985', 'ℹ️'],
        'neutro' => ['#f1f5f9', '#475569', '•'],
    ];
    $cop = fn ($v) => '$'.number_format($v, 0, ',', '.');
@endphp
<div style="background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

    @include('admin.partials.table-header', [
        'titulo'    => '🧭 Cómo van los asesores',
        'subtitulo' => 'Cartera del mes, nivel que le corresponde y lo que dejó cada uno. Este informe no cambia nada: el tipo de cobro y el porcentaje se editan en la ficha del asesor.',
    ])

    <form method="GET" action="{{ route('admin.asesores.como_van') }}" style="background:#f8fafc;padding:1rem 1.25rem;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:1.25rem;display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Mes</label>
            <select name="mes" style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;min-width:150px;">
                @foreach(range(1,12) as $m)
                    <option value="{{ $m }}" {{ $m == $mes ? 'selected' : '' }}>{{ \Carbon\Carbon::create(null,$m)->locale('es')->isoFormat('MMMM') }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Año</label>
            <select name="anio" style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;min-width:100px;">
                @foreach(range(now()->year - 2, now()->year) as $a)
                    <option value="{{ $a }}" {{ $a == $anio ? 'selected' : '' }}>{{ $a }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:0.6rem 1.5rem;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;">🔍 Ver</button>
        <a href="{{ route('admin.asesores.index') }}" style="margin-left:auto;font-size:0.85rem;font-weight:600;color:#2563eb;text-decoration:none;">← Asesores</a>
    </form>

    @if($mesEnCurso)
        <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:0.6rem 0.9rem;font-size:0.82rem;margin-bottom:1.25rem;">
            {{ ucfirst($periodoLabel) }} está en curso: la cartera se cuenta con los contratos activos hasta hoy y la plata con lo pagado hasta hoy.
        </div>
    @endif

    {{-- Resumen --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
        <div style="background:#f1f5f9;padding:1rem;border-radius:10px;border-left:4px solid #2563eb;">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;">Personas con asesor</div>
            <div style="font-size:1.3rem;font-weight:700;color:#0f172a;">{{ number_format($totales['personas'], 0, ',', '.') }}</div>
            <div style="font-size:0.7rem;color:#94a3b8;">Sin contar la oficina · {{ $periodoLabel }}</div>
        </div>
        <div style="background:#f1f5f9;padding:1rem;border-radius:10px;border-left:4px solid #16a34a;">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;">Administración pagada</div>
            <div style="font-size:1.3rem;font-weight:700;color:#0f172a;">{{ $cop($totales['admon']) }}</div>
            <div style="font-size:0.7rem;color:#94a3b8;">Lo que quedó al aliado</div>
        </div>
        <div style="background:#f1f5f9;padding:1rem;border-radius:10px;border-left:4px solid #8b5cf6;">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;">Comisiones de asesores</div>
            <div style="font-size:1.3rem;font-weight:700;color:#0f172a;">{{ $cop($totales['comision']) }}</div>
            <div style="font-size:0.7rem;color:#94a3b8;">Administración y afiliación ya pagadas</div>
        </div>
        @if($escalera)
        <div style="background:#f8fafc;padding:1rem;border-radius:10px;border:1px dashed #cbd5e1;">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;margin-bottom:0.35rem;">Escalera de administración</div>
            <div style="font-size:0.82rem;color:#334155;line-height:1.5;">
                @foreach($escalera['niveles'] as $desde => $pct)
                    <span style="white-space:nowrap;"><strong>{{ $pct }} %</strong> desde {{ $desde }}</span>@if(! $loop->last) · @endif
                @endforeach
            </div>
            <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.25rem;">Tarifa más común: {{ $cop($tarifaLista) }}</div>
        </div>
        @endif
    </div>

    {{-- Un asesor por tarjeta --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;">
        @forelse($filas as $f)
            @php [$bg, $fg, $ico] = $tonos[$f->estado['tono']] ?? $tonos['neutro']; @endphp
            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:1.1rem;display:flex;flex-direction:column;gap:0.8rem;min-width:0;">
                <div style="display:flex;justify-content:space-between;gap:0.75rem;align-items:flex-start;">
                    <div style="min-width:0;">
                        <a href="{{ route('admin.asesores.show', $f->asesor) }}" style="font-weight:700;color:#0d2550;text-decoration:none;font-size:1rem;">{{ $f->asesor->nombre }}</a>
                        <div style="font-size:0.75rem;color:#64748b;margin-top:0.15rem;">
                            {{ \App\Models\Asesor::TIPOS_COBRO[$f->asesor->tipo_cobro] ?? $f->asesor->tipo_cobro }}
                            @if($f->asesor->forma_pago) · {{ \App\Models\Asesor::FORMAS_PAGO[$f->asesor->forma_pago] ?? $f->asesor->forma_pago }} @endif
                        </div>
                    </div>
                    <div style="text-align:right;flex:0 0 auto;">
                        <div style="font-size:1.6rem;font-weight:800;color:#0f172a;line-height:1;">{{ $f->personas }}</div>
                        <div style="font-size:0.68rem;color:#64748b;text-transform:uppercase;font-weight:600;">personas</div>
                    </div>
                </div>

                {{-- Tendencia de tres meses --}}
                <div style="display:flex;gap:0.4rem;">
                    @foreach($f->serie as $s)
                        <div style="flex:1;background:{{ $loop->last ? '#eff6ff' : '#f8fafc' }};border-radius:8px;padding:0.4rem;text-align:center;">
                            <div style="font-size:0.95rem;font-weight:700;color:{{ $loop->last ? '#1d4ed8' : '#334155' }};">{{ $s['n'] }}</div>
                            <div style="font-size:0.66rem;color:#64748b;text-transform:uppercase;">{{ $s['label'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;font-size:0.8rem;">
                    <div>
                        <div style="font-size:0.66rem;color:#64748b;text-transform:uppercase;font-weight:600;">Tiene hoy</div>
                        <div style="font-weight:600;color:#0f172a;">{{ $f->tiene }}</div>
                    </div>
                    <div>
                        <div style="font-size:0.66rem;color:#64748b;text-transform:uppercase;font-weight:600;">Por cartera</div>
                        <div style="font-weight:600;color:#0f172a;">
                            @if(! $f->nivel) —
                            @else {{ $f->nivel['pct'] }} % (desde {{ $f->nivel['desde'] }})
                            @endif
                        </div>
                    </div>
                </div>

                <div style="background:{{ $bg }};color:{{ $fg }};border-radius:8px;padding:0.6rem 0.75rem;font-size:0.82rem;line-height:1.45;">
                    {{ $ico }} {{ $f->estado['texto'] }}
                </div>

                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;font-size:0.78rem;border-top:1px solid #f1f5f9;padding-top:0.7rem;margin-top:auto;">
                    <div>
                        <div style="font-size:0.64rem;color:#64748b;text-transform:uppercase;font-weight:600;">Dejó al aliado</div>
                        <div style="font-weight:700;color:#166534;">{{ $cop($f->admon) }}</div>
                    </div>
                    <div>
                        <div style="font-size:0.64rem;color:#64748b;text-transform:uppercase;font-weight:600;">Su comisión</div>
                        <div style="font-weight:700;color:#6d28d9;">{{ $cop($f->comision) }}</div>
                    </div>
                    <div>
                        <div style="font-size:0.64rem;color:#64748b;text-transform:uppercase;font-weight:600;">Pagaron</div>
                        <div style="font-weight:700;color:#0f172a;">{{ $f->pagaron }} de {{ $f->facturadas }}</div>
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;gap:0.5rem;font-size:0.78rem;">
                    <span style="color:#64748b;">{{ $f->afiliaciones }} {{ $f->afiliaciones === 1 ? 'afiliación' : 'afiliaciones' }} en el mes</span>
                    <a href="{{ route('admin.asesores.edit', $f->asesor) }}" style="color:#2563eb;font-weight:600;text-decoration:none;">Cambiar tipo de cobro →</a>
                </div>
            </div>
        @empty
            <div style="grid-column:1/-1;text-align:center;color:#64748b;padding:2rem;">No hay asesores activos en este aliado.</div>
        @endforelse
    </div>

    <p style="font-size:0.75rem;color:#94a3b8;margin-top:1.25rem;line-height:1.5;">
        Personas = cédulas distintas con contrato activo en algún momento del mes o con planilla facturada ese mes (una cédula con varios contratos cuenta una vez).
        «Dejó al aliado» y «Su comisión» solo cuentan facturas ya pagadas.
    </p>
</div>
@endsection
