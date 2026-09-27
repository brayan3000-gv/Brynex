@extends('layouts.portal')
@section('titulo', 'Trámites')

@php
    $fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : null;
    $fechaHora = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y h:i a') : null;

    $datos = $filas->map(fn ($f) => array_merge($f, [
        'creada' => $fecha($f['creada']),
        'avances' => collect($f['avances'])->map(fn ($a) => ['fecha' => $fechaHora($a['fecha']), 'texto' => $a['texto']])->all(),
        'buscar' => mb_strtolower($f['tipo'].' '.$f['trabajador'].' '.$f['asunto']),
    ]))->values();

    $abiertas = $filas->where('abierta', true)->count();
@endphp

@section('contenido')
<div x-data="tramitesPortal(@js($datos))">
    <div class="pt-titulo aparece">
        <div>
            <h1>Trámites</h1>
            <p>Pide ingresos, retiros o reporta incapacidades. Nosotros los revisamos y te contamos aquí en qué van.</p>
        </div>
        <a href="{{ route('portal.tramites.nuevo') }}" class="btn btn-nuevo">
            @include('portal._icono', ['n' => 'mas', 'clase' => 'ic-sm', 'grosor' => 2.4]) Nuevo trámite
        </a>
    </div>

    {{-- Accesos rápidos --}}
    <div class="rapidos">
        @foreach([
            ['ingreso', 'usuarios', 'Ingresar trabajador', 'Afiliar a alguien nuevo'],
            ['retiro', 'retirados', 'Retirar trabajador', 'Reportar un retiro'],
            ['incapacidad', 'incapacidades', 'Reportar incapacidad', 'Subir el certificado'],
            ['otra', 'tramites', 'Otra solicitud', 'Certificados, traslados…'],
        ] as $i => [$tipo, $icono, $titulo, $sub])
            <a href="{{ route('portal.tramites.nuevo', ['tipo' => $tipo]) }}" class="tarjeta rapido aparece" style="--i:{{ $i + 1 }}">
                <span class="rapido-ico">@include('portal._icono', ['n' => $icono])</span>
                <span><b>{{ $titulo }}</b><small>{{ $sub }}</small></span>
            </a>
        @endforeach
    </div>

    <div class="herr aparece" style="--i:5">
        <label class="buscar">
            @include('portal._icono', ['n' => 'buscar', 'clase' => 'ic-sm'])
            <input type="search" x-model="q" placeholder="Buscar por trabajador o tipo" autocomplete="off">
        </label>
        <div class="filtros">
            <button type="button" class="filtro" :class="filtro === 'abiertas' && 'activo'" @click="filtro = 'abiertas'">En curso<span class="n">{{ $abiertas }}</span></button>
            <button type="button" class="filtro" :class="filtro === 'cerradas' && 'activo'" @click="filtro = 'cerradas'">Terminados<span class="n">{{ $filas->count() - $abiertas }}</span></button>
            <button type="button" class="filtro" :class="filtro === 'todas' && 'activo'" @click="filtro = 'todas'">Todos<span class="n">{{ $filas->count() }}</span></button>
        </div>
    </div>

    <div class="tramites">
        <template x-for="(t, i) in visibles" :key="t.id">
            <article class="tarjeta tramite aparece" :style="`--i:${Math.min(i, 10)}`" x-data="{ abierto: i < 3 && t.abierta }">
                <button type="button" class="tramite-cab" @click="abierto = !abierto" :aria-expanded="abierto">
                    <div style="min-width:0;flex:1">
                        <div class="tramite-tipo">
                            <span x-text="t.tipo"></span>
                            <template x-if="t.asunto"><span class="sub" x-text="'· ' + t.asunto"></span></template>
                        </div>
                        <div class="sub">
                            <span x-text="t.trabajador || 'General'"></span> · <span x-text="t.creada"></span>
                            <template x-if="!t.propia"><span> · abierto por nosotros</span></template>
                        </div>
                    </div>
                    <span class="chip" :class="t.estado[1]" x-text="t.estado[0]"></span>
                    <span class="flecha" :class="abierto && 'girada'">@include('portal._icono', ['n' => 'der', 'clase' => 'ic-sm'])</span>
                </button>

                <div x-show="abierto" x-cloak x-transition:enter="t-fade-enter" x-transition:enter-start="t-fade-from" class="tramite-cuerpo">
                    <template x-if="t.descripcion">
                        <p class="tramite-desc" x-text="t.descripcion"></p>
                    </template>

                    <template x-if="t.archivos.length">
                        <div class="adjuntos">
                            <template x-for="a in t.archivos" :key="a.url">
                                <a :href="a.url" target="_blank" rel="noopener" class="chip chip-gris adjunto">
                                    @include('portal._icono', ['n' => 'descarga', 'clase' => 'ic-sm']) <span x-text="a.nombre"></span>
                                </a>
                            </template>
                        </div>
                    </template>

                    <ol class="linea-tiempo">
                        <template x-for="(a, n) in t.avances" :key="n">
                            <li :class="n === t.avances.length - 1 && 'ultimo'">
                                <i></i>
                                <div>
                                    <div class="sub" x-text="a.fecha"></div>
                                    <div x-text="a.texto"></div>
                                </div>
                            </li>
                        </template>
                        <template x-if="!t.avances.length">
                            <li class="ultimo"><i></i><div class="sub">Todavía no hay avances para mostrar.</div></li>
                        </template>
                    </ol>
                </div>
            </article>
        </template>

        <template x-if="!visibles.length">
            <div class="tarjeta vacio">
                @include('portal._icono', ['n' => 'tramites'])
                <div x-text="q ? 'Nada coincide con «' + q + '».' : (filtro === 'abiertas' ? 'No tienes trámites en curso.' : 'Todavía no hay trámites.')"></div>
            </div>
        </template>
    </div>
</div>
@endsection

@push('estilos')
<style>
    .btn-nuevo { padding: .7rem 1.1rem; }
    .rapidos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem; margin-bottom: 1rem; }
    .rapido { display: flex; align-items: center; gap: .7rem; padding: .8rem .9rem; text-decoration: none;
        transition: transform .2s, box-shadow .2s, border-color .2s; }
    .rapido:hover { transform: translateY(-2px); border-color: #93c5fd; box-shadow: 0 6px 18px rgba(59,130,246,.14); }
    .rapido-ico { width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; flex-shrink: 0;
        background: linear-gradient(135deg, #dbeafe, #eff6ff); color: var(--azul-btn); transition: transform .25s; }
    .rapido:hover .rapido-ico { transform: rotate(-6deg) scale(1.06); }
    .rapido b { display: block; font-size: .85rem; }
    .rapido small { display: block; font-size: .72rem; color: var(--tenue); }
    .tramites { display: grid; gap: .6rem; }
    .tramite { overflow: hidden; }
    .tramite-cab { width: 100%; display: flex; align-items: center; gap: .7rem; background: none; border: 0; font: inherit;
        color: inherit; padding: .85rem 1rem; cursor: pointer; text-align: left; }
    .tramite-tipo { font-weight: 800; }
    .sub { font-size: .76rem; color: var(--tenue); }
    .flecha { color: var(--tenue); transition: transform .2s; display: flex; }
    .flecha.girada { transform: rotate(90deg); }
    .tramite-cuerpo { padding: 0 1rem 1rem; border-top: 1px solid #f1f5f9; }
    .tramite-desc { white-space: pre-wrap; font-size: .85rem; color: var(--tinta-2); background: #f8fafc; border-radius: 9px;
        padding: .6rem .75rem; margin: .8rem 0 .6rem; }
    .adjuntos { display: flex; flex-wrap: wrap; gap: .35rem; margin: .5rem 0; }
    .adjunto { text-decoration: none; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
    .linea-tiempo { list-style: none; margin: .9rem 0 0; padding: 0; }
    .linea-tiempo li { position: relative; display: flex; gap: .75rem; padding-bottom: .9rem; font-size: .86rem; }
    .linea-tiempo li i { width: 11px; height: 11px; border-radius: 50%; background: #cbd5e1; margin-top: .3rem; flex-shrink: 0; position: relative; z-index: 1; }
    .linea-tiempo li::before { content: ''; position: absolute; left: 5px; top: .6rem; bottom: 0; width: 1px; background: #e2e8f0; }
    .linea-tiempo li.ultimo::before { display: none; }
    .linea-tiempo li.ultimo i { background: var(--azul-btn); box-shadow: 0 0 0 4px rgba(37,99,235,.15); }
    @media (min-width: 900px) {
        .rapidos { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
</style>
@endpush

@push('scripts')
<script>
    function tramitesPortal(filas) {
        return {
            filas, q: '', filtro: filas.some(t => t.abierta) ? 'abiertas' : 'todas',
            get visibles() {
                const q = this.q.trim().toLowerCase();
                return this.filas.filter(t => {
                    if (this.filtro === 'abiertas' && !t.abierta) return false;
                    if (this.filtro === 'cerradas' && t.abierta) return false;
                    return !q || t.buscar.includes(q);
                });
            },
        };
    }
</script>
@endpush
