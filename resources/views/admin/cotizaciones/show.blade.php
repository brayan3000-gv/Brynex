@extends('layouts.app')

@section('titulo', 'Detalle Prospecto')
@section('modulo', 'Cotizaciones')

@section('contenido')
@php
    $abierto = !in_array($prospecto->estado, \App\Models\CotizacionProspecto::ESTADOS_CERRADOS);
    $llamadaVencida = $prospecto->proxima_llamada && $abierto && $prospecto->proxima_llamada->lte(today());
    $iconosGestion = [
        'Llamada'  => 'M3 5a2 2 0 012-2h2.3a1 1 0 01.95.68l1.2 3.6a1 1 0 01-.5 1.2l-1.6.8a11 11 0 005.1 5.1l.8-1.6a1 1 0 011.2-.5l3.6 1.2a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.72 21 3 14.28 3 6V5z',
        'WhatsApp' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.42-4.03 8-9 8a9.9 9.9 0 01-4.26-.95L3 20l1.4-3.72A7.4 7.4 0 013 12c0-4.42 4.03-8 9-8s9 3.58 9 8z',
        'Correo'   => 'M3 8l7.9 5.26a2 2 0 002.2 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        'Reunión'  => 'M17 20h5v-2a3 3 0 00-5.36-1.86M17 20H7m10 0v-2c0-.66-.13-1.28-.36-1.86M7 20H2v-2a3 3 0 015.36-1.86M7 20v-2c0-.66.13-1.28.36-1.86m0 0a5 5 0 019.28 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    ];
    $iconoDefecto = 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z';
@endphp

<div class="cz-page cz-page--form">

    {{-- ══ ENCABEZADO ══ --}}
    <div class="cz-header">
        <div class="cz-header-left">
            <div class="cz-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
            </div>
            <div style="min-width:0;">
                <h1 class="cz-title">{{ $prospecto->esEmpresa() ? $prospecto->nombre_mostrar : nombre_oracion($prospecto->nombre_completo ?: 'Prospecto sin nombre') }}</h1>
                <p class="cz-subtitle">
                    <span class="cz-estado cz-estado--{{ $prospecto->estado }}">{{ $lookups['estados'][$prospecto->estado] ?? $prospecto->estado }}</span>
                    @if($prospecto->fecha_cotizacion)
                        <span>Cotizó el {{ $prospecto->fecha_cotizacion->format('d/m/Y') }}</span>
                    @endif
                    @if($prospecto->proxima_llamada && $abierto)
                        <span>· Llamar {{ $prospecto->proxima_llamada->isToday() ? 'hoy' : 'el ' . $prospecto->proxima_llamada->format('d/m/Y') }}{{ $llamadaVencida && !$prospecto->proxima_llamada->isToday() ? ' (vencida)' : '' }}</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="cz-header-actions">
            <a href="{{ route('admin.cotizaciones.index') }}" class="cz-btn-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Volver
            </a>
            @if($prospecto->valor_mensual)
                <a href="{{ route('admin.cotizaciones.pdf', $prospecto->id) }}" class="cz-btn-header" title="Descargar la cotización en PDF">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                    PDF
                </a>
                <button type="button" class="cz-btn-header" onclick="window.dispatchEvent(new CustomEvent('abrir-whatsapp'))" title="Enviar la cotización por WhatsApp">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.948h.003c4.368 0 7.927-3.559 7.931-7.928a7.86 7.86 0 0 0-2.327-5.594Z"/></svg>
                    WhatsApp
                </button>
            @endif
            @if($prospecto->esEmpresa())
                {{-- A una empresa no se la convierte en un cliente: cada trabajador se afilia con su contrato. --}}
            @elseif($prospecto->estado !== 'convertido')
                <form action="{{ route('admin.cotizaciones.convertir', $prospecto->id) }}" method="POST" onsubmit="return confirm('¿Convertir este prospecto a cliente real?');">
                    @csrf
                    <button type="submit" class="cz-btn-header cz-btn-header--solido">Convertir a cliente</button>
                </form>
            @elseif($prospecto->cliente_id)
                <a href="{{ route('admin.clientes.edit', $prospecto->cliente_id) }}" class="cz-btn-header cz-btn-header--solido">Ver cliente</a>
            @endif
        </div>
    </div>

    @if(session('info'))
        <div class="cz-flash cz-flash--info">{{ session('info') }}</div>
    @endif
    @if(session('error'))
        <div class="cz-flash cz-flash--error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="cz-flash cz-flash--error">
            <div>
                <strong>Corrija los errores:</strong>
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        </div>
    @endif

    {{-- ══ ENVIAR POR WHATSAPP ══ --}}
    @if($prospecto->valor_mensual)
    <div x-data="{ abierto: false, texto: @js($mensajeWhatsapp), celular: @js(preg_replace('/\D/', '', (string) $prospecto->celular)) }"
         @abrir-whatsapp.window="abierto = true" @keydown.escape.window="abierto = false">
        <div class="cz-modal-fondo" x-show="abierto" x-cloak @click.self="abierto = false">
            <div class="cz-modal" role="dialog" aria-modal="true" aria-labelledby="cz-wa-titulo">
                <div class="cz-modal-cab">
                    <h3 id="cz-wa-titulo">Enviar cotización por WhatsApp</h3>
                    <button type="button" class="cz-modal-cerrar" @click="abierto = false" aria-label="Cerrar">&times;</button>
                </div>
                <form action="{{ route('admin.cotizaciones.whatsapp', $prospecto->id) }}" method="POST">
                    @csrf
                    <div class="cz-modal-cuerpo">
                        <p class="cz-modal-nota">Al <strong>{{ $prospecto->celular ?: 'sin celular' }}</strong>. Puede ajustar el mensaje antes de enviarlo.</p>
                        <textarea name="texto" class="cz-input" rows="12" x-model="texto" required maxlength="3000"></textarea>
                        @if(!$whatsappApi['configurado'])
                            <p class="cz-modal-nota">El aliado no tiene la API de WhatsApp configurada: el mensaje se abre en su WhatsApp y el PDF se adjunta a mano (botón PDF).</p>
                        @elseif(!$whatsappApi['ventana'])
                            <p class="cz-modal-nota">Este número no tiene una conversación abierta en las últimas 24 h, así que la API no deja mandar el PDF. Ábralo en WhatsApp; cuando el prospecto responda, ya se podrá enviar con el PDF adjunto.</p>
                        @else
                            <p class="cz-modal-nota">Hay conversación abierta: se envía el PDF adjunto con este texto y queda registrado en el chat y en las gestiones.</p>
                        @endif
                    </div>
                    <div class="cz-modal-pie">
                        <a :href="'https://wa.me/57' + celular + '?text=' + encodeURIComponent(texto)" target="_blank" rel="noopener" class="cz-btn cz-btn--borde" x-show="celular.length >= 10">Abrir en WhatsApp</a>
                        @if($whatsappApi['configurado'] && $whatsappApi['ventana'])
                            <button type="submit" class="cz-btn cz-btn--primario">Enviar con el PDF</button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- ══ PROSPECTO + COTIZADOR ══ --}}
    <form action="{{ route('admin.cotizaciones.update', $prospecto->id) }}" method="POST" x-data="cotizadorProspecto()" @submit.prevent="guardar()">
        @csrf
        @method('PUT')
        @include('admin.cotizaciones._form', ['textoGuardar' => 'Guardar cambios'])
    </form>

    {{-- ══ GESTIONES (formulario propio, fuera del de arriba) ══ --}}
    <section class="cz-card" x-data="{ nueva: {{ $errors->hasAny(['tipo_gestion', 'descripcion', 'resultado', 'proxima_llamada']) ? 'true' : 'false' }}, verTodas: false }">
        <div class="cz-card-head">
            <h2 class="cz-card-title">
                Historial de gestiones
                <span class="cz-chip">{{ $prospecto->gestiones->count() }}</span>
            </h2>
            <button type="button" class="cz-btn cz-btn--borde cz-btn--chico" @click="nueva = !nueva" x-text="nueva ? 'Cerrar' : '+ Agregar gestión'">+ Agregar gestión</button>
        </div>

        <form action="{{ route('admin.cotizaciones.gestion', $prospecto->id) }}" method="POST" class="cz-gestion-form" x-show="nueva" x-cloak>
            @csrf
            <div class="cz-grid">
                <div class="cz-field cz-m-mitad">
                    <label class="cz-label" for="cz_g_tipo">Tipo de contacto</label>
                    <select id="cz_g_tipo" name="tipo_gestion" class="cz-input" required>
                        <option value="Llamada">Llamada</option>
                        <option value="WhatsApp">WhatsApp</option>
                        <option value="Correo">Correo</option>
                        <option value="Reunión">Reunión / visita</option>
                    </select>
                </div>
                <div class="cz-field cz-m-mitad">
                    <label class="cz-label" for="cz_g_resultado">Resultado</label>
                    <select id="cz_g_resultado" name="resultado" class="cz-input" required>
                        @foreach($lookups['estados'] as $key => $val)
                            <option value="{{ $key }}" {{ old('resultado', $prospecto->estado) == $key ? 'selected' : '' }}>{{ $val }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="cz-field">
                    <label class="cz-label" for="cz_g_prox">Próxima llamada (opcional)</label>
                    <input type="date" id="cz_g_prox" name="proxima_llamada" class="cz-input" value="{{ old('proxima_llamada') }}" min="{{ date('Y-m-d') }}">
                </div>
                <div class="cz-field cz-span-todo">
                    <label class="cz-label" for="cz_g_desc">Detalle de la conversación</label>
                    <textarea id="cz_g_desc" name="descripcion" class="cz-input" rows="3" required placeholder="¿Qué dijo el cliente?">{{ old('descripcion') }}</textarea>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:.9rem;">
                <button type="button" class="cz-btn cz-btn--texto" @click="nueva = false">Cancelar</button>
                <button type="submit" class="cz-btn cz-btn--primario">Guardar gestión</button>
            </div>
        </form>

        @if($prospecto->gestiones->isEmpty())
            <div style="text-align:center;padding:1.5rem 0;color:#94a3b8;font-size:.84rem;">
                Aún no hay gestiones registradas para este prospecto.
            </div>
        @else
            <div class="cz-gestiones">
                @foreach($prospecto->gestiones as $g)
                <div class="cz-gestion" @if($loop->index >= 3) x-show="verTodas" x-cloak @endif>
                    <div class="cz-gestion-icono" title="{{ $g->tipo_gestion }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconosGestion[$g->tipo_gestion] ?? $iconoDefecto }}"/>
                        </svg>
                    </div>
                    <div style="min-width:0;">
                        <div class="cz-gestion-cab">
                            <strong>{{ $g->tipo_gestion }}</strong>
                            <span style="color:#64748b;">· {{ nombre_oracion($g->usuario->nombre ?? 'Sistema') }}</span>
                            @if($g->resultado && isset($lookups['estados'][$g->resultado]))
                                <span class="cz-estado cz-estado--{{ $g->resultado }}" style="font-size:.66rem;padding:.12rem .5rem;">{{ $lookups['estados'][$g->resultado] }}</span>
                            @endif
                            <span class="cz-gestion-fecha">{{ $g->created_at->format('d/m/Y h:i A') }}</span>
                        </div>
                        <p class="cz-gestion-texto">{{ $g->descripcion }}</p>
                        @if($g->proxima_llamada)
                            <span class="cz-gestion-prox">Próxima llamada: {{ $g->proxima_llamada->format('d/m/Y') }}</span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            @if($prospecto->gestiones->count() > 3)
                <button type="button" class="cz-ver-mas" @click="verTodas = !verTodas"
                        x-text="verTodas ? 'Ver menos' : 'Ver las {{ $prospecto->gestiones->count() }} gestiones'">Ver todas</button>
            @endif
        @endif
    </section>
</div>

@include('admin.cotizaciones._estilos')
@endsection

@push('scripts')
    @include('admin.cotizaciones._script')
@endpush
