@extends('layouts.app')

@section('titulo', 'Cotizaciones y Prospectos')
@section('modulo', 'Cotizaciones')

@section('contenido')
@php
    $iconoWa = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.948h.003c4.368 0 7.927-3.559 7.931-7.928a7.86 7.86 0 0 0-2.327-5.594ZM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592Zm3.69-4.98c-.202-.101-1.202-.594-1.392-.66-.189-.07-.327-.101-.466.101-.139.2-.538.66-.66.8-.12.138-.241.156-.443.055-.202-.101-.85-.313-1.62-.998-.6-.535-1.005-1.197-1.123-1.401-.118-.202-.012-.311.089-.412.091-.09.202-.236.302-.354.101-.118.135-.2.203-.332.067-.134.034-.251-.017-.352-.05-.101-.466-1.123-.638-1.54-.168-.403-.34-.348-.466-.354-.121-.006-.26-.008-.399-.008-.14 0-.368.052-.56.26-.192.208-.733.717-.733 1.748 0 1.03.75 2.023.854 2.163.104.14 1.478 2.256 3.58 3.162.5.216.89.345 1.196.443.502.16 1.037.137 1.429.078.437-.066 1.202-.492 1.371-.963.17-.472.17-.878.118-.963-.05-.084-.191-.133-.393-.234Z"/></svg>';

    // Las pestañas conservan la búsqueda y los demás filtros; solo cambian el estado.
    $filtrosBase = array_filter(request()->only(['buscar', 'canal', 'asesor_id']), fn ($v) => $v !== null && $v !== '');
    $urlTab = fn (array $extra = []) => route('admin.cotizaciones.index', $filtrosBase + $extra);
    $totalAliado = $conteos->sum();
@endphp

<div class="cz-page">

    {{-- ══ ENCABEZADO ══ --}}
    <div class="cz-header">
        <div class="cz-header-left">
            <div class="cz-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                </svg>
            </div>
            <div>
                <h1 class="cz-title">Prospectos y Cotizaciones</h1>
                <p class="cz-subtitle">
                    <span class="cz-count">{{ number_format($prospectos->total()) }}</span>
                    {{ $prospectos->total() === $totalAliado ? 'prospectos registrados' : 'de ' . number_format($totalAliado) . ' prospectos' }}
                </p>
            </div>
        </div>
        <div class="cz-header-actions">
            <a href="{{ route('admin.cotizaciones.create') }}" class="cz-btn-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Nueva Cotización
            </a>
        </div>
    </div>

    {{-- ══ PESTAÑAS POR ESTADO ══ --}}
    <nav class="cz-tabs" aria-label="Filtrar por estado">
        <a href="{{ $urlTab() }}" class="cz-tab {{ !$estado && !$porLlamar ? 'cz-tab--activo' : '' }}">
            Todos <b>{{ number_format($totalAliado) }}</b>
        </a>
        @if($conteoPorLlamar > 0 || $porLlamar)
            <a href="{{ $urlTab(['llamar' => 1]) }}" class="cz-tab cz-tab--alerta {{ $porLlamar ? 'cz-tab--activo' : '' }}">
                Por llamar <b>{{ number_format($conteoPorLlamar) }}</b>
            </a>
        @endif
        @foreach($estados as $key => $val)
            <a href="{{ $urlTab(['estado' => $key]) }}" class="cz-tab {{ $estado === $key && !$porLlamar ? 'cz-tab--activo' : '' }}">
                {{ $val }} <b>{{ number_format($conteos[$key] ?? 0) }}</b>
            </a>
        @endforeach
    </nav>

    {{-- ══ FILTROS ══ --}}
    <form method="GET" action="{{ route('admin.cotizaciones.index') }}" class="cz-filtros">
        @if($estado)<input type="hidden" name="estado" value="{{ $estado }}">@endif
        @if($porLlamar)<input type="hidden" name="llamar" value="1">@endif

        <div class="cz-buscar">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#64748b" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input type="search" name="buscar" class="cz-input" value="{{ $buscar }}" placeholder="Buscar por nombre, cédula o celular…" aria-label="Buscar">
        </div>

        <select name="canal" class="cz-input" aria-label="Canal" onchange="this.form.submit()">
            <option value="">Canal: todos</option>
            @foreach($canales as $key => $val)
                <option value="{{ $key }}" {{ $canal == $key ? 'selected' : '' }}>{{ $val }}</option>
            @endforeach
        </select>

        <select name="asesor_id" class="cz-input" aria-label="Asesor" onchange="this.form.submit()">
            <option value="">Asesor: todos</option>
            @foreach($asesores as $id => $nombre)
                <option value="{{ $id }}" {{ $asesorId == $id ? 'selected' : '' }}>{{ nombre_oracion($nombre) }}</option>
            @endforeach
        </select>

        <button type="submit" class="cz-btn cz-btn--primario">Buscar</button>
        <a href="{{ route('admin.cotizaciones.index') }}" class="cz-btn cz-btn--borde">Limpiar</a>
    </form>

    {{-- ══ LISTADO (tabla en escritorio, tarjetas en celular) ══ --}}
    <div class="cz-tabla-wrap">
        <table class="cz-tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Prospecto</th>
                    <th>Contacto</th>
                    <th class="cz-num">Cotización</th>
                    <th>Canal / Asesor</th>
                    <th>Estado</th>
                    <th>Próx. llamada</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($prospectos as $prospecto)
                @php
                    $nombre = nombre_oracion($prospecto->nombre_completo ?: 'Sin nombre');
                    $palabras = preg_split('/\s+/', $nombre, -1, PREG_SPLIT_NO_EMPTY);
                    $iniciales = mb_substr($palabras[0] ?? 'S', 0, 1) . mb_substr($palabras[count($palabras) > 2 ? 2 : 1] ?? '', 0, 1);
                    $celularLimpio = preg_replace('/\D/', '', (string) $prospecto->celular);
                    $abierto = !in_array($prospecto->estado, \App\Models\CotizacionProspecto::ESTADOS_CERRADOS);
                    $llamadaVencida = $prospecto->proxima_llamada && $abierto && $prospecto->proxima_llamada->lte(today());
                @endphp
                <tr>
                    <td class="cz-td-fecha" style="white-space:nowrap;">
                        <span class="cz-etiqueta">Cotizó</span>{{ $prospecto->fecha_cotizacion?->format('d/m/Y') }}
                    </td>
                    <td class="cz-td-persona">
                        <div class="cz-persona">
                            <div class="cz-avatar cz-avatar--{{ $prospecto->estado }}">{{ $iniciales }}</div>
                            <div style="min-width:0;">
                                <a href="{{ route('admin.cotizaciones.show', $prospecto->id) }}" class="cz-nombre">{{ $nombre }}</a>
                                @if($prospecto->cedula)
                                    <div class="cz-sub">{{ $prospecto->tipo_doc ?: 'CC' }} {{ $prospecto->cedula }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="cz-td-contacto">
                        @if($celularLimpio)
                            <span class="cz-contacto">
                                <a href="tel:{{ $celularLimpio }}" class="cz-tel">{{ $prospecto->celular }}</a>
                                <a href="https://wa.me/57{{ $celularLimpio }}" class="cz-wa-mini" target="_blank" rel="noopener" title="Escribir por WhatsApp" aria-label="Escribir por WhatsApp">{!! $iconoWa !!}</a>
                            </span>
                        @else
                            <span class="cz-vacio-dato">Sin celular</span>
                        @endif
                    </td>
                    <td class="cz-td-valor cz-num">
                        @if($prospecto->valor_mensual)
                            <div class="cz-valor">${{ number_format($prospecto->valor_mensual, 0, ',', '.') }}</div>
                        @endif
                        <div class="cz-sub">{{ $prospecto->plan->nombre ?? ($prospecto->valor_mensual ? '' : 'Sin cotizar') }}</div>
                    </td>
                    <td class="cz-td-origen">
                        @if($prospecto->canal_origen)
                            <span class="cz-chip">{{ $canales[$prospecto->canal_origen] ?? $prospecto->canal_origen }}</span>
                        @endif
                        <div class="cz-sub">{{ $prospecto->asesor ? nombre_oracion($prospecto->asesor->nombre) : 'Sin asesor' }}</div>
                    </td>
                    <td class="cz-td-estado">
                        <span class="cz-estado cz-estado--{{ $prospecto->estado }}">{{ $estados[$prospecto->estado] ?? $prospecto->estado }}</span>
                    </td>
                    <td class="cz-td-llamada">
                        @if($prospecto->proxima_llamada)
                            <span class="cz-etiqueta">Llamar</span><span class="cz-llamada {{ $llamadaVencida ? 'cz-llamada--vencida' : '' }}">{{ $prospecto->proxima_llamada->isToday() ? 'Hoy' : $prospecto->proxima_llamada->format('d/m/Y') }}{{ $llamadaVencida && !$prospecto->proxima_llamada->isToday() ? ' · vencida' : '' }}</span>
                        @else
                            <span class="cz-vacio-dato cz-solo-escritorio">—</span>
                        @endif
                    </td>
                    <td class="cz-td-abrir" style="text-align:right;">
                        <a href="{{ route('admin.cotizaciones.show', $prospecto->id) }}" class="cz-btn-abrir">Abrir</a>
                    </td>
                </tr>
                @empty
                <tr class="cz-fila-vacia">
                    <td colspan="8" class="cz-vacio">
                        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p>No se encontraron prospectos con los filtros actuales.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ══ PAGINACIÓN ══ --}}
    @if($prospectos->total() > 0)
    <div class="cz-paginacion">
        <div class="cz-pag-info">
            Mostrando <strong>{{ $prospectos->firstItem() }}</strong> a <strong>{{ $prospectos->lastItem() }}</strong> de <strong>{{ number_format($prospectos->total()) }}</strong>
        </div>
        @if($prospectos->hasPages())
        <div class="cz-pag-controles">
            @if($prospectos->onFirstPage())
                <span class="cz-pag-btn cz-pag-btn--off">« Anterior</span>
            @else
                <a href="{{ $prospectos->previousPageUrl() }}" class="cz-pag-btn">« Anterior</a>
            @endif

            @php
                $currentPage = $prospectos->currentPage();
                $lastPage = $prospectos->lastPage();
                $start = max(1, $currentPage - 2);
                $end = min($lastPage, $currentPage + 2);
            @endphp

            @if($start > 1)
                <a href="{{ $prospectos->url(1) }}" class="cz-pag-num">1</a>
                @if($start > 2)<span class="cz-vacio-dato">…</span>@endif
            @endif

            @for($p = $start; $p <= $end; $p++)
                @if($p == $currentPage)
                    <span class="cz-pag-num cz-pag-num--activo">{{ $p }}</span>
                @else
                    <a href="{{ $prospectos->url($p) }}" class="cz-pag-num">{{ $p }}</a>
                @endif
            @endfor

            @if($end < $lastPage)
                @if($end < $lastPage - 1)<span class="cz-vacio-dato">…</span>@endif
                <a href="{{ $prospectos->url($lastPage) }}" class="cz-pag-num">{{ $lastPage }}</a>
            @endif

            @if($prospectos->hasMorePages())
                <a href="{{ $prospectos->nextPageUrl() }}" class="cz-pag-btn">Siguiente »</a>
            @else
                <span class="cz-pag-btn cz-pag-btn--off">Siguiente »</span>
            @endif
        </div>
        @endif
    </div>
    @endif

</div>

@include('admin.cotizaciones._estilos')
@endsection
