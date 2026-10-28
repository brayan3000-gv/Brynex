{{-- Empresas en bandejas por estado (deben / afiliación de un mes anterior / al día), con filas de avance --}}
@php
$debeMes  = fn($e) => ($e->afil_pend + $e->indep_pend + $e->plan_pend) > 0;
$bDeben   = $empresas->filter($debeMes);
$bAtras   = $empresas->reject($debeMes)->filter(fn($e) => ($e->afil_atrasadas ?? 0) > 0);
$bAlDia   = $empresas->reject($debeMes)->reject(fn($e) => ($e->afil_atrasadas ?? 0) > 0);
$pesos    = fn($v) => '$'.number_format($v ?? 0, 0, '', '.');
$mesCorto = fn($periodo) => mb_strtolower(explode(' ', $periodo)[0]);
@endphp
<style>
.bj { background:#fff; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:.75rem; overflow:hidden; }
.bj > summary { list-style:none; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:.6rem; padding:.65rem 1rem; }
.bj > summary::-webkit-details-marker { display:none; }
.bj-tit { font-size:.85rem; font-weight:800; display:flex; align-items:center; gap:.45rem; }
.bj-cnt { font-size:.72rem; font-weight:800; padding:.1rem .5rem; border-radius:20px; }
.bj-res { font-size:.72rem; color:#64748b; display:flex; gap:.9rem; align-items:center; }
.bj-res b { color:#0f172a; }
.bj-flecha { font-size:.7rem; color:#94a3b8; transition:transform .15s; }
.bj[open] .bj-flecha { transform:rotate(90deg); }
.bj-deben  { border-top:3px solid #dc2626; } .bj-deben .bj-cnt  { background:#fee2e2; color:#b91c1c; }
.bj-atras  { border-top:3px solid #d97706; } .bj-atras .bj-cnt  { background:#fef3c7; color:#92400e; }
.bj-aldia  { border-top:3px solid #15803d; } .bj-aldia .bj-cnt  { background:#dcfce7; color:#15803d; }
.bf { display:grid; align-items:center; gap:.75rem; padding:.55rem 1rem; border-top:1px solid #f1f5f9; font-size:.77rem;
      grid-template-columns: 26px minmax(0,2.3fr) 110px minmax(0,2.2fr) 110px minmax(0,1.5fr) {{ $esAdmin ? '128px' : '' }} 70px; }
.bf:hover { background:#f8fafc; }
.bf-head { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; padding-top:.45rem; padding-bottom:.45rem; background:#f8fafc; }
.bf-head:hover { background:#f8fafc; }
.bf-av { font-size:.72rem; color:#334155; margin-bottom:.2rem; } .bf-av b { font-size:.82rem; }
.bf-bar { height:6px; border-radius:3px; background:#e2e8f0; overflow:hidden; }
.bf-bar i { display:block; height:100%; background:#22c55e; border-radius:3px; }
.chip { display:inline-block; font-size:.66rem; font-weight:700; padding:.12rem .45rem; border-radius:6px; margin:.1rem .25rem .1rem 0; white-space:nowrap; }
.ch-plan { background:#dbeafe; color:#1e40af; }
.ch-ind  { background:#e0f2fe; color:#0369a1; }
.ch-afil { background:#ede9fe; color:#6d28d9; }
.ch-atr  { background:#fef3c7; color:#92400e; }
.ch-ok   { background:#dcfce7; color:#15803d; }
.bf-plata { text-align:right; font-family:monospace; }
.bf-plata b { font-size:.85rem; color:#0f172a; }
.bf-mora { font-size:.66rem; color:#b45309; font-weight:700; }
.bf-ges { font-size:.7rem; color:#334155; min-width:0; }
.bf-ges .obs { color:#94a3b8; font-size:.66rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bf-acc { display:flex; gap:.3rem; justify-content:flex-end; }
.bf-acc a { font-size:.8rem; text-decoration:none; padding:.22rem .45rem; background:#dbeafe; color:#1e40af; border-radius:7px; font-weight:700; }
.bf-num { font-family:monospace; font-size:.72rem; font-weight:700; color:#94a3b8; text-align:right; }
</style>


@php
$bandejas = [
    ['deben', '🔴 Deben este mes', 'Contratos de '.mb_strtolower($meses[$mes]).' sin pagar', $bDeben, true],
    ['atras', '🟠 Solo deben una afiliación de un mes anterior', 'El mes está pagado; falta cobrar o registrar en $0 esa afiliación', $bAtras, true],
    ['aldia', '🟢 Al día', 'Todo pagado este mes', $bAlDia, false],
];
@endphp

@foreach($bandejas as [$clave, $titulo, $ayuda, $lista, $abierta])
<details class="bj bj-{{ $clave }}" {{ $abierta && $lista->isNotEmpty() ? 'open' : '' }}>
    <summary>
        <div>
            <div class="bj-tit"><span class="bj-flecha">▶</span> {{ $titulo }} <span class="bj-cnt">{{ $lista->count() }}</span></div>
            <div style="font-size:.68rem;color:#94a3b8;margin-left:1.15rem;">{{ $ayuda }}</div>
        </div>
        <div class="bj-res">
            @if($clave === 'deben')
                <span><b>{{ $lista->sum(fn($e) => $e->afil_pend + $e->indep_pend + $e->plan_pend) }}</b> contratos por cobrar</span>
                <span>Admon <b>{{ $pesos($lista->sum('admon_pend')) }}</b></span>
                <span>Mora <b style="color:#b45309;">{{ $pesos($lista->sum('mora_estimada')) }}</b></span>
            @elseif($clave === 'atras')
                <span><b>{{ $lista->sum('afil_atrasadas') }}</b> afiliaciones</span>
                <span>Valor <b>{{ $pesos($lista->sum('afil_atrasadas_valor')) }}</b></span>
            @else
                <span><b>{{ $lista->sum('pagados') }}</b> contratos pagados</span>
            @endif
        </div>
    </summary>

    @if($lista->isNotEmpty())
    <div class="bf bf-head">
        <span style="text-align:right;">#</span><span>Empresa</span><span>Pagados</span><span>Qué falta</span><span style="text-align:right;">Por cobrar</span><span>Última gestión</span>
        @if($esAdmin)<span>Encargado</span>@endif
        <span></span>
    </div>
    @foreach($lista as $emp)
    @php
        [$semIco, $semColor, $semTip] = $semLabel($emp->semaforo);
        $pct = $emp->cant > 0 ? round($emp->pagados / $emp->cant * 100) : 0;
        $tel = $emp->telefono ?? $emp->celular ?? '';
        $wa  = $waUrl($tel);
        $urlFact = route('admin.facturacion.empresa', ['id' => $emp->id, 'mes' => $mes, 'anio' => $anio]);
    @endphp
    <div class="bf">
        <div class="bf-num">{{ $loop->iteration }}</div>

        {{-- Empresa y contacto --}}
        <div style="min-width:0;">
            <a href="{{ $urlFact }}" target="_blank" class="emp-nombre" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $emp->empresa ?? "Empresa #{$emp->id}" }}</a>
            <div class="emp-contacto" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                {{ $emp->contacto ?: 'Sin contacto' }}@if($tel) · {{ $tel }}@endif
                @if($wa)<a href="{{ $wa }}" target="_blank" title="Abrir WhatsApp" style="text-decoration:none;color:#25d366;font-weight:800;"> WA</a>@endif
            </div>
        </div>

        {{-- Avance --}}
        <div>
            <div class="bf-av"><b>{{ $emp->pagados }}</b> de {{ $emp->cant }}</div>
            <div class="bf-bar"><i style="width:{{ $pct }}%"></i></div>
        </div>

        {{-- Qué falta, en palabras --}}
        <div>
            @if($emp->plan_pend > 0)<span class="chip ch-plan">{{ $emp->plan_pend }} planilla{{ $emp->plan_pend > 1 ? 's' : '' }}</span>@endif
            @if($emp->indep_pend > 0)<span class="chip ch-ind">{{ $emp->indep_pend }} independiente{{ $emp->indep_pend > 1 ? 's' : '' }}</span>@endif
            @if($emp->afil_pend > 0)<span class="chip ch-afil">{{ $emp->afil_pend }} afiliación{{ $emp->afil_pend > 1 ? 'es' : '' }} del mes</span>@endif
            @if(($emp->afil_atrasadas ?? 0) > 0)
                <span class="chip ch-atr" title="Se factura en la planilla de la empresa de ese mes">
                    {{ $emp->afil_atrasadas }} afil. de {{ implode(', ', array_map($mesCorto, $emp->afil_atrasadas_periodos)) }} · {{ $pesos($emp->afil_atrasadas_valor) }}
                </span>
            @endif
            @if(!$debeMes($emp) && !($emp->afil_atrasadas ?? 0))<span class="chip ch-ok">✓ Al día</span>@endif
        </div>

        {{-- Plata --}}
        <div class="bf-plata">
            @if($emp->admon_pend > 0)<b>{{ $pesos($emp->admon_pend) }}</b><div style="font-size:.6rem;color:#94a3b8;">admon</div>@else<span style="color:#cbd5e1;">—</span>@endif
            @if(($emp->mora_estimada ?? 0) > 0)<div class="bf-mora">+ mora {{ $pesos($emp->mora_estimada) }}</div>@endif
        </div>

        {{-- Gestión --}}
        <div class="bf-ges" title="{{ $semTip }}">
            <span style="color:{{ $semColor }};">{{ $semIco }}</span>
            @if($emp->ultima_llamada)
                <b>{{ \App\Models\BitacoraCobro::RESULTADOS[$emp->ultima_llamada->resultado] ?? $emp->ultima_llamada->resultado }}</b>
                <span style="color:#94a3b8;">· hace {{ $emp->dias_sin_llamar }} d</span>
                @if($emp->ultima_llamada->observacion)<div class="obs" title="{{ $emp->ultima_llamada->observacion }}">{{ $emp->ultima_llamada->observacion }}</div>@endif
            @else
                <span style="color:#94a3b8;">Sin llamar</span>
            @endif
        </div>

        {{-- Encargado --}}
        @if($esAdmin)
        <div>
            <select class="enc-select" data-empresa-id="{{ $emp->id }}" onchange="asignarEncargado(this)" style="max-width:100%;">
                <option value="">— Sin asignar —</option>
                @foreach($usuariosDisponibles as $u)
                <option value="{{ $u->id }}" {{ $emp->encargado_id == $u->id ? 'selected' : '' }}>{{ $u->nombre ?? $u->name }}</option>
                @endforeach
            </select>
        </div>
        @endif

        {{-- Acciones --}}
        <div class="bf-acc">
            <button class="btn-llamar btn-llamar-emp"
                data-empresa-id="{{ $emp->id }}"
                data-nombre="{{ $emp->empresa ?? 'Empresa #'.$emp->id }}"
                data-contacto="{{ $emp->contacto ?? '—' }}"
                data-telefono="{{ $tel ?: '—' }}"
                data-cant="{{ $emp->cant }}"
                data-pend="{{ $emp->total_pend }}"
                title="Registrar llamada">📞</button>
            <a href="{{ $urlFact }}" target="_blank" title="Ver contratos en facturación">📋</a>
        </div>
    </div>
    @endforeach
    @endif
</details>
@endforeach
