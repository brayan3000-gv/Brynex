@extends('layouts.app')

@section('titulo', 'Nueva Cotización')
@section('modulo', 'Cotizaciones')

@section('contenido')
<div class="cz-page cz-page--form" x-data="cotizadorProspecto()">

    <div class="cz-header">
        <div class="cz-header-left">
            <div class="cz-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h2m2 0h2m-6 4h2m2 0h2M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"/>
                </svg>
            </div>
            <div>
                <h1 class="cz-title">Nueva cotización</h1>
                <p class="cz-subtitle">Registre el prospecto y calcule su plan</p>
            </div>
        </div>
        <div class="cz-header-actions">
            <a href="{{ route('admin.cotizaciones.index') }}" class="cz-btn-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Volver
            </a>
        </div>
    </div>

    @if($errors->any())
        <div class="cz-flash cz-flash--error">
            <div>
                <strong>Corrija los errores:</strong>
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        </div>
    @endif

    <form action="{{ route('admin.cotizaciones.store') }}" method="POST" @submit.prevent="guardar()">
        @csrf
        @include('admin.cotizaciones._form', [
            'textoGuardar' => 'Guardar cotización',
            'urlCancelar' => route('admin.cotizaciones.index'),
        ])
    </form>
</div>

@include('admin.cotizaciones._estilos')
@endsection

@push('scripts')
    @include('admin.cotizaciones._script')
@endpush
