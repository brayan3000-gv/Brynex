@extends('layouts.portal')
@section('titulo', 'Retirados')

@php
    $datos = $retirados->map(fn ($r) => array_merge($r, [
        'ingreso' => $r['ingreso']?->format('d/m/Y'),
        'retiro' => $r['retiro']?->format('d/m/Y'),
        'buscar' => mb_strtolower($r['nombre'].' '.$r['cedula']),
    ]))->values();
@endphp

@section('contenido')
<div x-data="{ q: '', filas: @js($datos), get visibles() { const q = this.q.trim().toLowerCase(); return this.filas.filter(f => !q || f.buscar.includes(q)); } }">
    <div class="pt-titulo aparece">
        <div>
            <h1>Retirados</h1>
            <p>Personas que trabajaron contigo y hoy no tienen afiliación activa. Se muestra su último retiro.</p>
        </div>
    </div>

    <div class="herr aparece" style="--i:1">
        <label class="buscar">
            @include('portal._icono', ['n' => 'buscar', 'clase' => 'ic-sm'])
            <input type="search" x-model="q" placeholder="Buscar por nombre o cédula" autocomplete="off">
        </label>
        <span class="chip chip-gris">{{ $retirados->count() }} {{ $retirados->count() === 1 ? 'persona' : 'personas' }}</span>
    </div>

    <div class="tarjeta aparece" style="--i:2;overflow:hidden">
        <template x-for="(r, i) in visibles.slice(0, 400)" :key="r.cedula">
            <div class="ret" :style="`--i:${Math.min(i, 12)}`">
                <div class="ret-av" x-text="r.nombre.charAt(0)"></div>
                <div style="min-width:0;flex:1">
                    <div class="nom" x-text="r.nombre"></div>
                    <div class="sub" x-text="(r.tipo_doc ? r.tipo_doc + ' ' : '') + r.cedula + (r.razon_social ? ' · ' + r.razon_social : '')"></div>
                </div>
                <div class="ret-fechas">
                    <div class="sub">Ingreso <b x-text="r.ingreso || '—'"></b></div>
                    <div class="sub">Retiro <b style="color:#b91c1c" x-text="r.retiro || '—'"></b></div>
                </div>
            </div>
        </template>
        <template x-if="visibles.length > 400">
            <div class="sub" style="padding:.8rem 1rem">Se muestran 400 de <span x-text="visibles.length"></span>. Usa la búsqueda para encontrar a alguien.</div>
        </template>
        <template x-if="!visibles.length">
            <div class="vacio">
                @include('portal._icono', ['n' => 'retirados'])
                <div x-text="q ? 'Nadie coincide con «' + q + '».' : 'No tienes trabajadores retirados.'"></div>
            </div>
        </template>
    </div>
</div>
@endsection

@push('estilos')
<style>
    .ret { display: flex; align-items: center; gap: .8rem; padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9;
        animation: pt-sube .35s cubic-bezier(.2,.7,.2,1) both; animation-delay: calc(var(--i, 0) * 25ms); transition: background .12s; }
    .ret:hover { background: #f8fbff; }
    .ret:last-child { border-bottom: 0; }
    .ret-av { width: 36px; height: 36px; border-radius: 50%; background: #fee2e2; color: #b91c1c; font-weight: 800;
        display: grid; place-items: center; flex-shrink: 0; text-transform: uppercase; }
    .nom { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sub { font-size: .76rem; color: var(--tenue); }
    .ret-fechas { text-align: right; flex-shrink: 0; }
</style>
@endpush
