@extends('layouts.app')
@section('modulo', 'Cuadre Consolidado')

@php
$fmt = fn($v) => '$'.number_format($v ?? 0, 0, ',', '.');
@endphp

@section('contenido')
<style>
.con-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;color:#fff;padding:1rem 1.4rem;margin-bottom:1rem}
table.tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.tbl th{background:#0f172a;color:#94a3b8;font-size:.63rem;text-transform:uppercase;letter-spacing:.05em;padding:.4rem .6rem;position:sticky;top:0}
.tbl td{padding:.38rem .6rem;border-bottom:1px solid #f1f5f9}
.tbl tr:hover td{background:#f8fafc}
.num{text-align:right;font-family:monospace}
.badge-est{padding:.12rem .45rem;border-radius:20px;font-size:.67rem;font-weight:700}
</style>

<div class="con-header">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
        <div>
            <a href="{{ route('admin.cuadre-diario.index') }}"
               style="color:#94a3b8;font-size:.78rem;text-decoration:none">← Volver a mi cuadre</a>
            <div style="font-size:1.1rem;font-weight:800;margin-top:.2rem">📊 Cuadre Consolidado</div>
            @if($vistaMes)
            <div style="font-size:.78rem;color:#94a3b8;text-transform:capitalize">
                {{ \Carbon\Carbon::parse($mes . '-01')->locale('es')->translatedFormat('F Y') }}
            </div>
            @endif
        </div>
        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
            @php
                $campo = 'padding:.28rem .5rem;border-radius:6px;border:1.5px solid #334155;background:#1e293b;color:#fff;font-size:.8rem';
                $tab   = fn($activo) => 'padding:.28rem .7rem;font-size:.75rem;font-weight:700;text-decoration:none;'
                    . ($activo ? 'background:#3b82f6;color:#fff' : 'background:#1e293b;color:#94a3b8');
                $filtroUsuario = request('usuario_id') ? ['usuario_id' => request('usuario_id')] : [];
            @endphp
            <div style="display:flex;border:1.5px solid #334155;border-radius:6px;overflow:hidden">
                <a href="{{ route('admin.cuadre-diario.consolidado', ['fecha' => $vistaMes ? ($mes === substr(today()->toDateString(), 0, 7) ? today()->toDateString() : $mes . '-01') : $fecha] + $filtroUsuario) }}"
                   style="{{ $tab(! $vistaMes) }}">Día</a>
                <a href="{{ route('admin.cuadre-diario.consolidado', ['vista' => 'mes', 'mes' => $mes] + $filtroUsuario) }}"
                   style="{{ $tab($vistaMes) }}">Mes</a>
            </div>
            <form method="GET" style="display:flex;gap:.3rem;align-items:center">
                @if($vistaMes)
                <input type="hidden" name="vista" value="mes">
                <input type="month" name="mes" value="{{ $mes }}" onchange="this.form.submit()" style="{{ $campo }}">
                @else
                <input type="date" name="fecha" value="{{ $fecha }}" onchange="this.form.submit()" style="{{ $campo }}">
                @endif
                <select name="usuario_id" onchange="this.form.submit()" style="{{ $campo }}">
                    <option value="">Todos los usuarios</option>
                    @foreach($usuarios as $u)
                    <option value="{{ $u->id }}" {{ request('usuario_id') == $u->id ? 'selected' : '' }}>
                        {{ $u->nombre }}
                    </option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>
</div>

{{-- Saldos bancarios --}}
@if($saldosBanco->isNotEmpty())
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.7rem;margin-bottom:.8rem">
    @foreach($saldosBanco as $sb)
    <div style="background:#fff;border-radius:10px;border:1px solid #e2e8f0;padding:.8rem 1rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.3rem">
            🏦 {{ $sb['banco']->banco }}
        </div>
        <div style="font-size:1.2rem;font-weight:800;color:#1d4ed8">{{ $fmt($sb['saldo']) }}</div>
    </div>
    @endforeach
</div>
@endif

{{-- Tabla consolidada del día, usuario por usuario --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden">
    @if($resumen->isEmpty())
    <div style="padding:2rem;text-align:center;color:#94a3b8">Nadie movió plata {{ $vistaMes ? 'en el mes seleccionado' : 'en la fecha seleccionada' }}</div>
    @else
    <div style="overflow-x:auto">
    <table class="tbl">
        <thead><tr>
            <th>Usuario</th>
            <th class="num">Facturas</th>
            @unless($vistaMes)<th class="num">Caja menor</th>@endunless
            <th class="num">Efectivo recibido</th>
            <th class="num">Consignado</th>
            <th class="num" title="Todo lo que salió del efectivo: gastos, traslados al banco y pagos de incapacidad">Salidas efectivo</th>
            <th class="num">{{ $vistaMes ? 'Neto efectivo' : 'Efectivo en caja' }}</th>
            <th>{{ $vistaMes ? 'Días cuadrados' : 'Estado' }}</th>
            <th style="text-align:center">Ver</th>
        </tr></thead>
        <tbody>
        @foreach($resumen as $r)
        <tr>
            <td style="font-weight:600">{{ $r->usuario->nombre }}</td>
            <td class="num" style="color:#64748b">{{ $r->facturas ?: '—' }}</td>
            @unless($vistaMes)<td class="num">{{ $fmt($r->base_caja) }}</td>@endunless
            <td class="num" style="color:#15803d;font-weight:600">+{{ $fmt($r->efectivo_total) }}</td>
            <td class="num" style="color:#0369a1">{{ $r->consignado ? $fmt($r->consignado) : '—' }}</td>
            <td class="num" style="color:#dc2626">-{{ $fmt($r->gastos_efectivo) }}</td>
            <td class="num" style="font-weight:800;color:#1d4ed8">{{ $fmt($r->saldo_esperado) }}</td>
            <td>
                @if($vistaMes)
                <span class="badge-est" style="{{ $r->dias_cuadrados >= $r->dias_movimiento ? 'background:#dcfce7;color:#15803d' : 'background:#fef3c7;color:#92400e' }}">
                    {{ $r->dias_cuadrados }} de {{ $r->dias_movimiento }}
                </span>
                @elseif($r->cuadre)
                <span class="badge-est" style="background:#dcfce7;color:#15803d">✅ Cuadrado</span>
                <div style="font-size:.68rem;color:#64748b;margin-top:.15rem">
                    {{ $r->cuadre->cerradoPor?->nombre ?? '—' }} · {{ $fmt($r->cuadre->saldo_cierre) }}
                </div>
                @else
                <span class="badge-est" style="background:#fef3c7;color:#92400e">🕐 Pendiente</span>
                @endif
            </td>
            <td style="text-align:center">
                <a href="{{ $vistaMes
                        ? route('admin.cuadre-diario.consolidado', ['vista' => 'mes', 'mes' => $mes, 'usuario_id' => $r->usuario->id])
                        : route('admin.cuadre-diario.index', ['fecha' => $fecha, 'usuario_id' => $r->usuario->id]) }}"
                   style="padding:.2rem .55rem;background:#0f172a;color:#fff;border-radius:5px;font-size:.72rem;text-decoration:none">
                    👁️ Ver
                </a>
            </td>
        </tr>
        @endforeach
        </tbody>
        <tfoot><tr style="background:#0f172a;color:#e2e8f0;font-weight:700">
            <td colspan="{{ $vistaMes ? 2 : 3 }}" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em">{{ $vistaMes ? 'Total del mes' : 'Total del día' }}</td>
            <td class="num" style="color:#4ade80">+{{ $fmt($resumen->sum('efectivo_total')) }}</td>
            <td class="num" style="color:#7dd3fc">{{ $fmt($resumen->sum('consignado')) }}</td>
            <td class="num" style="color:#f87171">-{{ $fmt($resumen->sum('gastos_efectivo')) }}</td>
            <td class="num" style="color:#fbbf24">{{ $fmt($resumen->sum('saldo_esperado')) }}</td>
            <td colspan="2"></td>
        </tr></tfoot>
    </table>
    </div>
    @endif
</div>

@if($vistaMes && $resumen->isNotEmpty())
@php
    // Ver lleva a las facturas del día del usuario escogido en el card (o el de arriba).
    $usuarioVer = $usuarioDia ?? request('usuario_id');
@endphp
<div id="dia-por-dia" style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;margin-top:.8rem">
    <div style="padding:.5rem .9rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap">
        <div style="font-size:.78rem;font-weight:800;color:#0f172a">📅 Día por día</div>
        <form method="GET" action="{{ route('admin.cuadre-diario.consolidado') }}#dia-por-dia" style="margin:0">
            <input type="hidden" name="vista" value="mes">
            <input type="hidden" name="mes" value="{{ $mes }}">
            @if(request('usuario_id'))<input type="hidden" name="usuario_id" value="{{ request('usuario_id') }}">@endif
            <select name="usuario_dia" onchange="this.form.submit()"
                    style="padding:.25rem .5rem;border-radius:6px;border:1.5px solid #cbd5e1;background:#fff;color:#0f172a;font-size:.78rem">
                <option value="">Todos los usuarios</option>
                @foreach($resumen as $r)
                <option value="{{ $r->usuario->id }}" {{ $usuarioDia === $r->usuario->id ? 'selected' : '' }}>
                    {{ $r->usuario->nombre }}
                </option>
                @endforeach
            </select>
        </form>
    </div>
    @if($resumenDias->isEmpty())
    <div style="padding:1.5rem;text-align:center;color:#94a3b8">Ese usuario no movió plata en el mes</div>
    @else
    <div style="overflow-x:auto">
    <table class="tbl">
        <thead><tr>
            <th>Día</th>
            <th class="num">Facturas</th>
            <th class="num">Efectivo recibido</th>
            <th class="num">Consignado</th>
            <th class="num" title="Todo lo que salió del efectivo: gastos, traslados al banco y pagos de incapacidad">Salidas efectivo</th>
            <th class="num">Neto efectivo</th>
            <th>Cuadrados</th>
            <th style="text-align:center">Ver</th>
        </tr></thead>
        <tbody>
        @foreach($resumenDias as $d)
        <tr>
            <td style="font-weight:600;text-transform:capitalize">{{ \Carbon\Carbon::parse($d->dia)->locale('es')->translatedFormat('D d') }}</td>
            <td class="num" style="color:#64748b">{{ $d->facturas ?: '—' }}</td>
            <td class="num" style="color:#15803d;font-weight:600">+{{ $fmt($d->efectivo_total) }}</td>
            <td class="num" style="color:#0369a1">{{ $d->consignado ? $fmt($d->consignado) : '—' }}</td>
            <td class="num" style="color:#dc2626">-{{ $fmt($d->gastos_efectivo) }}</td>
            <td class="num" style="font-weight:800;color:#1d4ed8">{{ $fmt($d->efectivo_total - $d->gastos_efectivo) }}</td>
            <td>
                <span class="badge-est" style="{{ $d->cuadrados >= $d->usuarios ? 'background:#dcfce7;color:#15803d' : 'background:#fef3c7;color:#92400e' }}">
                    {{ $d->cuadrados }} de {{ $d->usuarios }}
                </span>
            </td>
            <td style="text-align:center">
                <a href="{{ route('admin.cuadre-diario.facturas-dia', array_filter(['fecha' => $d->dia, 'usuario_id' => $usuarioVer])) }}"
                   style="padding:.2rem .55rem;background:#0f172a;color:#fff;border-radius:5px;font-size:.72rem;text-decoration:none">
                    👁️ Ver
                </a>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>
@endif
@endsection
