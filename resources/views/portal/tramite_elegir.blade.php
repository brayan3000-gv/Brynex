@extends('layouts.portal')
@section('titulo', 'Nuevo trámite')

@section('contenido')
<div class="pt-titulo aparece">
    <div>
        <a href="{{ route('portal.tramites') }}" class="volver">@include('portal._icono', ['n' => 'izq', 'clase' => 'ic-sm']) Trámites</a>
        <h1>¿Qué necesitas?</h1>
        <p>Lo revisamos y te avisamos aquí mismo en qué va.</p>
    </div>
</div>

<div class="elegir">
    @foreach([
        ['ingreso', 'usuarios', 'Ingresar un trabajador', 'Afiliamos a una persona nueva con el plan que escojas. Ten a mano su documento de identidad.'],
        ['retiro', 'retirados', 'Retirar un trabajador', 'Nos dices quién sale y desde qué fecha, y hacemos el retiro.'],
        ['incapacidad', 'incapacidades', 'Reportar una incapacidad', 'Sube el certificado y la cobramos ante la EPS o la ARL.'],
        ['otra', 'tramites', 'Otra solicitud', 'Traslados, beneficiarios, certificados, correcciones o lo que necesites.'],
    ] as $i => [$tipo, $icono, $titulo, $texto])
        <a href="{{ route('portal.tramites.nuevo', ['tipo' => $tipo]) }}" class="tarjeta opcion aparece" style="--i:{{ $i + 1 }}">
            <span class="opcion-ico">@include('portal._icono', ['n' => $icono])</span>
            <b>{{ $titulo }}</b>
            <span>{{ $texto }}</span>
            <span class="opcion-ir">Empezar @include('portal._icono', ['n' => 'der', 'clase' => 'ic-sm'])</span>
        </a>
    @endforeach
</div>
@endsection

@push('estilos')
<style>
    .volver { display: inline-flex; align-items: center; gap: .25rem; font-size: .8rem; font-weight: 700; color: var(--acento); text-decoration: none; margin-bottom: .4rem; }
    .elegir { display: grid; grid-template-columns: 1fr; gap: .8rem; }
    .opcion { display: flex; flex-direction: column; gap: .35rem; padding: 1.2rem; text-decoration: none; position: relative; overflow: hidden;
        transition: transform .2s, box-shadow .2s, border-color .2s; }
    .opcion::after { content: ''; position: absolute; right: -40px; top: -40px; width: 120px; height: 120px; border-radius: 50%;
        background: radial-gradient(circle, rgba(59,130,246,.12), transparent 70%); transition: transform .4s; }
    .opcion:hover { transform: translateY(-3px); border-color: #93c5fd; box-shadow: 0 10px 26px rgba(59,130,246,.16); }
    .opcion:hover::after { transform: scale(1.6); }
    .opcion b { font-size: 1.02rem; }
    .opcion > span:not(.opcion-ico):not(.opcion-ir) { color: var(--tenue); font-size: .85rem; }
    .opcion-ico { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; color: #fff; margin-bottom: .3rem;
        background: linear-gradient(135deg, var(--azul-oscuro), var(--azul-vivo)); box-shadow: 0 6px 16px rgba(30,64,175,.28); }
    .opcion-ir { display: inline-flex; align-items: center; gap: .2rem; margin-top: .4rem; font-size: .8rem; font-weight: 800; color: var(--azul-btn); }
    .opcion:hover .opcion-ir .ic-sm { transform: translateX(3px); }
    .opcion-ir .ic-sm { transition: transform .2s; }
    @media (min-width: 640px) { .elegir { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
@endpush
