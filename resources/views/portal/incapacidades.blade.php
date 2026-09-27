@extends('layouts.portal')
@section('titulo', 'Incapacidades')

@php
    $fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : null;

    // En qué paso del trámite va, para la línea de avance.
    $paso = fn ($estado) => match (true) {
        in_array($estado, ['recibido'], true) => 1,
        in_array($estado, ['transcripcion_ips', 'transcripcion'], true) => 1,
        in_array($estado, ['radicada', 'negada', 'derecho_peticion', 'derecho_peticion_radicado', 'tutela', 'tutela_radicada'], true) => 2,
        in_array($estado, ['en_liquidacion', 'liquidacion', 'pagada_razon_social'], true) => 3,
        in_array($estado, ['pagada_afiliado', 'pagado_afiliado', 'cierre_exitoso', 'pagada'], true) => 4,
        default => 0,
    };

    $datos = $filas->map(fn ($f) => array_merge($f, [
        'inicio' => $fecha($f['inicio']),
        'fin' => $fecha($f['fin']),
        'radicado' => $fecha($f['radicado']),
        'paso' => $paso($f['estado']),
        'alerta' => in_array($f['estado'], ['negada', 'rechazado', 'derecho_peticion', 'derecho_peticion_radicado', 'tutela', 'tutela_radicada'], true),
        'buscar' => mb_strtolower($f['nombre'].' '.$f['cedula'].' '.$f['entidad']),
    ]))->values();

    $enTramite = $filas->where('grupo', 'tramite');
@endphp

@section('contenido')
<div x-data="incapPortal(@js($datos))">
    <div class="pt-titulo aparece">
        <div>
            <h1>Incapacidades</h1>
            <p>En qué va el cobro de cada incapacidad de tus trabajadores ante la EPS o ARL.</p>
        </div>
    </div>

    <div class="kpis">
        <div class="tarjeta kpi aparece" style="--i:1">
            <div class="et">@include('portal._icono', ['n' => 'reloj', 'clase' => 'ic-sm']) En trámite</div>
            <div class="valor num" x-data="contador({{ $enTramite->count() }}, false)" x-text="texto"></div>
            <div class="nota">de {{ $filas->count() }} registradas</div>
        </div>
        <div class="tarjeta kpi destacado aparece" style="--i:2">
            <div class="et">@include('portal._icono', ['n' => 'dinero', 'clase' => 'ic-sm']) Por recibir</div>
            <div class="valor num" x-data="contador({{ (int) $enTramite->sum('por_recibir') }})" x-text="texto"></div>
            <div class="nota">de incapacidades en trámite</div>
        </div>
        <div class="tarjeta kpi aparece" style="--i:3">
            <div class="et">@include('portal._icono', ['n' => 'check', 'clase' => 'ic-sm']) Ya entregado</div>
            <div class="valor num" x-data="contador({{ (int) $filas->sum('entregado') }})" x-text="texto"></div>
            <div class="nota">en total</div>
        </div>
        <div class="tarjeta kpi aparece" style="--i:4">
            <div class="et">@include('portal._icono', ['n' => 'escudo', 'clase' => 'ic-sm']) Pagado por la entidad</div>
            <div class="valor num" x-data="contador({{ (int) $filas->sum('pago_entidad') }})" x-text="texto"></div>
            <div class="nota">EPS / ARL</div>
        </div>
    </div>

    <div class="herr aparece" style="--i:5">
        <label class="buscar">
            @include('portal._icono', ['n' => 'buscar', 'clase' => 'ic-sm'])
            <input type="search" x-model="q" placeholder="Buscar por trabajador, cédula o entidad" autocomplete="off">
        </label>
        <div class="filtros">
            <button type="button" class="filtro" :class="filtro === 'tramite' && 'activo'" @click="filtro = 'tramite'">En trámite<span class="n">{{ $enTramite->count() }}</span></button>
            <button type="button" class="filtro" :class="filtro === 'cerrada' && 'activo'" @click="filtro = 'cerrada'">Cerradas<span class="n">{{ $filas->where('grupo', 'cerrada')->count() }}</span></button>
            <button type="button" class="filtro" :class="filtro === 'todas' && 'activo'" @click="filtro = 'todas'">Todas<span class="n">{{ $filas->count() }}</span></button>
        </div>
    </div>

    <div class="incaps">
        <template x-for="(f, i) in visibles" :key="f.cedula + f.inicio + i">
            <article class="tarjeta incap aparece" :style="`--i:${Math.min(i, 10)}`">
                <div class="incap-cab">
                    <div style="min-width:0">
                        <div class="nom" x-text="f.nombre"></div>
                        <div class="sub" x-text="f.cedula + ' · ' + f.entidad"></div>
                    </div>
                    <span class="chip" :class="f.alerta ? 'chip-warn' : (f.grupo === 'cerrada' ? 'chip-ok' : 'chip-info')" x-text="f.estado_label"></span>
                </div>

                <div class="incap-meta">
                    <span x-text="f.tipo"></span>
                    <span x-text="f.dias + ' días'"></span>
                    <span x-text="f.inicio + (f.fin ? ' → ' + f.fin : '')"></span>
                    <template x-if="f.prorroga > 0"><span x-text="'Prórroga ' + f.prorroga"></span></template>
                </div>

                <ol class="pasos" :data-paso="f.paso">
                    <template x-for="(p, n) in ['Recibida', 'Radicada', 'Liquidación', 'Pagada']" :key="p">
                        <li :class="{ hecho: f.paso > n + 1 || (f.paso === 4 && n === 3), actual: f.paso === n + 1 && f.paso !== 4 }">
                            <i></i><span x-text="p"></span>
                        </li>
                    </template>
                </ol>

                <template x-if="f.esperado > 0">
                    <div class="plata">
                        <div class="barra"><i :style="`width:${Math.min(100, Math.round((f.esperado - f.por_recibir) * 100 / f.esperado))}%`"></i></div>
                        <div class="plata-lineas">
                            <div><small>Valor esperado</small><b class="num" x-text="pesos(f.esperado)"></b></div>
                            <div><small>Pagó la entidad</small><b class="num" x-text="pesos(f.pago_entidad)"></b></div>
                            <div><small>Ya entregado</small><b class="num" x-text="pesos(f.entregado)"></b></div>
                            <div><small>Por recibir</small><b class="num" :style="f.por_recibir > 0 && 'color:#b45309'" x-text="pesos(f.por_recibir)"></b></div>
                        </div>
                        <template x-if="f.anticipado > 0">
                            <div class="sub" style="margin-top:.4rem" x-text="'Lo entregado incluye un anticipo de ' + pesos(f.anticipado) + ' mientras paga la entidad.'"></div>
                        </template>
                    </div>
                </template>
            </article>
        </template>

        <template x-if="!visibles.length">
            <div class="tarjeta vacio">
                @include('portal._icono', ['n' => 'incapacidades'])
                <div x-text="q ? 'Nada coincide con «' + q + '».' : (filtro === 'tramite' ? 'No hay incapacidades en trámite.' : 'No hay incapacidades registradas.')"></div>
            </div>
        </template>
    </div>
</div>
@endsection

@push('estilos')
<style>
    .incaps { display: grid; gap: .8rem; grid-template-columns: 1fr; }
    .incap { padding: 1rem; transition: transform .2s, box-shadow .2s; }
    .incap:hover { transform: translateY(-2px); box-shadow: 0 4px 18px rgba(59,130,246,.12); }
    .incap-cab { display: flex; justify-content: space-between; gap: .8rem; align-items: flex-start; }
    .nom { font-weight: 800; }
    .sub { font-size: .76rem; color: var(--tenue); }
    .incap-meta { display: flex; flex-wrap: wrap; gap: .35rem .9rem; font-size: .8rem; color: var(--tinta-2); margin: .6rem 0 .2rem; }
    .pasos { list-style: none; padding: 0; margin: .9rem 0 .4rem; display: grid; grid-template-columns: repeat(4, 1fr); }
    .pasos li { position: relative; text-align: center; font-size: .68rem; color: #94a3b8; font-weight: 600; }
    .pasos li i { display: block; width: 14px; height: 14px; border-radius: 50%; background: #e2e8f0; margin: 0 auto .3rem;
        position: relative; z-index: 1; transition: background .3s, box-shadow .3s; }
    .pasos li::before { content: ''; position: absolute; top: 6px; left: -50%; width: 100%; height: 2px; background: #e2e8f0; }
    .pasos li:first-of-type::before { display: none; }
    .pasos li.hecho { color: #047857; }
    .pasos li.hecho i { background: var(--verde); }
    .pasos li.hecho::before, .pasos li.actual::before { background: var(--verde); }
    .pasos li.actual { color: var(--azul-btn); }
    .pasos li.actual i { background: var(--azul-btn); box-shadow: 0 0 0 5px rgba(37,99,235,.18); animation: latido 1.8s ease-in-out infinite; }
    @keyframes latido { 50% { box-shadow: 0 0 0 9px rgba(37,99,235,.05); } }
    .plata { margin-top: .6rem; padding-top: .7rem; border-top: 1px dashed var(--borde); }
    .plata-lineas { display: grid; grid-template-columns: repeat(2, 1fr); gap: .5rem; margin-top: .6rem; }
    .plata-lineas small { display: block; font-size: .68rem; color: var(--tenue); font-weight: 600; }
    .plata-lineas b { font-size: .92rem; }
    @media (min-width: 900px) {
        .incaps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .plata-lineas { grid-template-columns: repeat(4, 1fr); }
    }
</style>
@endpush

@push('scripts')
<script>
    function incapPortal(filas) {
        return {
            filas, q: '', filtro: filas.some(f => f.grupo === 'tramite') ? 'tramite' : 'todas',
            get visibles() {
                const q = this.q.trim().toLowerCase();
                return this.filas.filter(f => (this.filtro === 'todas' || f.grupo === this.filtro) && (!q || f.buscar.includes(q)));
            },
        };
    }
</script>
@endpush
