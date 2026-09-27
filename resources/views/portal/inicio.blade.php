@extends('layouts.portal')
@section('titulo', 'Mi mes')

@php
    use App\Http\Controllers\Portal\PortalEmpresaController as P;
    $fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : null;
    $actual = \Illuminate\Support\Carbon::create($anio, $mes, 1);
    $ant = $actual->copy()->subMonth();
    $sig = $actual->copy()->addMonth();
    $haySiguiente = $sig->lte(now()->startOfMonth()->addMonth());

    $estadoFactura = [
        'pagada' => ['Pagado', 'chip-ok'],
        'abono' => ['Abono parcial', 'chip-warn'],
        'prestamo' => ['A crédito', 'chip-lila'],
        'pre_factura' => ['Pre-factura', 'chip-info'],
    ];

    // Lo que necesita Alpine para filtrar, buscar y abrir el detalle.
    $datos = $filas->map(function ($f) use ($fecha, $estadoFactura) {
        $ef = $f['factura_estado'] ? ($estadoFactura[$f['factura_estado']] ?? [ucfirst($f['factura_estado']), 'chip-gris']) : null;

        return array_merge($f, [
            'ingreso' => $fecha($f['ingreso']),
            'retiro' => $fecha($f['retiro']),
            'factura_fecha' => $fecha($f['factura_fecha']),
            'factura_label' => $ef[0] ?? null,
            'factura_clase' => $ef[1] ?? null,
            'buscar' => mb_strtolower($f['nombre'].' '.$f['cedula'].' '.$f['plan'].' '.$f['cargo']),
            'planillas' => collect($f['planillas'])->map(fn ($p) => array_merge($p, [
                'fecha' => $fecha($p['fecha']),
                'url' => route('portal.planilla', $p['id']),
            ]))->all(),
        ]);
    })->values();

    $conteos = [
        'todos' => $filas->count(),
        'por_facturar' => $filas->where('grupo', 'por_facturar')->count(),
        'facturado' => $filas->where('grupo', 'facturado')->count(),
        'retirados' => $filas->where('retirado', true)->count(),
    ];
    $pctPila = $resumen['pila_total'] ? round($resumen['pila_pagadas'] * 100 / $resumen['pila_total']) : 0;
@endphp

@section('contenido')
<div x-data="mesPortal(@js($datos), @js((bool) $discriminado))">

    {{-- Encabezado con selector de mes --}}
    <div class="pt-titulo aparece">
        <div>
            <h1>{{ P::MESES[$mes] }} {{ $anio }}</h1>
            <p>Tus trabajadores, lo facturado, lo que falta y la planilla de seguridad social del mes.</p>
        </div>
        <div class="selector-mes">
            <a class="btn btn-sec btn-ico" href="{{ route('portal.inicio', ['mes' => $ant->month, 'anio' => $ant->year]) }}" aria-label="Mes anterior">
                @include('portal._icono', ['n' => 'izq', 'clase' => 'ic-sm'])
            </a>
            <form method="GET" action="{{ route('portal.inicio') }}" class="selector-form">
                <select name="mes" onchange="this.form.submit()" aria-label="Mes">
                    @foreach(P::MESES as $n => $nombreMes)
                        <option value="{{ $n }}" @selected($n === $mes)>{{ $nombreMes }}</option>
                    @endforeach
                </select>
                <select name="anio" onchange="this.form.submit()" aria-label="Año">
                    @foreach(range(now()->year + 1, 2024) as $a)
                        <option value="{{ $a }}" @selected($a === $anio)>{{ $a }}</option>
                    @endforeach
                </select>
            </form>
            @if($haySiguiente)
                <a class="btn btn-sec btn-ico" href="{{ route('portal.inicio', ['mes' => $sig->month, 'anio' => $sig->year]) }}" aria-label="Mes siguiente">
                    @include('portal._icono', ['n' => 'der', 'clase' => 'ic-sm'])
                </a>
            @endif
        </div>
    </div>

    {{-- Saldo que viene de meses anteriores --}}
    @if($resumen['saldo_pendiente'] > 0)
        <div class="aviso aviso-warn aparece" style="--i:1">
            @include('portal._icono', ['n' => 'info', 'clase' => 'ic-sm'])
            <div>Tienes un <b>saldo pendiente de ${{ number_format($resumen['saldo_pendiente'], 0, ',', '.') }}</b> de facturas anteriores.
                <a href="{{ route('portal.facturas') }}" style="font-weight:700">Ver facturas</a></div>
        </div>
    @elseif($resumen['saldo_favor'] > 0)
        <div class="aviso aviso-ok aparece" style="--i:1">
            @include('portal._icono', ['n' => 'check', 'clase' => 'ic-sm'])
            <div>Tienes <b>${{ number_format($resumen['saldo_favor'], 0, ',', '.') }} a favor</b> que se descuentan de tus próximas facturas.</div>
        </div>
    @endif

    {{-- Indicadores --}}
    <div class="kpis">
        <div class="tarjeta kpi destacado aparece" style="--i:1">
            <div class="et">@include('portal._icono', ['n' => 'dinero', 'clase' => 'ic-sm']) Por facturar</div>
            <div class="valor num" x-data="contador({{ (int) $resumen['por_facturar_valor'] }})" x-text="texto"></div>
            <div class="nota">{{ $resumen['por_facturar_n'] }} por facturar · valor estimado</div>
        </div>
        <div class="tarjeta kpi aparece" style="--i:2">
            <div class="et">@include('portal._icono', ['n' => 'facturas', 'clase' => 'ic-sm']) Facturado</div>
            <div class="valor num" x-data="contador({{ (int) $resumen['facturado_valor'] }})" x-text="texto"></div>
            <div class="nota">{{ $resumen['facturado_n'] }} facturados · {{ $resumen['pagado_n'] }} pagados</div>
        </div>
        <div class="tarjeta kpi aparece" style="--i:3">
            <div class="et">@include('portal._icono', ['n' => 'usuarios', 'clase' => 'ic-sm']) Trabajadores</div>
            <div class="valor num" x-data="contador({{ (int) $resumen['trabajadores'] }}, false)" x-text="texto"></div>
            <div class="nota">activos en el mes{{ $conteos['retirados'] ? ' · '.$conteos['retirados'].' con retiro' : '' }}</div>
        </div>
        <div class="tarjeta kpi aparece" style="--i:4">
            <div class="et">@include('portal._icono', ['n' => 'escudo', 'clase' => 'ic-sm']) Planilla PILA pagada</div>
            <div class="valor num">{{ $resumen['pila_pagadas'] }}<span style="font-size:.95rem;color:var(--tenue);font-weight:600"> / {{ $resumen['pila_total'] }}</span></div>
            <div class="barra"><i x-data x-init="setTimeout(() => $el.style.width = '{{ $pctPila }}%', 80)"></i></div>
            <div class="nota">trabajadores con planilla pagada</div>
        </div>
    </div>

    {{-- Búsqueda y filtros --}}
    <div class="herr aparece" style="--i:5">
        <label class="buscar">
            @include('portal._icono', ['n' => 'buscar', 'clase' => 'ic-sm'])
            <input type="search" x-model="q" placeholder="Buscar por nombre, cédula, plan o cargo" autocomplete="off">
        </label>
        <div class="filtros">
            @foreach(['todos' => 'Todos', 'por_facturar' => 'Por facturar', 'facturado' => 'Facturados', 'retirados' => 'Retiros'] as $k => $t)
                <button type="button" class="filtro" :class="filtro === '{{ $k }}' && 'activo'" @click="filtro = '{{ $k }}'">
                    {{ $t }}<span class="n">{{ $conteos[$k] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Lista (PC: tabla · celular: tarjetas) --}}
    <div class="tarjeta aparece" style="--i:6;overflow:hidden">
        <div class="tabla-envoltura">
            <table class="tabla {{ $discriminado ? 'densa' : '' }}">
                <thead>
                    <tr>
                        <th>Trabajador</th>
                        <th>Plan</th>
                        <th>Ingreso</th>
                        <th class="der">Días</th>
                        @if($discriminado)
                            <th class="der">EPS</th><th class="der">ARL</th><th class="der">Pensión</th><th class="der">Caja</th>
                            @if($hayParaf)<th class="der" title="SENA 2 % + ICBF 3 %: aportes del empleador no exonerado">SENA/ICBF</th>@endif
                            <th class="der">Admón.</th>
                            @if($hayIva)<th class="der">IVA</th>@endif
                            @if($hayOtros)<th class="der">Otros</th>@endif
                        @endif
                        <th class="der">Total</th>
                        <th>Factura</th>
                        <th>PILA</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(f, i) in visibles" :key="f.contrato_id">
                        <tr @click="abrir(f)" class="fila" :style="`--i:${Math.min(i, 12)}`">
                            <td>
                                <div class="nom" x-text="f.nombre" :title="f.nombre"></div>
                                <div class="sub"><span x-text="(f.tipo_doc ? f.tipo_doc + ' ' : '') + f.cedula"></span>
                                    <template x-if="f.retirado || f.estado !== 'Activo'">
                                        <span class="chip" :class="f.retirado ? 'chip-err' : 'chip-gris'" style="margin-left:.3rem" x-text="f.estado"></span>
                                    </template>
                                </div>
                            </td>
                            <td><div class="plan" x-text="f.plan || '—'"></div></td>
                            <td class="num" x-text="f.retiro ? 'Ret. ' + f.retiro : (f.ingreso || '—')"></td>
                            <td class="der num" x-text="f.dias || '—'"></td>
                            @if($discriminado)
                                <td class="der num" x-text="m(f.eps_v)"></td>
                                <td class="der num" x-text="m(f.arl_v)"></td>
                                <td class="der num" x-text="m(f.afp_v)"></td>
                                <td class="der num" x-text="m(f.caja_v)"></td>
                                @if($hayParaf)<td class="der num" x-text="m(f.parafiscales)"></td>@endif
                                <td class="der num" x-text="m(f.admon)"></td>
                                @if($hayIva)<td class="der num" x-text="m(f.iva)"></td>@endif
                                @if($hayOtros)<td class="der num" x-text="m(f.otros)"></td>@endif
                            @endif
                            <td class="der num">
                                <b x-text="pesos(f.total)"></b>
                                <template x-if="f.mora > 0"><div class="mora" x-text="(f.mora_incluida ? 'incluye ' : '+ ') + pesos(f.mora) + ' de mora'"></div></template>
                            </td>
                            <td>
                                <template x-if="f.factura_label"><span class="chip" :class="f.factura_clase" x-text="f.factura_label"></span></template>
                                <template x-if="!f.factura_label && f.grupo === 'por_facturar'"><span class="chip chip-warn">Por facturar</span></template>
                                <template x-if="!f.factura_label && f.grupo === 'sin_cobro'"><span class="chip chip-gris">Sin cobro</span></template>
                            </td>
                            <td @click.stop>
                                <template x-if="f.planillas.length">
                                    <a class="chip chip-ok pila" :href="f.planillas[0].url" target="_blank" rel="noopener" title="Descargar planilla">
                                        @include('portal._icono', ['n' => 'descarga', 'clase' => 'ic-sm', 'grosor' => 2.2]) <span x-text="f.planillas[0].numero"></span>
                                    </a>
                                </template>
                                <template x-if="!f.planillas.length && f.cotiza"><span class="chip chip-gris">Pendiente</span></template>
                                <template x-if="!f.planillas.length && !f.cotiza"><span style="color:#cbd5e1">—</span></template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Celular --}}
        <div class="tarjetas">
            <template x-for="(f, i) in visibles" :key="'t' + f.contrato_id">
                <button type="button" class="trab aparece" :style="`--i:${Math.min(i, 10)}`" @click="abrir(f)">
                    <div class="trab-top">
                        <div style="min-width:0">
                            <div class="nom" x-text="f.nombre"></div>
                            <div class="sub" x-text="(f.plan || 'Sin plan') + ' · ' + f.cedula"></div>
                        </div>
                        <div class="der">
                            <div class="num" style="font-weight:800" x-text="pesos(f.total)"></div>
                            <template x-if="f.mora > 0"><div class="mora" x-text="(f.mora_incluida ? 'incluye ' : '+ ') + pesos(f.mora) + ' de mora'"></div></template>
                        </div>
                    </div>
                    <div class="trab-chips">
                        <template x-if="f.retirado || f.estado !== 'Activo'"><span class="chip" :class="f.retirado ? 'chip-err' : 'chip-gris'" x-text="f.estado"></span></template>
                        <template x-if="f.factura_label"><span class="chip" :class="f.factura_clase" x-text="f.factura_label"></span></template>
                        <template x-if="!f.factura_label && f.grupo === 'por_facturar'"><span class="chip chip-warn">Por facturar</span></template>
                        <template x-if="f.planillas.length"><span class="chip chip-ok">@include('portal._icono', ['n' => 'check', 'clase' => 'ic-sm', 'grosor' => 2.4]) PILA pagada</span></template>
                        <template x-if="!f.planillas.length && f.cotiza"><span class="chip chip-gris">PILA pendiente</span></template>
                    </div>
                </button>
            </template>
        </div>

        <template x-if="!visibles.length">
            <div class="vacio">
                @include('portal._icono', ['n' => 'buscar'])
                <div x-text="q ? 'Nadie coincide con «' + q + '».' : 'No hay trabajadores en este grupo.'"></div>
            </div>
        </template>
    </div>

    {{-- Detalle del trabajador --}}
    <template x-teleport="body">
        <div x-show="sel" x-cloak @keydown.escape.window="sel = null">
            <div class="velo" x-show="sel" x-transition:enter="t-fade-enter" x-transition:enter-start="t-fade-from"
                 x-transition:leave="t-fade-enter" x-transition:leave-end="t-fade-from" @click="sel = null"></div>
            <aside class="panel" x-show="sel" role="dialog" aria-modal="true"
                   x-transition:enter="t-sube-enter" x-transition:enter-start="t-sube-from"
                   x-transition:leave="t-sube-enter" x-transition:leave-end="t-sube-from">
                <template x-if="sel">
                    <div>
                        <div class="asa"></div>
                        <div class="panel-cab">
                            <div style="min-width:0">
                                <h2 x-text="sel.nombre"></h2>
                                <div class="sub" x-text="(sel.tipo_doc ? sel.tipo_doc + ' ' : '') + sel.cedula"></div>
                            </div>
                            <button type="button" class="btn btn-sec btn-ico" @click="sel = null" aria-label="Cerrar">
                                @include('portal._icono', ['n' => 'cerrar', 'clase' => 'ic-sm'])
                            </button>
                        </div>

                        <template x-if="!sel.retirado">
                            <div class="panel-acciones">
                                <a class="btn btn-sec" :href="`{{ route('portal.tramites.nuevo') }}?tipo=incapacidad&contrato=${sel.contrato_id}`">
                                    @include('portal._icono', ['n' => 'incapacidades', 'clase' => 'ic-sm']) Reportar incapacidad
                                </a>
                                <a class="btn btn-sec" :href="`{{ route('portal.tramites.nuevo') }}?tipo=retiro&contrato=${sel.contrato_id}`">
                                    @include('portal._icono', ['n' => 'retirados', 'clase' => 'ic-sm']) Solicitar retiro
                                </a>
                            </div>
                        </template>

                        <div class="panel-sec">
                            <h3>Afiliación</h3>
                            <div class="datos">
                                <div class="dato" style="grid-column:1/-1"><small>Plan</small><div x-text="sel.plan || '—'"></div></div>
                                <div class="dato"><small>Estado</small><div x-text="sel.estado"></div></div>
                                <div class="dato"><small>Ingreso</small><div x-text="sel.ingreso || '—'"></div></div>
                                <template x-if="sel.retiro"><div class="dato"><small>Retiro</small><div x-text="sel.retiro"></div></div></template>
                                <div class="dato"><small>Cargo</small><div x-text="sel.cargo || '—'"></div></div>
                                <div class="dato"><small>EPS</small><div x-text="sel.eps || '—'"></div></div>
                                <div class="dato"><small>Pensión</small><div x-text="sel.afp || '—'"></div></div>
                                <div class="dato"><small>ARL</small><div x-text="sel.arl ? sel.arl + (sel.nivel_arl ? ' · riesgo ' + sel.nivel_arl : '') : '—'"></div></div>
                                <div class="dato"><small>Caja</small><div x-text="sel.caja || '—'"></div></div>
                                <div class="dato"><small>Empleador en PILA</small><div x-text="sel.razon_social || '—'"></div></div>
                            </div>
                        </div>

                        <div class="panel-sec">
                            <h3 x-text="'Valor del mes' + (sel.dias ? ' · ' + sel.dias + ' días' : '')"></h3>
                            <template x-if="disc">
                                <div>
                                    <template x-for="[et, v] in [['EPS', sel.eps_v], ['ARL', sel.arl_v], ['Pensión', sel.afp_v], ['Caja', sel.caja_v], ['SENA e ICBF', sel.parafiscales], ['Administración', sel.admon], ['IVA', sel.iva], ['Afiliación y otros', sel.otros]]" :key="et">
                                        <template x-if="v > 0"><div class="linea"><span x-text="et"></span><span class="num" x-text="pesos(v)"></span></div></template>
                                    </template>
                                </div>
                            </template>
                            <div class="linea total"><span>Total</span><span class="num" x-text="pesos(sel.total)"></span></div>
                            <template x-if="sel.mora > 0">
                                <div class="linea" style="color:#92400e">
                                    <span x-text="sel.mora_incluida ? 'Intereses de mora (incluidos en el total)' : 'Intereses de mora (se suman al total)'"></span>
                                    <span class="num" x-text="pesos(sel.mora)"></span>
                                </div>
                            </template>
                            <template x-if="sel.grupo === 'por_facturar'">
                                <p class="sub" style="margin:.4rem 0 0">Valor estimado: puede cambiar al facturar si hay novedades en el mes.</p>
                            </template>
                        </div>

                        <div class="panel-sec">
                            <h3>Factura</h3>
                            <template x-if="sel.factura_label">
                                <div class="linea" style="border:0">
                                    <span>N.º <b x-text="sel.factura_numero"></b><span class="sub" x-show="sel.factura_fecha" x-text="' · ' + sel.factura_fecha"></span></span>
                                    <span class="chip" :class="sel.factura_clase" x-text="sel.factura_label"></span>
                                </div>
                            </template>
                            <template x-if="!sel.factura_label">
                                <div class="sub" x-text="sel.grupo === 'por_facturar' ? 'Todavía no se ha facturado este mes.' : 'Este mes no tiene cobro.'"></div>
                            </template>
                        </div>

                        <div class="panel-sec">
                            <h3>Planilla de seguridad social</h3>
                            <template x-for="p in sel.planillas" :key="p.id">
                                <div class="planilla">
                                    <div>
                                        <div style="font-weight:700">N.º <span x-text="p.numero"></span></div>
                                        <div class="sub" x-text="[p.operador, p.fecha ? 'pagada el ' + p.fecha : null].filter(Boolean).join(' · ')"></div>
                                    </div>
                                    <a class="btn btn-verde" :href="p.url" target="_blank" rel="noopener">
                                        @include('portal._icono', ['n' => 'descarga', 'clase' => 'ic-sm']) PDF
                                    </a>
                                </div>
                            </template>
                            <template x-if="!sel.planillas.length">
                                <div class="sub" x-text="sel.cotiza ? 'La planilla de este mes todavía no está pagada.' : 'Este mes no lleva planilla.'"></div>
                            </template>
                        </div>
                    </div>
                </template>
            </aside>
        </div>
    </template>
</div>
@endsection

@push('estilos')
<style>
    .panel-acciones { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-bottom: 1rem; }
    .panel-acciones .btn { font-size: .8rem; padding: .55rem .6rem; }
    .selector-mes { display: flex; align-items: center; gap: .4rem; }
    .selector-form { display: flex; gap: .4rem; }
    .selector-form select { font: inherit; font-size: .88rem; font-weight: 600; border: 1.5px solid var(--borde); border-radius: 9px;
        padding: .5rem .6rem; background: #fff; color: var(--tinta); cursor: pointer; }
    .selector-form select:focus { outline: 0; border-color: var(--acento); }

    .tabla-envoltura { display: none; overflow-x: auto; }
    .tabla { width: 100%; border-collapse: collapse; font-size: .84rem; }
    .tabla th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; color: var(--tenue);
        font-weight: 700; padding: .75rem .8rem; background: #f8fafc; border-bottom: 1px solid var(--borde); white-space: nowrap; }
    .tabla td { padding: .7rem .8rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .tabla .der { text-align: right; }
    /* Discriminado: once columnas; se aprieta para que quepan sin desplazar. */
    .tabla.densa { font-size: .78rem; }
    .tabla.densa th { padding: .7rem .45rem; font-size: .62rem; }
    .tabla.densa td { padding: .6rem .45rem; }
    .tabla.densa .nom { max-width: 190px; }
    .tabla.densa .plan { max-width: 120px; }
    .tabla th:first-child, .tabla td:first-child { padding-left: 1rem; }
    .tabla tr.fila { cursor: pointer; transition: background .12s; animation: pt-sube .4s cubic-bezier(.2,.7,.2,1) both;
        animation-delay: calc(var(--i, 0) * 25ms); }
    .tabla tr.fila:hover { background: #f8fbff; }
    .nom { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }
    .sub { font-size: .76rem; color: var(--tenue); }
    .plan { max-width: 190px; font-size: .8rem; color: var(--tinta-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mora { font-size: .7rem; color: #b45309; font-weight: 700; }
    a.pila { text-decoration: none; transition: transform .12s, box-shadow .12s; }
    a.pila:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(16,185,129,.25); }

    .tarjetas { display: flex; flex-direction: column; }
    .trab { display: block; width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid #f1f5f9;
        padding: .85rem 1rem; font: inherit; color: inherit; cursor: pointer; animation-duration: .35s; animation-delay: calc(var(--i, 0) * 30ms); }
    .trab:active { background: #f8fbff; }
    .trab-top { display: flex; justify-content: space-between; gap: .8rem; align-items: flex-start; }
    .trab-top .nom { max-width: none; white-space: normal; }
    .trab-top .der { text-align: right; flex-shrink: 0; }
    .trab-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .5rem; }

    @media (max-width: 899px) {
        .pt-titulo { align-items: stretch; }
        .selector-mes { width: 100%; }
        .selector-form { flex: 1; }
        .selector-form select { flex: 1; }
    }
    @media (min-width: 900px) {
        .tabla-envoltura { display: block; }
        .tarjetas { display: none; }
    }
</style>
@endpush

@push('scripts')
<script>
    function mesPortal(filas, disc) {
        return {
            filas, disc, q: '', filtro: 'todos', sel: null,
            get visibles() {
                const q = this.q.trim().toLowerCase();
                return this.filas.filter(f => {
                    if (this.filtro === 'por_facturar' && f.grupo !== 'por_facturar') return false;
                    if (this.filtro === 'facturado' && f.grupo !== 'facturado') return false;
                    if (this.filtro === 'retirados' && !f.retirado) return false;
                    return !q || f.buscar.includes(q);
                });
            },
            m(v) { return v > 0 ? pesos(v) : '—'; },
            abrir(f) { this.sel = f; },
        };
    }
</script>
@endpush
