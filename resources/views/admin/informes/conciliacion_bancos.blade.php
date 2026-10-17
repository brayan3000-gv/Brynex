@extends('layouts.app')
@section('modulo', 'Conciliación de Bancos')

@php
$fmt = fn($v) => '$'.number_format(abs($v ?? 0), 0, ',', '.');
$meses = collect(range(0,11))->map(fn($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'));
// Filtrar bancos SIN movimientos en el mes
$saldosConMov = $saldos->filter(fn($sb) => $sb['movimientos']->isNotEmpty());
@endphp

@section('contenido')
<style>
/* ── Header de la página — se queda arriba para tener los filtros a mano ──
   Con los bancos plegados la página es corta, pero al abrir uno los filtros se
   iban del viewport y había que subir a buscarlos. El resto de los sticky
   (header de cada banco y thead) cuelgan de su altura, que el JS mide y publica
   en --bk-top: el header envuelve en dos líneas en pantallas angostas, así que
   la altura no se puede quemar. */
.bk-page-header {
    position: sticky;
    top: 0;
    z-index: 95;
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
    border-radius: 14px;
    color: #fff;
    padding: .85rem 1.4rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .6rem;
    box-shadow: 0 4px 20px rgba(15,23,42,.2);
}
.bk-page-header__left { display: flex; flex-direction: column; gap: .12rem; }
.bk-page-header__right { display: flex; align-items: center; gap: .8rem; flex-wrap: wrap; }

/* Filtros globales */
.filtros-globales { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
.btn-filtro-g {
    padding: .22rem .65rem;
    font-size: .71rem;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,.25);
    background: rgba(255,255,255,.08);
    color: rgba(255,255,255,.75);
    cursor: pointer;
    font-weight: 600;
    transition: all .15s;
    white-space: nowrap;
}
.btn-filtro-g:hover { background: rgba(255,255,255,.18); color: #fff; }
.btn-filtro-g.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }

/* ── Card de banco ──
   CRÍTICO: sin overflow aquí — el overflow rompe position:sticky de los hijos.
   El scroll horizontal se maneja en el wrapper exterior de la página. */
.banco-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    margin-bottom: 1rem;
}

/* Header sticky del banco — se pega al top del viewport al hacer scroll */
.banco-card-sticky {
    position: sticky;
    top: var(--bk-top, 0px);
    z-index: 90;
    background: #fff;
    border-bottom: 2px solid #e2e8f0;
    padding: .55rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .4rem;
    border-radius: 12px 12px 0 0;
    transition: box-shadow .2s;
}
.banco-card-sticky.is-stuck {
    box-shadow: 0 4px 16px rgba(15,23,42,.13);
    border-radius: 0;
}
.banco-nombre {
    font-size: .88rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.banco-cuenta {
    font-size: .68rem;
    color: #94a3b8;
    font-weight: 500;
    margin-top: .1rem;
}
.banco-saldo {
    font-size: 1.15rem;
    font-weight: 800;
    font-family: monospace;
}

/* ── Tabla ── */
table.tbl { width: 100%; border-collapse: collapse; font-size: .78rem; min-width: 780px; }
.tbl thead th {
    background: #1e293b;
    color: #94a3b8;
    font-size: .6rem;
    text-transform: uppercase;
    padding: .38rem .55rem;
    white-space: nowrap;
    text-align: center;
    letter-spacing: .04em;
    position: sticky;
    /* top se calcula dinámicamente por JS según la altura real del banco-card-sticky */
    top: 0;
    z-index: 80;
}
.tbl td { padding: .38rem .55rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; text-align: center; }
.tbl tr.mov-row:hover td { background: #f8fafc; }
.tbl tr.fecha-separador-row td {
    background: linear-gradient(90deg, #f0f9ff, #f8fafc);
    padding: .3rem 1rem;
    font-size: .67rem;
    font-weight: 800;
    color: #0369a1;
    text-align: left;
    border-top: 1px solid #bae6fd;
    border-bottom: 1px solid #bae6fd;
    letter-spacing: .03em;
}

/* ── Badges y botones ── */
.badge { padding: .1rem .4rem; border-radius: 12px; font-size: .65rem; font-weight: 700; white-space: nowrap; display: inline-block; }
.btn-sm { padding: .18rem .5rem; font-size: .71rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; }
.btn-estado-clic { white-space: nowrap; }

/* ── Modales ── */
.modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 9999; align-items: center; justify-content: center; }
.modal-bg.open { display: flex; }
.modal-box { background: #fff; border-radius: 14px; width: min(580px,96vw); max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
.modal-box.lg { width: min(900px,98vw); max-height: 94vh; }
.modal-head { background: #1e3a5f; padding: .75rem 1rem; display: flex; justify-content: space-between; align-items: center; }
.modal-body { padding: 1rem; overflow-y: auto; flex: 1; }
.btn-close { background: rgba(255,255,255,.18); color: #fff; border: none; border-radius: 5px; width: 28px; height: 28px; cursor: pointer; font-weight: 800; font-size: 1rem; }

/* ── Toast ── */
.toast-notif { position: fixed; bottom: 20px; right: 20px; background: #0f172a; color: #fff; padding: .75rem 1.25rem; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,.2); z-index: 99999; font-size: .82rem; font-weight: 600; display: flex; align-items: center; gap: .5rem; transform: translateY(150%); transition: transform .3s cubic-bezier(0.16,1,0.3,1); }
.toast-notif.show { transform: translateY(0); }

/* ── Zoom de imágenes ── */
.zoomable-img { max-width: 100%; max-height: 100%; object-fit: contain; cursor: zoom-in; transition: width .15s ease, height .15s ease; }
.zoomable-img.zoomed { max-width: none !important; max-height: none !important; width: 180% !important; height: auto !important; cursor: zoom-out; }
.img-zoom-wrapper { height: 360px; width: 100%; overflow: auto; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: #fff; position: relative; user-select: none; }
.img-zoom-wrapper.zoomed-mode { display: block !important; cursor: grab; }
</style>


{{-- ═══ PAGE HEADER STICKY (Título + Mes + Filtros Globales) ═══ --}}
<div class="bk-page-header">
    <div class="bk-page-header__left">
        <a href="{{ route('admin.informes.hub') }}" style="color:rgba(255,255,255,.55);font-size:.75rem;text-decoration:none;display:flex;align-items:center;gap:.25rem;">← Informes</a>
        <div style="font-size:1.05rem;font-weight:800;letter-spacing:-.01em;">🏦 Conciliación de Bancos</div>
    </div>
    <div class="bk-page-header__right">
        {{-- Filtros globales --}}
        <div class="filtros-globales" id="filtros-globales-bar">
            <span style="font-size:.68rem;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.05em">Filtrar:</span>
            <button type="button" class="btn-filtro-g active" onclick="filtrarTodos('todos', this)">Todos</button>
            <button type="button" class="btn-filtro-g" onclick="filtrarTodos('pendiente', this)">🕐 Pendientes</button>
            <button type="button" class="btn-filtro-g" onclick="filtrarTodos('no_aparece', this)">❌ No confirmados</button>
            <button type="button" class="btn-filtro-g" onclick="filtrarTodos('verificado', this)">✅ Verificados</button>
        </div>
        {{-- Selector de mes --}}
        <form method="GET" style="display:flex;align-items:center;gap:.4rem">
            <label style="font-size:.72rem;color:rgba(255,255,255,.55)">Mes:</label>
            <select name="mes" onchange="this.form.submit()"
                    style="border-radius:7px;padding:.3rem .6rem;font-size:.8rem;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;outline:none">
                @foreach($meses as $m)
                <option value="{{ $m }}" @selected($m === $mes) style="background:#0f172a">
                    {{ \Carbon\Carbon::createFromFormat('Y-m', $m)->locale('es')->isoFormat('MMMM YYYY') }}
                </option>
                @endforeach
            </select>
        </form>
    </div>
</div>

@if($saldosConMov->isEmpty())
<div style="background:#fff;border-radius:12px;border:2px dashed #e2e8f0;padding:2rem;text-align:center;color:#94a3b8">
    Sin movimientos bancarios en este mes
</div>
@endif

@foreach($saldosConMov as $sb)
@php
    // Resumen para el card cerrado: cuántos movimientos y cuántos el banco no respalda.
    $nMovs = $sb['movimientos']->count();
    $nSinRespaldo = $sb['movimientos']->filter(fn ($m) => ($m->extracto['estado'] ?? '') === 'sin_respaldo')->count();
@endphp
<div class="banco-card" id="banco-card-{{ $sb['banco']->id }}" x-data="{ abierto: {{ $loop->first && $saldosConMov->count() === 1 ? 'true' : 'false' }} }">
    {{-- Header sticky del banco — abre y cierra la tabla --}}
    <div class="banco-card-sticky" id="banco-header-{{ $sb['banco']->id }}"
         @click="abierto = !abierto" style="cursor:pointer" title="Clic para abrir o cerrar">
        <div>
            <div class="banco-nombre">
                <span x-text="abierto ? '▾' : '▸'" style="color:#94a3b8;font-size:.8rem"></span>
                🏦
                {{ $sb['banco']->banco }}
                @php $nSinB = trim(str_ireplace($sb['banco']->banco, '', $sb['banco']->nombre ?? '')); @endphp
                @if($nSinB)<span style="font-weight:500;color:#475569">{{ $nSinB }}</span>@endif
            </div>
            @if($sb['banco']->numero_cuenta)
            <div class="banco-cuenta">Cta. {{ $sb['banco']->numero_cuenta }}</div>
            @endif
        </div>
        <div style="display:flex;align-items:center;gap:1rem">
            <div style="text-align:right">
                <div style="font-size:.72rem;color:#64748b;font-weight:600">{{ $nMovs }} movimientos</div>
                @if($nSinRespaldo)
                    <div style="font-size:.68rem;color:#dc2626;font-weight:700">⚠️ {{ $nSinRespaldo }} sin respaldo</div>
                @endif
            </div>
            {{-- Saldo del libro al cierre del mes filtrado, contra el que reporta
                 el extracto. La diferencia es el descuadre real de ese mes. --}}
            @php
                $sMes = $sb['saldo_mes'];
                $sExt = $sb['saldo_extracto'];
                $dif  = $sExt !== null ? $sMes - $sExt : null;
            @endphp
            <div style="text-align:right">
                <div id="saldo-banco-{{ $sb['banco']->id }}" class="banco-saldo" style="color:{{ $sMes >= 0 ? '#1d4ed8' : '#dc2626' }}">
                    {{ $fmt($sMes) }}
                </div>
                <div style="font-size:.62rem;color:#94a3b8"
                     title="Histórico de la cuenta: {{ $fmt($sb['saldo']) }}">
                    Saldo del libro al cierre de {{ \Carbon\Carbon::createFromFormat('Y-m', $mes)->locale('es')->isoFormat('MMMM') }}
                </div>
            </div>
            @if($sExt !== null)
            <div style="text-align:right;border-left:1px solid #e2e8f0;padding-left:1rem">
                <div class="banco-saldo" style="color:#0f172a">{{ $fmt($sExt) }}</div>
                <div style="font-size:.62rem;color:#94a3b8">Según el extracto</div>
                @if($dif !== 0)
                    <div style="font-size:.68rem;font-weight:800;color:{{ $sb['banco']->uso_personal ? '#6d28d9' : '#dc2626' }};margin-top:.1rem"
                         title="{{ $dif > 0 ? 'El libro tiene más plata que el banco' : 'El banco tiene más plata que el libro' }}">
                        {{ $dif > 0 ? '+' : '−' }}{{ $fmt(abs($dif)) }} de diferencia
                        @if($sb['banco']->uso_personal)<span style="font-weight:600">(cuenta mixta)</span>@endif
                    </div>
                    @if($sb['personal_n'])
                        <div style="font-size:.64rem;color:#6d28d9;margin-top:.05rem"
                             title="Movimientos marcados como personales en este mes">
                            👤 {{ $sb['personal_n'] }} personales · {{ $fmt(abs($sb['personal_neto'])) }}
                        </div>
                    @endif
                @else
                    <div style="font-size:.68rem;font-weight:800;color:#15803d;margin-top:.1rem">✅ cuadra</div>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Tabla de movimientos (sin overflow-x aqui para que el sticky del thead funcione) --}}
    <div x-show="abierto" x-cloak>
    <table class="tbl" data-banco-id="{{ $sb['banco']->id }}">
        <thead><tr>
            <th>Tipo</th>
            <th>Fact.</th>
            <th style="text-align:left;padding-left:.8rem">Cliente / Empresa</th>
            <th style="text-align:left;padding-left:.8rem">Descripción</th>
            <th>Registró</th>
            <th>Fecha</th>
            <th>Referencia</th>
            <th style="text-align:right;padding-right:.8rem">Valor</th>
            <th>Estado</th>
            <th title="Respaldo en el extracto del banco">Extracto</th>
            <th>Img.</th>
        </tr></thead>
        <tbody>
        @php $fechaActual = null; @endphp
        @foreach($sb['movimientos'] as $mov)
        @php
            $estadoFila = $mov->es_salida ? 'verificado' : ($mov->confirmado ? 'verificado' : ($mov->no_aparece ? 'no_aparece' : 'pendiente'));
            $rowId = $mov->es_salida ? 'gasto-row-' . $mov->id : 'consignacion-row-' . $mov->cs_id;
            $fechaMov = sqldate($mov->fecha)->format('d/m/Y');
        @endphp
        @if($fechaActual !== $fechaMov)
            @php $fechaActual = $fechaMov; @endphp
            <tr class="fecha-separador-row" data-fecha="{{ $fechaMov }}">
                <td colspan="11">📅 {{ $fechaMov }}</td>
            </tr>
        @endif
        <tr id="{{ $rowId }}" class="mov-row" data-estado="{{ $estadoFila }}" data-fecha-grupo="{{ $fechaMov }}"
            @if(!$mov->es_salida)
                data-cs-id="{{ $mov->cs_id }}"
                data-factura-id="{{ $mov->factura_id ?? '' }}"
                data-num-factura="{{ $mov->num_factura ?? '' }}"
                data-valor-fmt="{{ $fmt($mov->valor) }}"
                data-referencia="{{ $mov->referencia ?? '' }}"
                data-imagen-url="{{ $mov->imagen_path ? asset('storage/' . $mov->imagen_path) : '' }}"
            @endif
        >

            {{-- Tipo --}}
            <td>
                @if(!$mov->es_salida)
                @php
                    $tipoLabel = match($mov->tipo ?? 'cliente') {
                        'cliente'            => ['label' => 'Pago SS',    'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => '📥'],
                        'anticipo'           => ['label' => 'Anticipo',   'color' => '#92400e', 'bg' => '#fef3c7', 'icon' => '💰'],
                        'traslado_efectivo'  => ['label' => 'Ef→Banco',   'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => '📥'],
                        'banco_recibido'     => ['label' => 'T. entrada', 'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => '📥'],
                        'abono_prestamo'     => ['label' => 'Abono préstamo', 'color' => '#0f766e', 'bg' => '#ccfbf1', 'icon' => '💳'],
                        default              => ['label' => ucfirst($mov->tipo ?? ''), 'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => '📥'],
                    };
                @endphp
                <span class="badge" style="background:{{ $tipoLabel['bg'] }};color:{{ $tipoLabel['color'] }}">
                    {{ $tipoLabel['icon'] }} {{ $tipoLabel['label'] }}
                </span>
                @else
                <span class="badge" style="background:#fee2e2;color:#dc2626">
                    📤 {{ str_contains($mov->tipo ?? '','banco') ? 'Transferencia' : ucfirst(str_replace('_',' ',$mov->tipo ?? '')) }}
                </span>
                @endif
            </td>

            {{-- Factura / Anticipo --}}
            <td>
                @if(($mov->tipo ?? '') === 'anticipo' && ($mov->anticipo_id ?? null))
                    <div style="display:flex;flex-direction:column;align-items:center;gap:.15rem">
                        <a href="#" onclick="abrirReciboAnticipo({{ $mov->anticipo_id }}); return false;"
                           title="Ver Recibo de Anticipo"
                           style="font-weight:700;font-size:.78rem;color:#b45309;text-decoration:none;background:#fef3c7;border-radius:4px;padding:1px 5px;border:1px solid #fde68a">
                            💰 #{{ $mov->anticipo_id }}
                        </a>
                        @if($mov->anticipo_factura_id ?? null)
                            <div style="display:flex;align-items:center;gap:.1rem;font-size:.62rem;color:#64748b">
                                <span>Aplicado →</span>
                                <a href="#" onclick="abrirRecibo({{ $mov->anticipo_factura_id }}); return false;"
                                   style="font-weight:700;color:#2563eb;text-decoration:none">
                                    📋 #{{ $mov->anticipo_factura_num }}
                                </a>
                            </div>
                        @else
                            <span style="font-size:.6rem;color:#047857;font-weight:700;background:#dcfce7;border-radius:3px;padding:0 4px;border:1px solid #bbf7d0">Disponible</span>
                        @endif
                    </div>
                @elseif($mov->num_factura)
                    <a href="#" onclick="abrirRecibo({{ $mov->factura_id }}); return false;"
                       style="font-weight:700;font-size:.78rem;color:#2563eb;text-decoration:none">
                        📋 #{{ $mov->num_factura }}
                    </a>
                @else
                    <span style="color:#cbd5e1;font-size:.72rem">—</span>
                @endif
            </td>

            {{-- Cliente / Empresa --}}
            <td style="font-size:.77rem;max-width:175px;text-align:left;padding-left:.8rem">
                @if($mov->es_salida ?? false)
                    <span style="color:#64748b">{{ $mov->pagador ?? '—' }}</span>
                @elseif($mov->pagador ?? null)
                    @if($mov->es_empresa ?? false)
                        <span title="Empresa" style="font-size:.72rem">🏢</span>
                        <span style="font-weight:700;color:#1e40af">{{ $mov->pagador }}</span>
                    @else
                        <span title="Cliente" style="font-size:.72rem">👤</span>
                        <span style="font-weight:600;color:#1e293b">{{ $mov->pagador }}</span>
                    @endif
                @else
                    <span style="color:#cbd5e1">—</span>
                @endif
            </td>

            {{-- Descripción — izquierda --}}
            <td class="celda-descripcion" style="text-align:left;font-size:.73rem;color:#64748b;max-width:180px;padding-left:.8rem">
                {{ $mov->descripcion ? \Str::limit($mov->descripcion, 55) : '—' }}
            </td>

            {{-- Registró --}}
            <td style="font-size:.73rem;color:#64748b;white-space:nowrap">
                {{ $mov->usuario ?? '—' }}
            </td>

            {{-- Fecha --}}
            <td style="font-size:.73rem;white-space:nowrap;color:#64748b">
                {{ sqldate($mov->fecha)->format('d/m/Y') }}
            </td>

            {{-- Referencia --}}
            <td style="font-size:.74rem;font-family:monospace;font-weight:600;color:#334155;white-space:nowrap">
                {{ $mov->referencia ?: '—' }}
            </td>

            {{-- Valor — alineado a la derecha con número visible --}}
            <td style="text-align:right;padding-right:.8rem;font-family:monospace;font-weight:700;font-size:.85rem;
                       color:{{ $mov->es_salida ? '#dc2626' : '#15803d' }};white-space:nowrap">
                {{ $mov->es_salida ? '-' : '+' }}{{ $fmt($mov->valor) }}
            </td>

            {{-- Estado (clic abre modal — solo admin/superadmin pueden cambiar) --}}
            <td>
                @if(!$mov->es_salida)
                @php
                    $bgEstado = '#fef3c7'; $colEstado = '#b45309'; $txtEstado = '🕐 Pendiente';
                    if ($mov->confirmado) { $bgEstado = '#dcfce7'; $colEstado = '#15803d'; $txtEstado = '✅ Verificado'; }
                    elseif ($mov->no_aparece) { $bgEstado = '#fee2e2'; $colEstado = '#dc2626'; $txtEstado = '❌ No aparece'; }
                @endphp
                @if(auth()->user()->hasRole(['admin','superadmin']))
                <button type="button"
                        onclick="abrirModalEstado({{ $mov->cs_id }}, {{ $mov->confirmado ? 'true' : 'false' }}, {{ $mov->no_aparece ? 'true' : 'false' }})"
                        class="btn-sm btn-estado-clic"
                        style="background:{{ $bgEstado }};color:{{ $colEstado }}">
                    {{ $txtEstado }}
                </button>
                @else
                <span class="badge" style="background:{{ $bgEstado }};color:{{ $colEstado }}">
                    {{ $txtEstado }}
                </span>
                @endif
                @else
                <span style="font-size:.72rem;color:#94a3b8">—</span>
                @endif
            </td>

            {{-- Extracto del banco: ¿el movimiento existe de verdad en la cuenta? --}}
            <td style="white-space:nowrap">
                @php
                    $ex = $mov->extracto ?? ['estado' => 'sin_extracto'];
                    $reglas = [
                        'referencia'    => 'por comprobante',
                        'fecha_valor'   => 'fecha y valor',
                        'valor_cercano' => 'valor, otro día',
                        'partido'       => 'pago partido',
                        'agrupado'      => 'varias facturas',
                        'manual'        => 'vinculado a mano',
                    ];
                @endphp
                @if($ex['estado'] === 'cruzado')
                    <span class="badge" style="background:#dcfce7;color:#15803d"
                          title="Cruzado {{ $reglas[$ex['regla']] ?? $ex['regla'] }}{{ ($ex['piezas'] ?? 1) > 1 ? ' · ' . $ex['piezas'] . ' movimientos' : '' }}{{ ($ex['dias'] ?? 0) > 0 ? ' · ' . $ex['dias'] . ' día(s) de diferencia' : '' }}">
                        🏦 En el banco
                    </span>
                @elseif($ex['estado'] === 'sin_respaldo')
                    <span class="badge" style="background:#fee2e2;color:#dc2626"
                          title="El extracto de este mes está cargado y no reporta esta entrada">
                        ⚠️ No está
                    </span>
                @elseif($ex['estado'] === 'no_aplica')
                    <span style="font-size:.72rem;color:#cbd5e1">—</span>
                @else
                    <span class="badge" style="background:#f1f5f9;color:#64748b"
                          title="Todavía no se ha cargado el extracto de este mes para esta cuenta">
                        sin extracto
                    </span>
                @endif
            </td>

            {{-- Imagen --}}
            <td style="white-space:nowrap">
                @if($mov->imagen_path ?? null)
                <button type="button" class="btn-sm" style="background:#dbeafe;color:#1d4ed8"
                        onclick="verImagen('{{ asset('storage/' . $mov->imagen_path) }}', {{ $mov->id }}, {{ $mov->es_gasto ? 'true' : 'false' }})">
                    🖼 Ver
                </button>
                @else
                <button type="button" class="btn-sm" style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0"
                        onclick="abrirSubirImagen({{ $mov->id }}, {{ $mov->es_gasto ? 'true' : 'false' }}, false)">
                    📎 Adjuntar
                </button>
                @endif
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endforeach


{{-- ═══ MODAL: Anular consignación duplicada + convertir a préstamo ═══ --}}
<div id="modal-anular-prestamo" class="modal-bg" onclick="if(event.target===this)cerrarModal('modal-anular-prestamo')">
    <div class="modal-box" style="width:min(460px,96vw)">
        <div class="modal-head" style="background:#7f1d1d">
            <span style="color:#fff;font-weight:700;font-size:.9rem">⚠️ Anular Consignación Duplicada</span>
            <button onclick="cerrarModal('modal-anular-prestamo')" class="btn-close">×</button>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem">
            <div style="background:#fff1f2;border:1px solid #fecdd3;border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:#9f1239">
                <strong>⚠️ Esta acción es irreversible.</strong><br>
                Convierte la factura a <strong>préstamo</strong> y anula la consignación bancaria.
                Úsalo solo cuando el plano ya fue pagado y la consignación está duplicada.
            </div>

            <div style="background:#f8fafc;border-radius:8px;padding:.7rem 1rem;font-size:.83rem">
                <div style="display:flex;justify-content:space-between;margin-bottom:.3rem">
                    <span style="color:#64748b">Factura:</span>
                    <strong id="ap-num-factura" style="color:#0f172a"></strong>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span style="color:#64748b">Valor consignación:</span>
                    <strong id="ap-valor" style="color:#dc2626"></strong>
                </div>
            </div>

            <div>
                <label style="font-size:.75rem;font-weight:700;color:#7f1d1d;display:block;margin-bottom:.35rem">
                    Confirmar: escribe el número de factura para continuar
                </label>
                <input id="ap-confirm-input" type="text" autocomplete="off"
                       placeholder="Ej: 13127"
                       style="width:100%;border:2px solid #fecdd3;border-radius:8px;padding:.5rem .75rem;font-size:.9rem;font-weight:700;color:#0f172a;outline:none;box-sizing:border-box;transition:border-color .15s"
                       oninput="validarConfirmAnular(this.value)"
                       onfocus="this.style.borderColor='#be123c'" onblur="this.style.borderColor='#fecdd3'">
            </div>

            <button id="ap-btn-confirmar" type="button" onclick="confirmarAnularPrestamo()"
                    disabled
                    style="width:100%;padding:.75rem;background:#9f1239;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:not-allowed;font-size:.88rem;opacity:.5;transition:all .2s">
                🗑️ Convertir a Préstamo y Anular Consignación
            </button>
        </div>
    </div>
</div>

{{-- ═══ MODAL: Estado consignación ═══ --}}
<div id="modal-estado" class="modal-bg" onclick="if(event.target===this)cerrarModal('modal-estado')">
    <div class="modal-box" id="modal-estado-box" style="width:min(820px,96vw);transition:width .22s cubic-bezier(0.16,1,0.3,1)">
        <div class="modal-head">
            <span style="color:#fff;font-weight:700;font-size:.9rem">🏦 Estado de consignación</span>
            <button onclick="cerrarModal('modal-estado')" class="btn-close">×</button>
        </div>
        <div class="modal-body" style="display:flex;gap:1.2rem;padding:1.2rem;flex-wrap:wrap">
            <!-- Columna Izquierda: Visor del comprobante -->
            <div id="modal-estado-comp-container" style="flex:1.4;border:1px solid #e2e8f0;border-radius:10px;padding:.5rem;text-align:center;background:#f8fafc;display:none;min-width:280px">
                <div style="font-size:.7rem;font-weight:700;color:#64748b;margin-bottom:.4rem;text-transform:uppercase">Comprobante Adjunto (Clic para Zoom):</div>
                <div id="modal-estado-img-wrapper" class="img-zoom-wrapper">
                    <img id="modal-estado-img" src="" onclick="toggleZoomImg(this)" class="zoomable-img" style="display:none" alt="Comprobante">
                    <iframe id="modal-estado-pdf" src="" style="width:100%;height:100%;border:none;display:none"></iframe>
                </div>
            </div>

            <!-- Columna Derecha: Opciones y estado -->
            <div id="modal-estado-opciones" style="flex:1;display:flex;flex-direction:column;justify-content:center;gap:1rem;min-width:240px">
                <div>
                    <p style="font-size:.88rem;color:#1e293b;margin:0 0 .5rem 0;font-weight:700">¿Cambiar el estado de esta consignación?</p>
                    {{-- Referencia --}}
                    <div id="modal-estado-referencia" style="display:none;margin-bottom:.5rem;background:#f1f5f9;border-radius:7px;padding:.4rem .75rem;font-size:.78rem;color:#334155;display:flex;align-items:center;gap:.4rem">
                        <span style="font-weight:700;color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em">Ref.:</span>
                        <span id="modal-estado-ref-valor" style="font-weight:700;color:#0f172a;font-family:monospace"></span>
                    </div>
                    <div id="estado-actual" style="text-align:center;font-size:.85rem;font-weight:600;padding:.6rem;border-radius:8px"></div>
                </div>
                
                {{-- Observación libre --}}
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;display:block;margin-bottom:.3rem">Observación:</label>
                    <textarea id="modal-estado-obs" rows="2"
                              style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:.45rem .6rem;font-size:.8rem;color:#1e293b;resize:vertical;font-family:inherit;outline:none;transition:border-color .15s;box-sizing:border-box"
                              onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#e2e8f0'"></textarea>
                </div>
                
                <div style="display:flex;flex-direction:column;gap:.5rem">
                    <button type="button" onclick="cambiarEstadoConsignacion('verificar')"
                            style="width:100%;padding:.7rem;background:#16a34a;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.85rem">
                        ✅ Marcar Verificado
                    </button>
                    <button type="button" onclick="cambiarEstadoConsignacion('pendiente')"
                            style="width:100%;padding:.7rem;background:#f59e0b;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.85rem">
                        🕐 Marcar Pendiente
                    </button>
                    <button type="button" onclick="cambiarEstadoConsignacion('no-aparece')"
                            style="width:100%;padding:.7rem;background:#dc2626;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.85rem">
                        ❌ No aparece en banco
                    </button>
                    
                    @if(auth()->user()->hasRole('superadmin'))
                    <button type="button" id="modal-estado-btn-anular-prestamo" onclick="irAAnularDesdeEstado()"
                            style="display:none;width:100%;padding:.65rem;background:#fff1f2;color:#be123c;border:1px dashed #fecdd3;border-radius:8px;font-weight:700;cursor:pointer;font-size:.8rem;margin-top:.4rem">
                        ⚠️ Convertir a Préstamo y Anular
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ═══ MODAL: Ver imagen ═══ --}}
<div id="modal-img" class="modal-bg" onclick="if(event.target===this)cerrarModal('modal-img')">
    <div class="modal-box">
        <div class="modal-head">
            <span style="color:#fff;font-weight:700;font-size:.9rem">🖼 Comprobante</span>
            <div style="display:flex;gap:.5rem;align-items:center">
                @if(auth()->user()->hasRole(['admin','superadmin']))
                <button id="btn-reemplazar-modal-img" type="button" class="btn-sm" style="background:#f59e0b;color:#fff;font-weight:700;display:none">
                    🔄 Reemplazar
                </button>
                @endif
                <button onclick="cerrarModal('modal-img')" class="btn-close">×</button>
            </div>
        </div>
        <div class="modal-body img-zoom-wrapper" id="modal-img-wrapper" style="height:70vh">
            <img id="img-preview" src="" onclick="toggleZoomImg(this)" class="zoomable-img" style="display:none" alt="comprobante">
            <iframe id="pdf-preview" src="" style="display:none;width:100%;height:100%;border:none"></iframe>
        </div>
    </div>
</div>

{{-- ═══ MODAL: Subir / Reemplazar imagen ═══ --}}
<div id="modal-subir" class="modal-bg" onclick="if(event.target===this)cerrarModal('modal-subir')">
    <div class="modal-box" style="width:min(440px,96vw)">
        <div class="modal-head" id="modal-subir-head">
            <span id="modal-subir-titulo" style="color:#fff;font-weight:700;font-size:.9rem">📎 Adjuntar comprobante</span>
            <button onclick="cerrarModal('modal-subir')" class="btn-close">×</button>
        </div>
        <div class="modal-body">
            <form id="form-subir" method="POST" enctype="multipart/form-data" onsubmit="return subirComprobanteSubmit()">
                @csrf

                {{-- Badge reemplazar --}}
                <div id="badge-reemplazar" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;
                     padding:.4rem .75rem;font-size:.75rem;font-weight:700;color:#92400e;margin-bottom:.7rem">
                    ⚠️ Reemplazando el comprobante actual
                </div>

                {{-- Drop zone --}}
                <div id="drop-zone-subir"
                     onclick="document.getElementById('file-input-subir').click()"
                     style="border:2px dashed #3b82f6;border-radius:11px;padding:1.1rem;text-align:center;
                            cursor:pointer;background:#eff6ff;transition:background .15s;margin-bottom:.7rem;position:relative">
                    <div style="font-size:1.5rem">📁</div>
                    <div id="drop-label-subir" style="font-size:.8rem;color:#2563eb;font-weight:700;margin-top:.3rem">
                        Clic, arrastra o pega (Ctrl+V)
                    </div>
                    <div style="font-size:.67rem;color:#93c5fd;margin-top:.2rem">JPG, PNG o PDF · máx 5 MB</div>
                    <input type="file" id="file-input-subir" name="imagen" accept="image/*,.pdf"
                           style="display:none" onchange="onFileSubir(this.files[0])">
                </div>

                {{-- Preview --}}
                <div id="preview-subir" style="display:none;margin-bottom:.7rem;position:relative">
                    <img id="img-subir" src="" alt="preview"
                         style="max-width:100%;max-height:180px;border-radius:9px;border:1px solid #e2e8f0;object-fit:contain;display:block">
                    <div id="pdf-subir" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;
                         border-radius:9px;padding:.6rem;font-size:.78rem;color:#374151;font-weight:600">
                        📄 <span id="pdf-name-subir"></span>
                    </div>
                    <button type="button" onclick="clearFileSubir()"
                            style="position:absolute;top:4px;right:4px;background:#dc2626;color:#fff;
                                   border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;
                                   font-size:.75rem;font-weight:800;line-height:1">×</button>
                </div>

                <div id="error-subir" style="display:none;background:#fee2e2;border:1px solid #fca5a5;
                     border-radius:8px;padding:.4rem .7rem;font-size:.75rem;color:#dc2626;margin-bottom:.6rem"></div>

                <button type="submit" id="btn-subir-comp"
                        style="width:100%;padding:.6rem;background:#1d4ed8;color:#fff;border:none;
                               border-radius:9px;font-weight:800;cursor:pointer;font-size:.85rem">
                    📤 Subir comprobante
                </button>
            </form>
        </div>
    </div>
</div>

{{-- ═══ MODAL: Recibo factura ═══ --}}
<div id="modal-recibo" class="modal-bg" onclick="if(event.target===this)cerrarModal('modal-recibo')">
    <div class="modal-box lg">
        <div class="modal-head">
            <span style="color:#fff;font-weight:700;font-size:.9rem">📋 Recibo de Factura</span>
            <div style="display:flex;gap:.4rem;align-items:center">
                <a id="btn-abrir-recibo" href="#" target="_blank"
                   style="background:rgba(255,255,255,.18);color:#fff;text-decoration:none;border-radius:5px;padding:.3rem .7rem;font-size:.78rem;font-weight:600">
                    🔗 Abrir
                </a>
                <button onclick="cerrarModal('modal-recibo')" class="btn-close">×</button>
            </div>
        </div>
        <div style="padding:0;flex:1;overflow:hidden">
            <iframe id="iframe-recibo" src="" style="width:100%;height:82vh;border:none"></iframe>
        </div>
    </div>
</div>

<script>
const baseUrl = '{{ url('') }}';
let _subirFile = null;
let _subirPasteHandler = null;

function cerrarModal(id) {
    document.getElementById(id).classList.remove('open');
    if (id === 'modal-img') {
        document.getElementById('img-preview').src = '';
        document.getElementById('pdf-preview').src = '';
        resetZoomImg('img-preview', 'modal-img-wrapper');
    }
    if (id === 'modal-recibo') document.getElementById('iframe-recibo').src = '';
    if (id === 'modal-subir') {
        clearFileSubir();
        if (_subirPasteHandler) { document.removeEventListener('paste', _subirPasteHandler); _subirPasteHandler = null; }
    }
    if (id === 'modal-estado') {
        resetZoomImg('modal-estado-img', 'modal-estado-img-wrapper');
        const obs = document.getElementById('modal-estado-obs');
        if (obs) obs.value = '';
    }
    if (id === 'modal-anular-prestamo') {
        const input = document.getElementById('ap-confirm-input');
        if (input) input.value = '';
    }
}

let _currentCsId = null;

function abrirModalEstado(csId, esVerificado, noAparece) {
    resetZoomImg('modal-estado-img', 'modal-estado-img-wrapper');
    _currentCsId = csId;

    const tr = document.getElementById(`consignacion-row-${csId}`);
    const referencia = tr ? tr.getAttribute('data-referencia') : '';
    const imagenUrl = tr ? tr.getAttribute('data-imagen-url') : '';
    const facturaId = tr ? tr.getAttribute('data-factura-id') : '';
    const numFactura = tr ? tr.getAttribute('data-num-factura') : '';
    const valorFmt = tr ? tr.getAttribute('data-valor-fmt') : '';

    // Mostrar / ocultar referencia
    const refContainer = document.getElementById('modal-estado-referencia');
    const refValor = document.getElementById('modal-estado-ref-valor');
    if (referencia && refContainer && refValor) {
        refValor.textContent = referencia;
        refContainer.style.display = 'flex';
    } else if (refContainer) {
        refContainer.style.display = 'none';
    }

    // Configurar estado actual en el modal
    const div = document.getElementById('estado-actual');
    if (esVerificado) {
        div.textContent = '✅ Estado actual: Verificado';
        div.style.background = '#dcfce7'; div.style.color = '#15803d';
    } else if (noAparece) {
        div.textContent = '❌ Estado actual: No aparece en banco';
        div.style.background = '#fee2e2'; div.style.color = '#dc2626';
    } else {
        div.textContent = '🕐 Estado actual: Pendiente de verificar';
        div.style.background = '#fef3c7'; div.style.color = '#b45309';
    }
    
    // Configurar visor de imagen/PDF y tamaño del modal
    const box = document.getElementById('modal-estado-box');
    const container = document.getElementById('modal-estado-comp-container');
    const img = document.getElementById('modal-estado-img');
    const pdf = document.getElementById('modal-estado-pdf');
    
    if (imagenUrl) {
        if (box) box.style.width = 'min(820px, 96vw)';
        container.style.display = 'block';
        const isPdf = imagenUrl.toLowerCase().endsWith('.pdf');
        if (isPdf) {
            img.style.display = 'none';
            pdf.style.display = 'block';
            pdf.src = imagenUrl;
        } else {
            pdf.style.display = 'none';
            img.style.display = 'block';
            img.src = imagenUrl;
        }
    } else {
        if (box) box.style.width = 'min(380px, 96vw)';
        container.style.display = 'none';
        img.src = '';
        pdf.src = '';
    }

    // Controlar visibilidad del botón de anulación para superadmin
    const btnAnular = document.getElementById('modal-estado-btn-anular-prestamo');
    if (btnAnular) {
        if (facturaId) {
            btnAnular.style.display = 'block';
            btnAnular.dataset.csId = csId;
            btnAnular.dataset.facturaId = facturaId;
            btnAnular.dataset.numFactura = numFactura;
            btnAnular.dataset.valorFmt = valorFmt;
        } else {
            btnAnular.style.display = 'none';
        }
    }
    
    document.getElementById('modal-estado').classList.add('open');
}

function verImagen(url, id, esGasto) {
    resetZoomImg('img-preview', 'modal-img-wrapper');
    const isPdf = url.toLowerCase().endsWith('.pdf');
    const img = document.getElementById('img-preview');
    const pdf = document.getElementById('pdf-preview');
    img.style.display = isPdf ? 'none' : 'block';
    pdf.style.display = isPdf ? 'block' : 'none';
    if (isPdf) { pdf.src = url; } else { img.src = url; }
    
    // Configurar botón reemplazar si se proveen ID y esGasto
    const btnReemplazar = document.getElementById('btn-reemplazar-modal-img');
    if (btnReemplazar) {
        if (id !== undefined && esGasto !== undefined) {
            btnReemplazar.style.display = 'inline-block';
            btnReemplazar.onclick = () => {
                cerrarModal('modal-img');
                abrirSubirImagen(id, esGasto, true);
            };
        } else {
            btnReemplazar.style.display = 'none';
        }
    }
    
    document.getElementById('modal-img').classList.add('open');
}

function abrirSubirImagen(id, esGasto, esReemplazar = false) {
    const url = esGasto
        ? `${baseUrl}/cuadre-diario/gasto/${id}/imagen`
        : `${baseUrl}/cuadre-diario/consignacion/${id}/imagen`;
    document.getElementById('form-subir').action = url;

    // Badge y título según si es reemplazar
    const badge = document.getElementById('badge-reemplazar');
    const titulo = document.getElementById('modal-subir-titulo');
    const head   = document.getElementById('modal-subir-head');
    if (esReemplazar) {
        badge.style.display = 'block';
        titulo.textContent  = '🔄 Reemplazar comprobante';
        head.style.background = '#92400e';
    } else {
        badge.style.display = 'none';
        titulo.textContent  = '📎 Adjuntar comprobante';
        head.style.background = '';
    }

    clearFileSubir();
    document.getElementById('error-subir').style.display = 'none';

    // Drop zone drag & drop
    const dz = document.getElementById('drop-zone-subir');
    dz.ondragover  = (e) => { e.preventDefault(); dz.style.background = '#dbeafe'; };
    dz.ondragleave = ()  => { dz.style.background = '#eff6ff'; };
    dz.ondrop      = (e) => { e.preventDefault(); dz.style.background = '#eff6ff'; const f = e.dataTransfer?.files?.[0]; if (f) onFileSubir(f); };

    // Ctrl+V paste
    if (_subirPasteHandler) document.removeEventListener('paste', _subirPasteHandler);
    _subirPasteHandler = (e) => {
        const item = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
        if (item) onFileSubir(item.getAsFile());
    };
    document.addEventListener('paste', _subirPasteHandler);

    document.getElementById('modal-subir').classList.add('open');
}

function onFileSubir(file) {
    if (!file) return;
    _subirFile = file;
    const prev = document.getElementById('preview-subir');
    const img  = document.getElementById('img-subir');
    const pdf  = document.getElementById('pdf-subir');
    prev.style.display = 'block';
    if (file.type === 'application/pdf') {
        img.style.display = 'none'; pdf.style.display = 'block';
        document.getElementById('pdf-name-subir').textContent = file.name;
    } else {
        pdf.style.display = 'none'; img.style.display = 'block';
        img.src = URL.createObjectURL(file);
    }
    document.getElementById('drop-label-subir').textContent = '✅ ' + file.name;
}

function clearFileSubir() {
    _subirFile = null;
    const fi = document.getElementById('file-input-subir');
    if (fi) fi.value = '';
    document.getElementById('preview-subir').style.display = 'none';
    const img = document.getElementById('img-subir');
    if (img) img.src = '';
    document.getElementById('drop-label-subir').textContent = 'Clic, arrastra o pega (Ctrl+V)';
}

function subirComprobanteSubmit() {
    const err = document.getElementById('error-subir');
    // Si hay archivo pegado/arrastrado, inyectarlo en el input
    if (_subirFile) {
        const dt = new DataTransfer();
        dt.items.add(_subirFile);
        document.getElementById('file-input-subir').files = dt.files;
    }
    const inp = document.getElementById('file-input-subir');
    if (!inp.files || !inp.files[0]) {
        err.textContent = 'Selecciona, arrastra o pega una imagen primero.';
        err.style.display = 'block';
        return false;
    }
    const btn = document.getElementById('btn-subir-comp');
    btn.disabled = true;
    btn.textContent = 'Subiendo...';
    return true; // permite el submit normal del form
}

function abrirRecibo(facturaId) {
    const url = `${baseUrl}/admin/facturacion/recibo/${facturaId}?modal=1`;
    document.getElementById('iframe-recibo').src = url;
    document.getElementById('btn-abrir-recibo').href = url;
    document.getElementById('modal-recibo').classList.add('open');
}

// ── Filtrar TODOS los bancos a la vez ──
function filtrarTodos(estado, btn) {
    document.querySelectorAll('.btn-filtro-g').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('table.tbl').forEach(tabla => {
        const filas = tabla.querySelectorAll('tbody tr.mov-row');
        filas.forEach(tr => {
            const estFila = tr.getAttribute('data-estado');
            tr.style.display = (estado === 'todos' || estFila === estado) ? '' : 'none';
        });
        // Separadores
        tabla.querySelectorAll('tbody tr.fecha-separador-row').forEach(sep => {
            const fecha = sep.getAttribute('data-fecha');
            const visibles = tabla.querySelectorAll(`tr.mov-row[data-fecha-grupo="${fecha}"]:not([style*="display: none"])`);
            sep.style.display = visibles.length > 0 ? '' : 'none';
        });
    });
}


// ── Actualización de Estado vía Fetch (AJAX) ──
function cambiarEstadoConsignacion(accion) {
    if (!_currentCsId) return;

    let url = '';
    let method = 'POST';
    
    if (accion === 'verificar') {
        url = `${baseUrl}/cuadre-diario/consignacion/${_currentCsId}/confirmar`;
    } else if (accion === 'pendiente') {
        url = `${baseUrl}/cuadre-diario/consignacion/${_currentCsId}/confirmar/reversar`;
    } else if (accion === 'no-aparece') {
        url = `${baseUrl}/cuadre-diario/consignacion/${_currentCsId}/no-aparece`;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';
    
    const headers = {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': token
    };

    const body = new FormData();
    if (accion === 'pendiente') {
        body.append('_method', 'PATCH');
    }
    // Agregar observacion libre del usuario si la escribió
    const obsInput = document.getElementById('modal-estado-obs');
    const obsVal = obsInput ? obsInput.value.trim() : '';
    if (obsVal) body.append('observacion_extra', obsVal);

    fetch(url, {
        method: 'POST',
        headers: headers,
        body: body
    })
    .then(res => {
        if (!res.ok) throw new Error('Error al actualizar el estado.');
        return res.json();
    })
    .then(data => {
        if (data.success) {
            const tr = document.getElementById(`consignacion-row-${_currentCsId}`);
            if (tr) {
                // 1. Actualizar el atributo data-estado
                let nuevoEst = 'pendiente';
                if (accion === 'verificar') nuevoEst = 'verificado';
                else if (accion === 'no-aparece') nuevoEst = 'no_aparece';
                tr.setAttribute('data-estado', nuevoEst);

                // 2. Actualizar el botón de Estado en la fila
                const btnEstado = tr.querySelector('.btn-estado-clic');
                if (btnEstado) {
                    if (accion === 'verificar') {
                        btnEstado.style.background = '#dcfce7';
                        btnEstado.style.color = '#15803d';
                        btnEstado.innerHTML = '✅ Verificado';
                        btnEstado.setAttribute('onclick', `abrirModalEstado(${data.consignacion.id}, true, false, ${data.consignacion.imagen_url ? "'"+data.consignacion.imagen_url+"'" : 'null'})`);
                    } else if (accion === 'no-aparece') {
                        btnEstado.style.background = '#fee2e2';
                        btnEstado.style.color = '#dc2626';
                        btnEstado.innerHTML = '❌ No aparece';
                        btnEstado.setAttribute('onclick', `abrirModalEstado(${data.consignacion.id}, false, true, ${data.consignacion.imagen_url ? "'"+data.consignacion.imagen_url+"'" : 'null'})`);
                    } else {
                        btnEstado.style.background = '#fef3c7';
                        btnEstado.style.color = '#b45309';
                        btnEstado.innerHTML = '🕐 Pendiente';
                        btnEstado.setAttribute('onclick', `abrirModalEstado(${data.consignacion.id}, false, false, ${data.consignacion.imagen_url ? "'"+data.consignacion.imagen_url+"'" : 'null'})`);
                    }
                }

                // 3. Actualizar la celda de descripción
                const tdDesc = tr.querySelector('.celda-descripcion');
                if (tdDesc) {
                    const descText = data.consignacion.descripcion || '—';
                    tdDesc.textContent = descText.length > 55 ? descText.substring(0, 52) + '...' : descText;
                    tdDesc.setAttribute('title', descText);
                }
            }

            // 4. Actualizar el saldo del banco
            if (data.nuevo_saldo !== undefined && data.banco_id) {
                const divSaldo = document.getElementById(`saldo-banco-${data.banco_id}`);
                if (divSaldo) {
                    const fmtVal = '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.abs(data.nuevo_saldo));
                    divSaldo.textContent = fmtVal;
                    divSaldo.style.color = data.nuevo_saldo >= 0 ? '#1d4ed8' : '#dc2626';
                }
            }

            cerrarModal('modal-estado');
            mostrarToast(data.message);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Ocurrió un error al procesar el cambio de estado.');
    });
}

// ── Lógica de Zoom Interactivo en Imágenes ──
function toggleZoomImg(img) {
    const wrapper = img.parentElement;
    if (!img.classList.contains('zoomed')) {
        // Activar Zoom (Redimensionamiento real del DOM)
        img.classList.add('zoomed');
        if (wrapper) {
            wrapper.classList.add('zoomed-mode');
            // Centrar el scroll inicialmente
            setTimeout(() => {
                wrapper.scrollLeft = (img.offsetWidth - wrapper.offsetWidth) / 2;
                wrapper.scrollTop = (img.offsetHeight - wrapper.offsetHeight) / 2;
            }, 100);
        }
    } else {
        // Desactivar Zoom
        img.classList.remove('zoomed');
        if (wrapper) {
            wrapper.classList.remove('zoomed-mode');
            wrapper.style.cursor = '';
        }
    }
}

function resetZoomImg(imgId, wrapperId) {
    const img = document.getElementById(imgId);
    if (img) {
        img.classList.remove('zoomed');
    }
    const wrapper = document.getElementById(wrapperId);
    if (wrapper) {
        wrapper.classList.remove('zoomed-mode');
        wrapper.style.cursor = '';
        wrapper.scrollLeft = 0;
        wrapper.scrollTop = 0;
    }
}

// ── Arrastre de imagen con el mouse (Drag to Scroll) ──
function initDragScroll(wrapper) {
    let isDown = false;
    let startX, startY;
    let scrollLeft, scrollTop;

    wrapper.addEventListener('mousedown', (e) => {
        const img = wrapper.querySelector('img.zoomable-img');
        if (img && img.classList.contains('zoomed')) {
            isDown = true;
            wrapper.style.cursor = 'grabbing';
            startX = e.pageX - wrapper.offsetLeft;
            startY = e.pageY - wrapper.offsetTop;
            scrollLeft = wrapper.scrollLeft;
            scrollTop = wrapper.scrollTop;
            e.preventDefault();
        }
    });

    wrapper.addEventListener('mouseleave', () => {
        isDown = false;
        const img = wrapper.querySelector('img.zoomable-img');
        if (img && img.classList.contains('zoomed')) {
            wrapper.style.cursor = 'grab';
        }
    });

    wrapper.addEventListener('mouseup', () => {
        isDown = false;
        const img = wrapper.querySelector('img.zoomable-img');
        if (img && img.classList.contains('zoomed')) {
            wrapper.style.cursor = 'grab';
        }
    });

    wrapper.addEventListener('mousemove', (e) => {
        if (!isDown) return;
        e.preventDefault();
        const x = e.pageX - wrapper.offsetLeft;
        const y = e.pageY - wrapper.offsetTop;
        const walkX = (x - startX) * 1.5; // multiplicador de velocidad
        const walkY = (y - startY) * 1.5;
        wrapper.scrollLeft = scrollLeft - walkX;
        wrapper.scrollTop = scrollTop - walkY;
    });
}

// ── Sticky: sombra en el header del banco cuando está pegado ──
(function initBancoStickyHeaders() {
    document.querySelectorAll('.banco-card-sticky').forEach(header => {
        const sentinel = document.createElement('div');
        sentinel.style.cssText = 'height:1px;pointer-events:none';
        header.parentElement.insertBefore(sentinel, header);
        new IntersectionObserver(entries => {
            header.classList.toggle('is-stuck', !entries[0].isIntersecting);
        }, { threshold: 0 }).observe(sentinel);
    });
})();
// ── Ajustar top del <thead> al tamaño real del header del banco ──
(function ajustarTheadTop() {
    function recalcular() {
        // El header de la página también es sticky: todo lo demás se apila
        // debajo de él, y su altura cambia cuando los filtros envuelven.
        const pageHeader = document.querySelector('.bk-page-header');
        const topPagina  = pageHeader ? pageHeader.offsetHeight : 0;
        document.documentElement.style.setProperty('--bk-top', topPagina + 'px');

        document.querySelectorAll('.banco-card').forEach(card => {
            const stickyH = card.querySelector('.banco-card-sticky');
            const ths     = card.querySelectorAll('thead th');
            if (!stickyH || !ths.length) return;
            const alturaHeader = stickyH.offsetHeight;
            ths.forEach(th => th.style.top = (topPagina + alturaHeader) + 'px');
        });
    }
    // Calcular al cargar, al cambiar el tamaño de ventana, y cuando se abre o
    // cierra un banco (el thead recién ahí conoce su posición real).
    recalcular();
    window.addEventListener('resize', recalcular);
    document.addEventListener('click', e => {
        if (e.target.closest('.banco-card-sticky')) setTimeout(recalcular, 60);
    });
})();
const wr1 = document.getElementById('modal-estado-img-wrapper');
const wr2 = document.getElementById('modal-img-wrapper');
if (wr1) initDragScroll(wr1);
if (wr2) initDragScroll(wr2);

// ── Mostrar Toast de Notificación ──
function mostrarToast(mensaje) {
    let toast = document.getElementById('app-toast-notif');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'app-toast-notif';
        toast.className = 'toast-notif';
        document.body.appendChild(toast);
    }
    toast.innerHTML = `<span>ℹ️</span> <span>${mensaje}</span>`;
    toast.classList.add('show');
    setTimeout(() => { toast.classList.remove('show'); }, 3500);
}

// ── Abrir Recibo de Anticipo ──
function abrirReciboAnticipo(anticipoId) {
    const url = `${baseUrl}/admin/anticipos/${anticipoId}/recibo?modal=1`;
    document.getElementById('iframe-recibo').src = url;
    document.getElementById('btn-abrir-recibo').href = url;
    document.getElementById('modal-recibo').classList.add('open');
}

// ── Anular Consignación y Convertir a Préstamo (Superadmin) ──
let _apCsId = null;
let _apFacturaId = null;
let _apNumFactura = '';

function abrirModalAnularPrestamo(csId, facturaId, numFactura, valor) {
    _apCsId = csId;
    _apFacturaId = facturaId;
    _apNumFactura = numFactura.toString().trim();
    
    document.getElementById('ap-num-factura').textContent = '#' + numFactura;
    document.getElementById('ap-valor').textContent = '$' + valor;
    
    const input = document.getElementById('ap-confirm-input');
    if (input) input.value = '';
    
    const btn = document.getElementById('ap-btn-confirmar');
    if (btn) {
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.opacity = '0.5';
    }
    
    document.getElementById('modal-anular-prestamo').classList.add('open');
}

function validarConfirmAnular(val) {
    const btn = document.getElementById('ap-btn-confirmar');
    if (!btn) return;
    if (val.toString().trim() === _apNumFactura) {
        btn.disabled = false;
        btn.style.cursor = 'pointer';
        btn.style.opacity = '1';
    } else {
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.opacity = '0.5';
    }
}

function confirmarAnularPrestamo() {
    if (!_apCsId || !_apFacturaId) return;
    
    const url = `${baseUrl}/cuadre-diario/consignacion/${_apCsId}/anular-prestamo`;
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';
    
    const btn = document.getElementById('ap-btn-confirmar');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Procesando...';
    }
    
    fetch(url, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token
        },
        body: JSON.stringify({
            _method: 'DELETE'
        })
    })
    .then(res => {
        if (!res.ok) throw new Error('Error al procesar la solicitud.');
        return res.json();
    })
    .then(data => {
        if (data.success) {
            // Eliminar la fila del DOM
            const tr = document.getElementById(`consignacion-row-${_apCsId}`);
            if (tr) {
                tr.style.transition = 'all 0.3s ease';
                tr.style.opacity = '0';
                setTimeout(() => {
                    tr.remove();
                }, 300);
            }
            
            // Actualizar el saldo del banco
            if (data.nuevo_saldo !== undefined && data.banco_id) {
                const divSaldo = document.getElementById(`saldo-banco-${data.banco_id}`);
                if (divSaldo) {
                    const fmtVal = '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.abs(data.nuevo_saldo));
                    divSaldo.textContent = fmtVal;
                    divSaldo.style.color = data.nuevo_saldo >= 0 ? '#1d4ed8' : '#dc2626';
                }
            }
            
            cerrarModal('modal-anular-prestamo');
            mostrarToast(data.message);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Ocurrió un error al anular la consignación y convertir a préstamo.');
    })
    .finally(() => {
        if (btn) btn.textContent = '🗑️ Convertir a Préstamo y Anular Consignación';
    });
}

function irAAnularDesdeEstado() {
    const btnAnular = document.getElementById('modal-estado-btn-anular-prestamo');
    if (!btnAnular) return;
    const csId = btnAnular.dataset.csId;
    const facturaId = btnAnular.dataset.facturaId;
    const numFactura = btnAnular.dataset.numFactura;
    const valorFmt = btnAnular.dataset.valorFmt;
    
    cerrarModal('modal-estado');
    setTimeout(() => {
        abrirModalAnularPrestamo(csId, facturaId, numFactura, valorFmt);
    }, 250);
}
</script>
@endsection
