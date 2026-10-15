@extends('layouts.app')
@section('modulo','Retirados del Mes')
@section('contenido')
@php 
    $mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']; 
    $years = range(now()->year-3, now()->year); 
@endphp

<style>
/* Estilo premium para los selectores de filtro en cabeceras */
.th-select {
    width: 100%; 
    background: transparent; 
    border: none; 
    border-bottom: 1.5px solid #cbd5e1;
    color: #475569; 
    font-size: .68rem; 
    font-weight: 700; 
    text-transform: uppercase;
    letter-spacing: .04em; 
    padding: .25rem 0; 
    cursor: pointer; 
    outline: none;
    font-family: inherit;
    appearance: auto;
    -webkit-appearance: auto;
}
.th-select:hover { 
    border-bottom-color: #94a3b8; 
}
.th-select:focus { 
    border-bottom-color: #2563eb; 
}
.th-select option { 
    background: #fff; 
    color: #0f172a; 
    font-weight: 500; 
    text-transform: none; 
    letter-spacing: normal;
}
.th-select.activo { 
    border-bottom-color: #2563eb; 
    color: #2563eb; 
}
</style>

<!-- max-width: 1450px para ampliar el cuadro y dar espacio a todas las columnas -->
<div style="max-width:1450px;margin:0 auto;padding:0 1rem;">
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;">
        <a href="{{ route('admin.informes.hub') }}" style="color:#64748b;font-size:.82rem;text-decoration:none;">← Informes</a>
        <h1 style="font-size:1.2rem;font-weight:700;color:#0d2550;flex:1;">🚪 Retirados del Mes</h1>
        <span style="background:#fef3c7;color:#92400e;font-size:.82rem;font-weight:700;padding:.3rem .75rem;border-radius:999px;">{{ $retirados->count() }} retirados</span>
    </div>

    <div style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center;">
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            {{-- Preservar los filtros al cambiar de mes/año --}}
            @foreach(request()->except(['mes','anio','page']) as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            <select name="mes" style="padding:.45rem .75rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.84rem;">
                @foreach($mesesEs as $n=>$nm) 
                    @if($n>0) <option value="{{ $n }}" {{ $mes==$n?'selected':'' }}>{{ $nm }}</option> @endif 
                @endforeach
            </select>
            <select name="anio" style="padding:.45rem .75rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.84rem;">
                @foreach($years as $y) 
                    <option value="{{ $y }}" {{ $anio==$y?'selected':'' }}>{{ $y }}</option> 
                @endforeach
            </select>
            <button type="submit" style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:.45rem 1.25rem;font-size:.85rem;cursor:pointer;">Ver</button>
        </form>
        {{-- Excel preserva todos los filtros de consulta aplicados --}}
        <a href="?{{ http_build_query(array_merge(request()->query(), ['excel'=>1])) }}" style="background:#16a34a;color:#fff;border-radius:8px;padding:.45rem 1rem;font-size:.82rem;font-weight:600;text-decoration:none;">📥 Excel</a>
    </div>

    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:.65rem .8rem;text-align:center;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:40px;">N°</th>
                    <th style="padding:.65rem .8rem;text-align:left;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:90px;">Cédula</th>
                    <th style="padding:.65rem .8rem;text-align:left;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:145px;">Nombre</th>
                    
                    {{-- Filtro Razón Social en el título --}}
                    <th style="padding:.65rem .8rem;text-align:left;min-width:160px;">
                        <form method="GET" style="margin:0">
                            @foreach(request()->except(['f_rs','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            <select name="f_rs" onchange="this.form.submit()" class="th-select {{ $fRs ? 'activo' : '' }}">
                                <option value="">↓ Razón Social</option>
                                <option value="todos">Todos</option>
                                @foreach($opcRs as $rsVal)
                                    <option value="{{ $rsVal }}" {{ $fRs===$rsVal?'selected':'' }}>{{ \Illuminate\Support\Str::limit($rsVal, 22, '…') }}</option>
                                @endforeach
                            </select>
                        </form>
                    </th>

                    {{-- Filtro Empresa cliente en el título --}}
                    <th style="padding:.65rem .8rem;text-align:left;min-width:150px;">
                        <form method="GET" style="margin:0">
                            @foreach(request()->except(['f_empresa','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            <select name="f_empresa" onchange="this.form.submit()" class="th-select {{ $fEmpresa ? 'activo' : '' }}">
                                <option value="">↓ Empresa</option>
                                <option value="todos">Todos</option>
                                <option value="sin" {{ $fEmpresa==='sin'?'selected':'' }}>Sin empresa</option>
                                @foreach($opcEmpresa as $empVal)
                                    <option value="{{ $empVal }}" {{ $fEmpresa===$empVal?'selected':'' }}>{{ \Illuminate\Support\Str::limit($empVal, 22, '…') }}</option>
                                @endforeach
                            </select>
                        </form>
                    </th>

                    {{-- Filtro Plan en el título --}}
                    <th style="padding:.65rem .8rem;text-align:left;min-width:110px;">
                        <form method="GET" style="margin:0">
                            @foreach(request()->except(['f_plan','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            <select name="f_plan" onchange="this.form.submit()" class="th-select {{ $fPlan ? 'activo' : '' }}">
                                <option value="">↓ Plan</option>
                                <option value="todos">Todos</option>
                                @foreach($opcPlan as $planVal)
                                    <option value="{{ $planVal }}" {{ $fPlan===$planVal?'selected':'' }}>{{ $planVal }}</option>
                                @endforeach
                            </select>
                        </form>
                    </th>

                    {{-- Filtro Modalidad en el título --}}
                    <th style="padding:.65rem .8rem;text-align:left;min-width:115px;">
                        <form method="GET" style="margin:0">
                            @foreach(request()->except(['f_modalidad','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            <select name="f_modalidad" onchange="this.form.submit()" class="th-select {{ $fModalidad ? 'activo' : '' }}">
                                <option value="">↓ Modalidad</option>
                                <option value="todos">Todos</option>
                                @foreach($opcModalidad as $modVal)
                                    <option value="{{ $modVal }}" {{ $fModalidad===$modVal?'selected':'' }}>{{ $modVal }}</option>
                                @endforeach
                            </select>
                        </form>
                    </th>

                    <th style="padding:.65rem .8rem;text-align:center;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:55px;">Días</th>
                    <th style="padding:.65rem .8rem;text-align:center;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:90px;">Fecha Retiro</th>
                    <th style="padding:.65rem .8rem;text-align:center;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:110px;" title="Fecha en que se marcó el retiro en el sistema">🗓 Marcado Retiro</th>

                    {{-- Filtro Motivo en el título --}}
                    <th style="padding:.65rem .8rem;text-align:left;min-width:120px;">
                        <form method="GET" style="margin:0">
                            @foreach(request()->except(['f_motivo','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            <select name="f_motivo" onchange="this.form.submit()" class="th-select {{ $fMotivo ? 'activo' : '' }}">
                                <option value="">↓ Motivo</option>
                                <option value="todos">Todos</option>
                                @foreach($opcMotivo as $motVal)
                                    <option value="{{ $motVal }}" {{ $fMotivo===$motVal?'selected':'' }}>{{ $motVal }}</option>
                                @endforeach
                            </select>
                        </form>
                    </th>

                    {{-- Filtro Tipo Retiro en el título --}}
                    <th style="padding:.65rem .8rem;text-align:center;min-width:110px;">
                        <form method="GET" style="margin:0">
                            @foreach(request()->except(['f_tipo','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            <select name="f_tipo" onchange="this.form.submit()" class="th-select {{ $fTipo ? 'activo' : '' }}" style="text-align:center;">
                                <option value="">↓ Tipo Retiro</option>
                                <option value="todos">Todos</option>
                                @foreach($opcTipo as $tipoVal)
                                    <option value="{{ $tipoVal }}" {{ $fTipo===$tipoVal?'selected':'' }}>{{ $tipoVal }}</option>
                                @endforeach
                            </select>
                        </form>
                    </th>

                    <th style="padding:.65rem .8rem;text-align:center;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:90px;">Renovó</th>
                    <th style="padding:.65rem .8rem;text-align:right;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:90px;">Costo SS</th>
                    <th style="padding:.65rem .8rem;text-align:left;font-size:.72rem;text-transform:uppercase;color:#64748b;min-width:150px;">Observación</th>
                </tr>
            </thead>
            <tbody>
                @forelse($retirados as $r)
                <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
                    <td style="padding:.6rem .8rem;text-align:center;font-weight:600;color:#64748b;">{{ $loop->iteration }}</td>
                    <td style="padding:.6rem .8rem;font-weight:600;color:#b45309;">{{ $r->cedula }}</td>
                    <td style="padding:.6rem .8rem;font-weight:600;color:#0d2550;">{{ nombre_oracion($r->nombre_completo) }}</td>
                    <td style="padding:.6rem .8rem;color:#475569;">{{ $r->razon_social ?? '—' }}</td>
                    <td style="padding:.6rem .8rem;color:#475569;">{{ $r->empresa ?? '—' }}</td>
                    
                    {{-- Plan más pequeño y en un solo renglón (nowrap) --}}
                    <td style="padding:.6rem .8rem;color:#475569;font-size:.73rem;white-space:nowrap;">{{ $r->plan_nombre ?? '—' }}</td>
                    
                    <td style="padding:.6rem .8rem;color:#475569;font-weight:600;">{{ $r->modalidad_nombre ?? '—' }}</td>
                    <td style="padding:.6rem .8rem;text-align:center;font-weight:700;color:#334155;">{{ $r->dias_retiro ?? 0 }}</td>
                    <td style="padding:.6rem .8rem;text-align:center;color:#92400e;font-weight:600;">{{ sqldate($r->fecha_retiro)?->format('d/m/Y') }}</td>
                    <td style="padding:.6rem .8rem;text-align:center;color:#64748b;font-size:.78rem;">
                        @if($r->fecha_marcado_retiro)
                            <span title="Fecha en que se marcó el retiro en el sistema">{{ sqldate($r->fecha_marcado_retiro)?->format('d/m/Y') }}</span>
                            <br><span style="color:#94a3b8;font-size:.7rem;">{{ sqldate($r->fecha_marcado_retiro)?->format('H:i') }}</span>
                        @else
                            <span style="color:#cbd5e1">—</span>
                        @endif
                    </td>
                    <td style="padding:.6rem .8rem;color:#475569;">{{ $r->motivo ?? '—' }}</td>
                    <td style="padding:.6rem .8rem;text-align:center;white-space:nowrap;">
                        @if($r->tipo_retiro === 'Real')
                            <span style="display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.65rem;font-weight:700;background:#dbeafe;color:#1e40af;">🔵 Real</span>
                        @else
                            <span style="display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.65rem;font-weight:700;background:#f1f5f9;color:#64748b;">⚪ Informativo</span>
                        @endif
                    </td>
                    <td style="padding:.6rem .8rem;text-align:center;white-space:nowrap;">
                        @if($r->tiene_contrato_vigente > 0)
                            <span style="display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.65rem;font-weight:700;background:#dcfce7;color:#166534;">🟢 Sí</span>
                        @else
                            <span style="display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.65rem;font-weight:700;background:#fee2e2;color:#991b1b;">🔴 No</span>
                        @endif
                    </td>
                    <td style="padding:.6rem .8rem;text-align:right;font-family:monospace;font-weight:600;color:#1e40af;">
                        ${{ number_format($r->costo_ss ?? 0, 0, ',', '.') }}
                    </td>
                    <td style="padding:.6rem .8rem;color:#64748b;font-size:.8rem;max-width:220px;word-break:break-word;">{{ $r->observacion ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="15" style="padding:2rem;text-align:center;color:#94a3b8;">Sin retirados para este período</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="background:#f8fafc;border-top:2px solid #cbd5e1;font-weight:700;color:#0d2550;">
                    <td style="padding:.65rem .8rem;text-align:left;" colspan="4">TOTALES ({{ $retirados->count() }} registros)</td>
                    <td colspan="9"></td>
                    <td style="padding:.65rem .8rem;text-align:right;font-family:monospace;font-weight:700;color:#1e40af;font-size:.85rem;">
                        ${{ number_format($retirados->sum('costo_ss'), 0, ',', '.') }}
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
