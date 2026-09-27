@extends('layouts.app')
@section('modulo', 'Facturación')

@php
use App\Models\BancoCuenta;
$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
// Mes anterior al que se está viendo: lo usa el aviso de "sin facturar".
$mesAnteriorNum   = $mes === 1 ? 12 : $mes - 1;
$anioMesAnterior  = $mes === 1 ? $anio - 1 : $anio;
$nombreMesAnterior = $meses[$mesAnteriorNum - 1];
$fmt   = fn($v) => '$' . number_format($v ?? 0, 0, ',', '.');
$aliadoId = session('aliado_id_activo');
$r100 = fn($v) => (int)(ceil(($v ?? 0) / 100) * 100); // redondeo al centena superior
// El IVA NO se redondea a centena: es impuesto, va exacto como se factura.
$rIva = fn($v) => (int) round($v ?? 0);
// Columna de IVA visible solo si la empresa está marcada con iva=SI
$mostrarIva = \App\Services\IvaService::bandera($empresa->iva ?? null);
$ivaPct     = \App\Models\ConfiguracionBrynex::porcentajeIva();

$estadoLabel = fn($e) => match($e) {
    'pagada'      => 'Pago',
    'abono'       => 'Abono',
    'pre_factura' => 'Pre-factura',
    'prestamo'    => 'Préstamo',
    default       => ucfirst($e)
};
$estadoBg = fn($e) => match($e) {
    'pagada'      => ['#dcfce7','#15803d'],
    'abono'       => ['#fef3c7','#92400e'],
    'prestamo'    => ['#ede9fe','#6d28d9'],
    default       => ['#f1f5f9','#64748b'],
};
@endphp

@section('contenido')
<style>
.fac-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;color:#fff;padding:1rem 1.4rem;margin-bottom:1rem}
.fac-h-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.fac-h-nom{font-size:1.3rem;font-weight:800}
.fac-h-meta{font-size:.78rem;color:#94a3b8;display:flex;gap:1.2rem;margin-top:.3rem;flex-wrap:wrap}
.periodo-sel{display:flex;align-items:center;gap:.5rem}
.periodo-sel select{padding:.35rem .6rem;border-radius:7px;border:1px solid #334155;background:#1e293b;color:#fff;font-size:.85rem}
.btn-act{padding:.4rem 1rem;font-size:.82rem;font-weight:600;border-radius:7px;border:none;cursor:pointer;transition:all .15s}
.btn-fac{background:#2563eb;color:#fff}.btn-fac:hover{background:#1d4ed8}
.btn-exp{background:#475569;color:#fff}.btn-exp:hover{background:#334155}
.btn-sm{padding:.25rem .65rem;font-size:.72rem;border-radius:5px;border:none;cursor:pointer;font-weight:600}
.fil-btn{padding:.3rem .8rem;border-radius:20px;font-size:.75rem;font-weight:600;border:1.5px solid #e2e8f0;background:#f8fafc;cursor:pointer;transition:all .15s}
.fil-btn.active{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}
/* Tabla */
.tbl-wrap{overflow-x:auto}
table.fac-tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.fac-tbl th{background:#0f172a;color:#94a3b8;font-size:.63rem;text-transform:uppercase;letter-spacing:.05em;padding:.4rem .45rem;white-space:nowrap;position:sticky;top:0;z-index:2}
.fac-tbl td{padding:.32rem .45rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.fac-tbl tr:hover td{background:#f8fafc}
.fac-tbl tr.ya-pago td{background:#f0fdf4}
.fac-tbl input[type=checkbox]{width:1.1rem;height:1.1rem;cursor:pointer}
.num-col{font-family:monospace;text-align:right}
.col-iva{text-align:center}
.totales{background:#0f172a;color:#fff;font-weight:700}
.tot-val{color:#34d399}
/* NP editable en la tabla */
.np-chip{display:inline-block;padding:.12rem .45rem;border-radius:20px;font-size:.75rem;font-weight:800;
         background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;cursor:pointer;user-select:none}
.np-chip:hover{background:#ffedd5;border-color:#fb923c}
.np-vacio{display:inline-block;min-width:26px;padding:.12rem .45rem;border-radius:20px;font-size:.7rem;
          color:#cbd5e1;border:1px dashed transparent;cursor:pointer;user-select:none}
.np-vacio:hover{color:#c2410c;border-color:#fed7aa;background:#fff7ed}
.np-select{padding:.1rem .2rem;border:1px solid #fb923c;border-radius:6px;font-size:.75rem;font-weight:800;
           color:#c2410c;background:#fff;text-align:center;cursor:pointer}
.np-select:focus{outline:none;box-shadow:0 0 0 2px #fed7aa}
.td-np.np-ok{background:#dcfce7!important;transition:background .5s}
/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:center;justify-content:center}
.modal-box{background:#fff;border-radius:14px;padding:1.4rem;width:min(600px,96vw);max-height:92vh;overflow-y:auto}
.modal-title{font-size:1rem;font-weight:800;margin-bottom:.9rem;color:#0f172a;border-bottom:2px solid #e2e8f0;padding-bottom:.45rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-bottom:.55rem}
.form-full{grid-column:1/-1}
.flb{display:block;font-size:.67rem;font-weight:700;color:#475569;margin-bottom:.15rem;text-transform:uppercase}
.finp{width:100%;padding:.36rem .48rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;box-sizing:border-box}
.finp:focus{outline:none;border-color:#3b82f6}
.resumen-box{background:#f8fafc;border-radius:8px;padding:.65rem .85rem;margin:.7rem 0;font-size:.82rem}
.resumen-row{display:flex;justify-content:space-between;padding:.15rem 0}
.resumen-row.total{font-weight:700;border-top:1px solid #e2e8f0;margin-top:.3rem;padding-top:.38rem;font-size:.95rem;color:#0f172a}
.btn-guardar{width:100%;padding:.65rem;background:#2563eb;color:#fff;font-size:.92rem;font-weight:700;border:none;border-radius:8px;cursor:pointer;margin-top:.45rem}
.btn-guardar:hover{background:#1d4ed8}
.btn-cancelar{margin-right:.5rem;padding:.48rem 1.1rem;background:#f1f5f9;color:#475569;border:none;border-radius:7px;cursor:pointer;font-weight:600}
/* Estilos para filtros y ordenación en tabla */
.tbl-header-select {
    background: transparent;
    color: #94a3b8;
    border: none;
    font-size: .63rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    outline: none;
    cursor: pointer;
    padding: 0;
    margin: 0;
    width: auto;
    max-width: 100%;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    transition: color .15s;
}
.tbl-header-select:focus {
    color: #fff;
    background: #0f172a;
}
.tbl-header-select option {
    background: #0f172a;
    color: #fff;
    font-size: .75rem;
    text-transform: none;
    letter-spacing: normal;
}
.tbl-header-select.active-filter {
    color: #3b82f6 !important;
    text-shadow: 0 0 1px rgba(59, 130, 246, 0.5);
}
.sort-icon {
    display: inline-block;
    width: 0;
    height: 0;
    margin-left: 4px;
    vertical-align: middle;
    border-right: 4px solid transparent;
    border-left: 4px solid transparent;
    border-top: 4px solid #64748b;
    transition: transform 0.2s ease, border-color 0.2s ease;
}
.sort-icon.asc {
    transform: rotate(180deg);
    border-top-color: #3b82f6;
}
.sort-icon.desc {
    transform: rotate(0deg);
    border-top-color: #3b82f6;
}
</style>

{{-- Header empresa --}}
<div class="fac-header">
    <div class="fac-h-top">
        {{-- Info empresa --}}
        <div>
            <a href="{{ route('admin.facturacion.index') }}" style="color:#94a3b8;text-decoration:none;font-size:.8rem;">← Empresas</a>
            <div class="fac-h-nom" style="margin-top:.2rem; display:inline-flex; align-items:center; gap:.5rem; flex-wrap:wrap;">
                <span>🏢 {{ $empresa->empresa }}</span>
                @if($empresa->asesor || $empresa->contacto)
                    <span style="font-size:1rem; font-weight:500; color:#cbd5e1; margin-left:.3rem;">
                        · 👤 {{ $empresa->asesor ? $empresa->asesor->nombre : $empresa->contacto }}
                    </span>
                @endif
                {{-- Sin la exoneración del 114-1 la planilla cambia de precio: la salud
                     sube al 12,5% y se suman SENA e ICBF. Es el dato que explica por qué
                     esta empresa cotiza más caro que las demás, así que va a la vista. --}}
                @unless($empresa->exonerado_parafiscales ?? true)
                    <span style="font-size:.72rem;font-weight:700;padding:.18rem .5rem;border-radius:999px;background:#7c2d12;color:#fed7aa;letter-spacing:.02em;"
                          title="No exonerada del art. 114-1 ET: sus planillas liquidan SENA 2% + ICBF 3% y la salud al 12,5%.">
                        PARAFISCALES
                    </span>
                @endunless
            </div>
            <div class="fac-h-meta">
                @if($empresa->nit)<span>NIT: {{ $empresa->nit }}</span>@endif
                {{-- Contacto propio de la empresa: arriba se muestra el asesor
                     cuando existe, así que aquí no se pierde a quién buscar. --}}
                @if($empresa->contacto)<span>👤 {{ $empresa->contacto }}</span>@endif
                @if($empresa->celular)<span>📞 {{ $empresa->celular }}</span>@endif
                @if($empresa->telefono)<span>☎️ {{ $empresa->telefono }}</span>@endif
                {{-- Dirección de entrega: la usa quien despacha al mensajero --}}
                @if($empresa->direccion)<span>📍 {{ $empresa->direccion }}</span>@endif
{{-- IVA oculto del encabezado --}}
            </div>
        </div>

        {{-- Botones del header: Historial + Editar --}}
        <div style="display:flex;align-items:center;gap:.45rem;">
            <a href="{{ route('admin.facturacion.empresa.historial', $empresa->id) }}"
               style="display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .85rem;font-size:.8rem;font-weight:600;border-radius:7px;background:rgba(255,255,255,.1);color:#cbd5e1;text-decoration:none;transition:background .15s;"
               onmouseover="this.style.background='rgba(255,255,255,.2)'" onmouseout="this.style.background='rgba(255,255,255,.1)'"
               title="Historial de facturación">📋 Historial</a>
            <button type="button" onclick="MCA.abrir({{ $empresa->id }})"
               style="display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .85rem;font-size:.8rem;font-weight:600;border-radius:7px;background:rgba(255,255,255,.1);color:#cbd5e1;border:none;cursor:pointer;transition:background .15s;"
               onmouseover="this.style.background='rgba(255,255,255,.2)'" onmouseout="this.style.background='rgba(255,255,255,.1)'"
               title="Administrar cobros adicionales (recurrentes o de única vez) de la empresa">⚙️ Cobros Adicionales</button>
            <button type="button" onclick="abrirClavesEmpresa()"
               style="display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .85rem;font-size:.8rem;font-weight:600;border-radius:7px;background:#fef9c3;color:#92400e;border:1px solid #fde68a;cursor:pointer;transition:background .15s;"
               onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef9c3'"
               title="Claves y accesos de la empresa">🔑 Claves</button>
            <a href="{{ route('admin.facturacion.empresa.edit', $empresa->id) }}"
               style="display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .85rem;font-size:.8rem;font-weight:600;border-radius:7px;background:#f59e0b;color:#fff;text-decoration:none;transition:background .15s;"
               onmouseover="this.style.background='#d97706'" onmouseout="this.style.background='#f59e0b'"
               title="Editar empresa">✏️ Editar</a>
        </div>
    </div>
</div>

{{-- Filtros + Acciones --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:.6rem 1rem;margin-bottom:.8rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.55rem;">
    {{-- Izquierda: filtros de estado + buscador --}}
    <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
        <span class="fil-btn active" onclick="filtrar(this,'todos')">Todos ({{ $contratos->count() }})</span>
        <span class="fil-btn" onclick="filtrar(this,'pendiente')">⏳ Pendientes</span>
        <span class="fil-btn" onclick="filtrar(this,'pago')">✅ Pagados</span>
        {{-- Buscador inline por nombre y cédula --}}
        <div style="position:relative;display:inline-flex;align-items:center;">
            <span style="position:absolute;left:.5rem;color:#94a3b8;font-size:.85rem;pointer-events:none;">🔍</span>
            <input id="inp-buscar" type="search" placeholder="Nombre o cédula..."
                   oninput="buscar(this.value)"
                   name="q_{{ rand() }}"
                   autocomplete="off" data-lpignore="true" data-form-type="other" spellcheck="false"
                   readonly onfocus="this.removeAttribute('readonly'); this.style.borderColor='#3b82f6';this.style.background='#fff';this.style.width='230px'"
                   style="padding:.28rem .6rem .28rem 1.75rem;border:1.5px solid #e2e8f0;
                          border-radius:20px;font-size:.8rem;background:#f8fafc;
                          color:#334155;outline:none;width:190px;transition:border .15s,width .2s;"
                   onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc';this.style.width='190px'">
            <button id="btn-limpiar-bus" onclick="limpiarBuscar()" title="Limpiar búsqueda"
                    style="display:none;position:absolute;right:.4rem;background:none;border:none;
                           cursor:pointer;color:#94a3b8;font-size:.85rem;line-height:1;"
                    onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">✕</button>
        </div>
    </div>
    <div style="display:flex;gap:.45rem;align-items:center;flex-wrap:wrap;">


        <button class="btn-act btn-exp" onclick="exportarExcel()">📊 Excel</button>

        <button onclick="abrirModalCargaCedulas()"
            title="Cargar lista de cédulas con NP provisional"
            style="display:inline-flex;align-items:center;justify-content:center;
                   width:30px;height:30px;padding:0;
                   background:linear-gradient(135deg,#0f766e,#0d9488);
                   color:#fff;border:none;border-radius:7px;cursor:pointer;
                   font-size:1rem;transition:opacity .15s;"
            onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">
            📋
        </button>

        <button class="btn-act" onclick="MAD.abrir({{ $empresa->id }}, '{{ addslashes($empresa->razon_social) }}')"
            style="background:linear-gradient(135deg,#b45309,#d97706);color:#fff;"
            title="Registrar anticipo de la empresa para ser distribuido entre contratos">
            🪙 Anticipos
        </button>

        <button class="btn-act" onclick="OI_abrirEmpresa()"
            style="background:linear-gradient(135deg,#065f46,#047857);color:#fff;"
            title="Registrar trámite / otro ingreso para esta empresa"
            data-empresa-id="{{ $empresa->id }}"
            data-empresa-asesor-id="{{ $empresa->asesor_id ?? '' }}"
            data-empresa-asesor-nombre="{{ $empresa->asesor?->nombre ?? '' }}">
            💼 Otro Ingreso
        </button>


        <button class="btn-act" id="btnCuentaCobro" onclick="abrirCuentaCobro('simple')" disabled
            style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;"
            title="Generar Cuenta de Cobro">
            📄 Cuenta Cobro
        </button>

        <button class="btn-act btn-fac" id="btnFacturarSel" onclick="abrirModalFacturar()" disabled>
            🧾 Facturar
        </button>

        {{-- Contador seleccionados (separado) --}}
        <span id="ctSelecBadge" style="
            display:inline-flex;align-items:center;justify-content:center;
            min-width:26px;height:26px;padding:0 .45rem;
            background:#1e3a5f;color:#fff;border-radius:20px;
            font-size:.78rem;font-weight:800;font-family:monospace;
            transition:background .2s;
        " title="Seleccionados"><span id="ctSelec">0</span></span>

        <div style="width:1px;height:24px;background:#e2e8f0;"></div>

        {{-- Selector periodo (mes / año) --}}
        <form method="GET" id="formPeriodo" style="display:flex;align-items:center;gap:.3rem;">
            <select name="mes" onchange="this.form.submit()"
                    style="padding:.28rem .5rem;border-radius:6px;border:1.5px solid #e2e8f0;background:#f8fafc;font-size:.8rem;cursor:pointer;color:#334155;">
                @foreach($meses as $i=>$nm)
                <option value="{{ $i+1 }}" {{ ($i+1)===$mes?'selected':'' }}>{{ $nm }}</option>
                @endforeach
            </select>
            <select name="anio" onchange="this.form.submit()"
                    style="padding:.28rem .4rem;border-radius:6px;border:1.5px solid #e2e8f0;background:#f8fafc;font-size:.8rem;cursor:pointer;color:#334155;">
                @for($y=now()->year+1;$y>=2020;$y--)
                <option value="{{ $y }}" {{ $y===$anio?'selected':'' }}>{{ $y }}</option>
                @endfor
            </select>
        </form>
    </div>
</div>


{{-- Tabla --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">
<div class="tbl-wrap">
<table class="fac-tbl" id="tblTrab">
<thead>
<tr style="user-select: none;">
    <th>
        <select id="filter-tipo" class="tbl-header-select" onchange="aplicarFiltrosTabla()" title="Filtrar por Tipo">
            <option value="todos">TIPO ▾</option>
        </select>
    </th>
    <th onclick="ordenarTabla('cedula')" style="cursor:pointer; text-align:left;" title="Clic para ordenar por Documento">
        DOCUMENTO <span id="sort-icon-cedula" class="sort-icon"></span>
    </th>
    <th onclick="ordenarTabla('nombre')" style="cursor:pointer; text-align:left;" title="Clic para ordenar por Nombre">
        NOMBRE <span id="sort-icon-nombre" class="sort-icon"></span>
    </th>
    <th style="max-width:105px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
        <select id="filter-rs" class="tbl-header-select" onchange="aplicarFiltrosTabla()" style="text-align:left; max-width:105px; text-overflow:ellipsis; overflow:hidden;" title="Filtrar por Razón Social">
            <option value="todos">RAZÓN SOCIAL ▾</option>
        </select>
    </th>
    <th onclick="ordenarTabla('fecha')" style="cursor:pointer; text-align:center;" title="Clic para ordenar por Ingreso / Retiro">
        ING/RET <span id="sort-icon-fecha" class="sort-icon"></span>
    </th>
    <th onclick="ordenarTabla('dias')" style="cursor:pointer; text-align:center;" title="Clic para ordenar por Días">
        DÍAS <span id="sort-icon-dias" class="sort-icon"></span>
    </th>
    <th class="num-col">
        <select id="filter-eps" class="tbl-header-select" onchange="aplicarFiltrosTabla()" style="text-align:right;" title="Filtrar por EPS">
            <option value="todos">EPS ▾</option>
        </select>
    </th>
    <th class="num-col">
        <select id="filter-arl" class="tbl-header-select" onchange="aplicarFiltrosTabla()" style="text-align:right;" title="Filtrar por ARL">
            <option value="todos">ARL ▾</option>
        </select>
    </th>
    <th class="num-col">
        <select id="filter-caja" class="tbl-header-select" onchange="aplicarFiltrosTabla()" style="text-align:right;" title="Filtrar por Caja">
            <option value="todos">CAJA ▾</option>
        </select>
    </th>
    <th class="num-col">
        <select id="filter-pension" class="tbl-header-select" onchange="aplicarFiltrosTabla()" style="text-align:right;" title="Filtrar por Pensión">
            <option value="todos">PENSIÓN ▾</option>
        </select>
    </th>
    <th class="num-col">
        <select id="filter-admon" class="tbl-header-select" onchange="aplicarFiltrosTabla()" style="text-align:right;" title="Filtrar por Admon">
            <option value="todos">ADMON ▾</option>
        </select>
    </th>
    <th class="num-col col-iva" @if(!$mostrarIva) style="display:none" @endif>IVA</th>
    <th onclick="ordenarTabla('total')" style="cursor:pointer;" class="num-col" title="Clic para ordenar por Total">
        TOTAL <span id="sort-icon-total" class="sort-icon"></span>
    </th>
    @if($hayMora)
    <th onclick="ordenarTabla('mora')" style="cursor:pointer;" class="num-col" title="Clic para ordenar por Mora">
        ⚠️ MORA <span id="sort-icon-mora" class="sort-icon"></span>
    </th>
    @endif
    @if($hayAnticipos)
    <th class="num-col" style="color:#b45309;" title="Anticipo disponible asignado a este contrato">
        🟡 ANTICIPO
    </th>
    @endif
    <th style="text-align:center">
        <select id="filter-estado" class="tbl-header-select" onchange="aplicarFiltrosTabla()" title="Filtrar por Estado">
            <option value="todos">ESTADO ▾</option>
        </select>
    </th>
    <th style="text-align:center">
        <select id="filter-np" class="tbl-header-select" onchange="aplicarFiltrosTabla()" title="Filtrar por Número de Planilla">
            <option value="todos">NP ▾</option>
        </select>
    </th>
    <th style="text-align:center" title="Recibo de la factura">🖨</th>
    <th style="text-align:center">
        <input type="checkbox" id="chkAll" onchange="toggleAll(this)"
               title="Seleccionar todo lo facturable (no marca lo ya facturado)"
               style="width:1rem;height:1rem;cursor:pointer;vertical-align:middle;"> SEL
    </th>
</tr>
</thead>
<tbody>
@php
$totEps=$totArl=$totCaja=$totPen=$totAdmon=$totIva=$totTotal=$totMora=0;
$totAFavor=$totPendiente=0;

// Calcular fecha predeterminada (30 del mes anterior al período actual consultado)
$tmpFechaPred = \Carbon\Carbon::create((int)($anio ?? now()->year), (int)($mes ?? now()->month), 1)->subMonth();
$ultimoDiaPred = $tmpFechaPred->endOfMonth()->day;
$diaSugeridoPred = min(30, $ultimoDiaPred);
$fechaPredeterminada = \Carbon\Carbon::create((int)($anio ?? now()->year), (int)($mes ?? now()->month), 1)->subMonth()->day($diaSugeridoPred)->format('Y-m-d');
@endphp

@forelse($contratos as $c)
@php
// El cálculo de la fila vive en EmpresaPeriodoService::valoresFila(), que
// comparte con el portal de empresas. Llegan las mismas variables de siempre.
[
    'fact' => $fact,
    'factRetiroPreview' => $factRetiroPreview,
    'yaP' => $yaP,
    'nombre' => $nombre,
    'tipoMod' => $tipoMod,
    'tipoNom' => $tipoNom,
    'rs' => $rs,
    'esRetirado' => $esRetirado,
    'esIngRet' => $esIngRet,
    'fIng' => $fIng,
    'fRet' => $fRet,
    'tieneRetiroPendiente' => $tieneRetiroPendiente,
    'fechaRetiroPendienteStr' => $fechaRetiroPendienteStr,
    'diasRetiroPendiente' => $diasRetiroPendiente,
    'cobrarAdmonRetiroPendiente' => $cobrarAdmonRetiroPendiente,
    'dias' => $dias,
    'esIndep' => $esIndep,
    'esIndActPrimerMes' => $esIndActPrimerMes,
    'esArlModalidad' => $esArlModalidad,
    'esAfil' => $esAfil,
    'esIngresoFuturo' => $esIngresoFuturo,
    'fIngC' => $fIngC,
    'periodoIngresoVista' => $periodoIngresoVista,
    'periodoActualVista' => $periodoActualVista,
    'esTP' => $esTP,
    'diasTP' => $diasTP,
    'vEps' => $vEps,
    'vArl' => $vArl,
    'vCaja' => $vCaja,
    'vPen' => $vPen,
    'vAdm' => $vAdm,
    'vIva' => $vIva,
    'cotiz' => $cotiz,
    'vSS' => $vSS,
    'vAdmProporcional' => $vAdmProporcional,
    'vTot' => $vTot,
    'cotizRetPend' => $cotizRetPend,
    'vParaf' => $vParaf,
    'vMora' => $vMora,
    'vAfiliacion' => $vAfiliacion,
] = app(\App\Services\EmpresaPeriodoService::class)->valoresFila($c, (int) $mes, (int) $anio, $moraPorContrato);
$totEps+=$vEps;$totArl+=$vArl;$totCaja+=$vCaja;$totPen+=$vPen;
$totAdmon+=$vAdm;$totIva+=$vIva;$totTotal+=$vTot;$totMora+=$vMora;

$lblEstado = 'Sin factura';
// Retirado que ingresó este mismo mes: lo que se cobra es la afiliación, no el retiro.
// El retiro (SS proporcional) se cobra aparte con la factura 0, en el mes que corresponda.
$esAfilDeRetirado = $esRetirado && $esAfil;
if ($esAfilDeRetirado && !$fact) {
    $lblEstado = 'AFIL. PEND.';
} elseif ($esAfilDeRetirado && $fact && (int)$fact->numero_factura !== 0) {
    $lblEstado = $estadoLabel($fact->estado);
} elseif ($esRetirado) {
    $lblEstado = 'RETIRO';
} elseif ($tieneRetiroPendiente) {
    $lblEstado = 'Retiro Pd.';
} elseif ($fact) {
    if ((int)$fact->numero_factura === 0) {
        $lblEstado = 'RETIRO';
    } else {
        $lblEstado = $estadoLabel($fact->estado);
    }
}
@endphp
<tr class="{{ $yaP?'ya-pago':'' }}"
    data-estado="{{ $fact?->estado ?? 'sin_factura' }}"
    data-estado-label="{{ $lblEstado }}"
    data-cedula="{{ $c->cedula }}"
    data-contrato="{{ $c->id }}"
    data-dias="{{ $dias }}"
    data-veps="{{ $vEps }}" data-varl="{{ $vArl }}"
    data-vpen="{{ $vPen }}" data-vcaja="{{ $vCaja }}" data-vparaf="{{ $vParaf }}"
    data-vadmon="{{ $vAdm }}" data-viva="{{ $vIva }}"
    data-vtot="{{ $vTot }}"
    data-seguro="{{ (int)($c->seguro??0) }}"
    data-nombre="{{ $nombre }}"
    data-esafil="{{ $esAfil ? '1' : '0' }}"
    data-esindact="{{ $esIndActPrimerMes ? '1' : '0' }}"
    data-afiliacion="{{ $vAfiliacion }}"
    data-tipo="{{ ($esAfil && !$esIndActPrimerMes) ? 'afiliacion' : 'planilla' }}"
    data-tipomod="{{ $tipoMod }}"
    data-tipo_modalidad_id="{{ $c->tipo_modalidad_id }}"
    data-rs="{{ $rs }}"
    data-fecha_ingreso_retiro="{{ $esRetirado && $c->fecha_retiro ? $c->fecha_retiro->format('Y-m-d') : ($c->fecha_ingreso ? $c->fecha_ingreso->format('Y-m-d') : '') }}"
    data-tiene-retiro-pendiente="{{ $tieneRetiroPendiente ? '1' : '0' }}"
    data-fecha-retiro-pendiente="{{ $fechaRetiroPendienteStr }}"
    data-cobrar-admon-ret-pend="{{ $cobrarAdmonRetiroPendiente ? '1' : '0' }}"
    data-vmora="{{ $vMora }}"
    data-iva-pct="{{ ($c->tiene_iva ?? false) ? $ivaPct : 0 }}"
    data-np="{{ $fact?->np ?? $c->np ?? '' }}"
    data-es-retiro-facturable="{{ ($factRetiroPreview ?? null) && !$esAfil ? '1' : '0' }}"
    data-dias-retiro="{{ ($factRetiroPreview ?? null) && !$esAfil ? (int)$factRetiroPreview->dias_cotizados : 0 }}"
    data-admon-full="{{ ($factRetiroPreview ?? null) && !$esAfil ? (int)(($c->administracion??0) + ($c->admon_asesor??0)) : 0 }}"
    data-admon-proporcional="{{ ($factRetiroPreview ?? null) && !$esAfil ? ($vAdmProporcional ?? 0) : 0 }}"
    data-vss-retiro="{{ ($factRetiroPreview ?? null) && !$esAfil ? $r100($factRetiroPreview->total_ss) : 0 }}"
    data-np-prov="{{ $c->np ?? '' }}">

    <td style="font-size:.75rem;font-weight:700;text-align:center;white-space:nowrap;" title="{{ $tipoNom }}{{ $esIndActPrimerMes ? ' · Afiliación + Planilla' : '' }}{{ $esRetirado ? ' · RETIRADO' : '' }}">
        <span style="display:inline-flex;align-items:center;gap:3px;flex-direction:column;">
            <span style="display:inline-flex;align-items:center;gap:3px;">
                {{ $tipoMod }}
                <a href="{{ route('admin.contratos.edit', $c->id) }}?back={{ urlencode(url()->current()) }}"
                   title="Abrir contrato · {{ $tipoNom }}"
                   style="color:{{ $esIndActPrimerMes ? '#7c3aed' : ($esRetirado ? '#dc2626' : '#64748b') }};text-decoration:none;line-height:1;font-size:.85rem;"
                   onmouseover="this.style.color='#2563eb'" onmouseout="this.style.color='{{ $esIndActPrimerMes ? '#7c3aed' : ($esRetirado ? '#dc2626' : '#64748b') }}'">
                    @if($esIndActPrimerMes)&#9889;@elseif($esAfil)&#128204;@elseif($esRetirado)&#128683;@else&#9741;@endif
                </a>
            </span>
        </span>
    </td>
    <td style="font-family:monospace;font-size:.75rem;white-space:nowrap;">
        @if($c->cliente?->tipo_doc)<span style="color:#94a3b8;font-weight:700;">{{ $c->cliente->tipo_doc }}</span> @endif{{ $c->cedula }}
    </td>
    <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;font-weight:500">
        @if($c->cliente?->id)
        <a href="{{ route('admin.clientes.edit', $c->cliente->id) }}"
           style="color:#1d4ed8;text-decoration:none;font-weight:600;"
           title="Ver cliente"
           onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
            {{ $nombre }}
        </a>
        @else
            {{ $nombre }}
        @endif
    </td>
    <td style="font-size:.7rem;color:#64748b;max-width:105px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $rs }}">{{ Str::limit($rs,12) }}</td>
    <td style="text-align:center;font-size:.75rem;">
        @if($esRetirado && $fRet)
            <span style="color:#dc2626;font-weight:700;">{{ $fRet }}</span>
        @else
            <span style="color:#64748b;">{{ $fIng }}</span>
        @endif
    </td>
    <td style="text-align:center;font-weight:700;color:{{ ($tieneRetiroPendiente || $dias < 30) && $dias > 0 ? '#d97706' : ($dias===0?'#9333ea':'#0f172a') }};position:relative;">
        @if($esTP && $diasTP)
            <span title="T. Parcial: ARL {{ $diasTP['arl'] }}d · AFP {{ $diasTP['afp'] }}d · CAJA {{ $diasTP['caja'] }}d"
                  style="font-size:.62rem;background:#fef3c7;color:#78350f;border-radius:6px;padding:.1rem .35rem;font-weight:700;cursor:help;white-space:nowrap;">
              ⏱TP
            </span>
        @else
            {{ $dias === 0 ? '—' : $dias }}
        @endif
        @if(!$esRetirado && !$esAfil && !$esTP && !$yaP && !(isset($fact) && $fact))
            <button
                type="button"
                onclick="abrirModalRetiroPendiente({{ $c->id }}, '{{ addslashes($nombre) }}', '{{ addslashes($rs) }}', {{ (int)(($c->administracion??0)+($c->admon_asesor??0)) }}, '{{ $fechaRetiroPendienteStr }}', {{ $cobrarAdmonRetiroPendiente ? 'true' : 'false' }})"
                title="{{ $tieneRetiroPendiente ? 'Editar retiro pendiente ('.$diasRetiroPendiente.' días)' : 'Registrar fecha de retiro' }}"
                style="background:none;border:none;cursor:pointer;font-size:.8rem;padding:0 0 0 3px;line-height:1;color:{{ $tieneRetiroPendiente ? '#c2410c' : '#94a3b8' }};vertical-align:middle;">
                {{ $tieneRetiroPendiente ? '✏️' : '🗓' }}
            </button>
        @endif
    </td>
    <td class="num-col">{{ (!$esTP && $vEps>0)?'$'.number_format($vEps,0,',','.'):'—' }}</td>
    <td class="num-col">{{ $vArl>0?'$'.number_format($vArl,0,',','.'):'—' }}</td>
    <td class="num-col">{{ $vCaja>0?'$'.number_format($vCaja,0,',','.'):'—' }}</td>
    <td class="num-col">{{ $vPen>0?'$'.number_format($vPen,0,',','.'):'—' }}</td>
    <td class="num-col celda-admon">${{ number_format($vAdm,0,',','.') }}</td>
    <td class="num-col col-iva celda-iva" @if(!$mostrarIva) style="display:none" @endif>{{ $vIva>0?'$'.number_format($vIva,0,',','.'):'—' }}</td>
    <td class="num-col celda-tot" style="font-weight:700;color:{{ $yaP?'#16a34a':'#0f172a' }}">
        ${{ number_format($vTot,0,',','.') }}
        @if($vParaf > 0)
            <div style="font-weight:600;font-size:.62rem;color:#c2410c;margin-top:.1rem;"
                 title="SENA 2% + ICBF 3% — incluidos en el total (aportante no exonerado)">
                +${{ number_format($vParaf,0,',','.') }} paraf.
            </div>
        @endif
    </td>
    {{-- Mora: real si ya facturada, estimada si no. La columna solo existe si alguien tiene mora --}}
    @if($hayMora)
    <td class="num-col">
        @if($vMora > 0)
            <span style="display:inline-block;padding:.1rem .4rem;border-radius:20px;font-size:.62rem;font-weight:700;background:#fef3c7;color:#92400e;"
                  title="{{ $fact && ($fact->mora??0)>0 ? 'Mora cobrada en factura' : 'Mora estimada (aún sin facturar)' }}">
                ${{ number_format($vMora,0,',','.') }}
            </span>
        @else
            <span style="color:#cbd5e1;font-size:.7rem">—</span>
        @endif
    </td>
    @endif
    @if($hayAnticipos)
    @php
        $vAnticipo = $saldoAnticipoPorContrato->get($c->id, 0);
    @endphp
    <td class="num-col">
        @if($vAnticipo > 0)
            <span style="display:inline-block;padding:.1rem .4rem;border-radius:20px;font-size:.62rem;font-weight:700;background:#fef3c7;color:#b45309;"
                  title="Anticipo disponible asignado a este contrato">
                ${{ number_format($vAnticipo,0,',','.') }}
            </span>
        @else
            <span style="color:#cbd5e1;font-size:.7rem">—</span>
        @endif
    </td>
    @endif
    @php
        $totAFavor    += $c->saldo_a_favor;
        $totPendiente += $c->saldo_pendiente;
        $totProxFavor    = ($totProxFavor ?? 0)    + $c->saldo_proximo_favor;
        $totProxPendiente= ($totProxPendiente ?? 0) + $c->saldo_proximo_pendiente;
    @endphp
    <td style="text-align:center">
        @if($esAfilDeRetirado && !$fact)
            {{-- Retirado que ingresó este mes: afiliación pendiente de cobro --}}
            <span style="display:inline-block;padding:.16rem .5rem;border-radius:20px;font-size:.63rem;font-weight:800;background:#ede9fe;color:#6d28d9;"
                  title="Ingresó este mes — afiliación pendiente de facturar">
                AFIL. PEND.
            </span>
        @elseif($esAfilDeRetirado && $fact && (int)$fact->numero_factura !== 0)
            {{-- Afiliación del mes de ingreso ya facturada, aunque después se retirara --}}
            @php $coloresAfil = $estadoBg($fact->estado); @endphp
            <span style="display:inline-block;padding:.16rem .5rem;border-radius:20px;font-size:.63rem;font-weight:700;background:{{ $coloresAfil[0] }};color:{{ $coloresAfil[1] }}"
                  title="Afiliación del mes de ingreso — el retiro se cobra aparte">
                {{ $estadoLabel($fact->estado) }}
            </span>
        @elseif($esRetirado)
            @php
                $factRetiro0 = $c->factura_retiro_0 ?? null;
                $tieneRetiroFacturable = $c->tiene_retiro_facturable ?? false;
                $numeroPlanillaRet = $factRetiro0 ? (\App\Models\Plano::where('factura_id', $factRetiro0->id)->whereNull('deleted_at')->value('numero_planilla') ?? null) : null;
            @endphp
            @if($tieneRetiroFacturable)
                {{-- Retiro marcado pero no cobrado aún — mostrar en rojo --}}
                <span style="display:inline-block;padding:.16rem .5rem;border-radius:20px;font-size:.63rem;font-weight:800;background:#fee2e2;color:#dc2626;"
                      title="Retiro marcado — pendiente de facturar{{ $numeroPlanillaRet ? ' ⚠ Planilla pagada: '.$numeroPlanillaRet : '' }}">
                    RETIRO
                </span>
            @else
                {{-- Retirado sin factura 0 (ya cobrado o retiro masivo) --}}
                <span style="display:inline-block;padding:.16rem .5rem;border-radius:20px;font-size:.63rem;font-weight:800;background:#fee2e2;color:#dc2626">
                    RETIRO
                </span>
            @endif
        @elseif($tieneRetiroPendiente)
            {{-- Retiro pendiente: registrado desde vista empresa, aún no facturado --}}
            <span style="display:inline-block;padding:.16rem .5rem;border-radius:20px;font-size:.63rem;font-weight:800;background:#fff7ed;color:#b45309;border:1px solid #fcd34d;"
                  title="Retiro pendiente — {{ $diasRetiroPendiente }} días — {{ $cobrarAdmonRetiroPendiente ? 'Con admon' : 'Sin admon' }} — pendiente de facturar">
                Retiro Pd.
            </span>
        @elseif($fact)
        @php $colores=$estadoBg($fact->estado); @endphp
        @if((int)$fact->numero_factura === 0)
        <span style="display:inline-block;padding:.16rem .5rem;border-radius:20px;font-size:.63rem;font-weight:800;background:#fee2e2;color:#dc2626">
            RETIRO
        </span>
        @else
        <span style="display:inline-block;padding:.16rem .5rem;border-radius:20px;font-size:.63rem;font-weight:700;background:{{ $colores[0] }};color:{{ $colores[1] }}">
            {{ $estadoLabel($fact->estado) }}
        </span>
        @endif
        @else<span style="color:#94a3b8;font-size:.7rem">Sin factura</span>@endif

        {{-- El mes pasado no se le facturó. Va debajo del estado del mes en curso
             porque es otra cosa: aquí puede estar al día y arrastrar un mes sin
             cobrar, que mirando un solo período no se ve por ningún lado. --}}
        @if($c->falta_mes_anterior ?? false)
            <div style="margin-top:.18rem;font-size:.58rem;font-weight:700;color:#b45309;letter-spacing:.01em;"
                 title="No tiene factura de {{ $nombreMesAnterior }} {{ $anioMesAnterior }} — revisar si quedó sin cobrar">
                ⚠ {{ $nombreMesAnterior }} sin facturar
            </div>
        @endif
    </td>
    <td class="td-np" style="text-align:center;font-size:.8rem">
        @php
            $npMostrar = $fact?->np ?? $c->np ?? null;
            $npProvisional = !$fact && $c->np;
        @endphp
        @if($fact)
            {{-- Ya facturado: el NP viene de la factura, no se edita desde aquí --}}
            @if($npMostrar)
                <strong style="color:#2563eb;font-weight:800;" title="NP de la factura — no editable aquí">{{ $npMostrar }}</strong>
            @else
                <span style="color:#cbd5e1;font-size:.7rem">—</span>
            @endif
        @elseif($npMostrar)
            <span class="np-chip" onclick="npEditar(this)" title="Clic para cambiar el NP">{{ $npMostrar }}</span>
        @else
            <span class="np-vacio" onclick="npEditar(this)" title="Clic para asignar NP">—</span>
        @endif
    </td>
    <td style="text-align:center;white-space:nowrap;">
        @if($fact && (int)$fact->numero_factura !== 0)
            <button onclick="abrirRecibo('{{ route('admin.facturacion.recibo',$fact->id) }}?modal=1')"
               class="btn-sm" style="background:#eff6ff;color:#1d4ed8;" title="Ver recibo">🖨</button>
        @else
            <span style="color:#e2e8f0;">—</span>
        @endif
    </td>
    <td style="text-align:center;">
        @php
            // La casilla va en TODAS las filas, también en las ya facturadas o
            // pagadas: sirve para armar una cuenta de cobro con gente que ya
            // pagó. Lo que no se puede es volver a facturarlas, así que esas
            // filas llevan `data-fuera-lote` y quedan fuera de "seleccionar
            // todos" y del botón Facturar.
            //
            // El retiro se mira con el mismo criterio que el
            // `data-es-retiro-facturable` de la fila, y no con las variables que
            // arma la celda de ESTADO: esas solo se escriben en la rama del
            // retirado y, como el listado va en orden alfabético, se quedaban
            // pegadas de una fila a la siguiente — un activo que viniera después
            // de un retiro por cobrar salía pintado como retiro facturable.
            $selRetiroFacturable = ($factRetiroPreview ?? null) && ! $esAfil;
            $selDiasRetiro       = $selRetiroFacturable ? (int) $factRetiroPreview->dias_cotizados : 0;
            $selFueraLote        = (bool) $fact || ($esRetirado && ! $selRetiroFacturable);

            if ($fact) {
                $selColor = '#64748b';
                $selTitle = 'Ya facturado — se puede marcar para la cuenta de cobro, no para facturar';
            } elseif ($esAfilDeRetirado) {
                $selColor = '#6d28d9';
                $selTitle = 'Seleccionar para facturar la afiliación ('.'$'.number_format($vTot, 0, ',', '.').')';
            } elseif ($selRetiroFacturable) {
                // `$numeroPlanillaRet` lo dejó la celda de ESTADO en esta misma
                // fila: un retiro facturable siempre pasa por esa rama.
                $selColor = '#c2410c';
                $selTitle = 'Seleccionar para facturar retiro ('.$selDiasRetiro.' días)'
                    .(! empty($numeroPlanillaRet) ? ' ⚠ Ya tiene planilla: '.$numeroPlanillaRet : '');
            } elseif ($esRetirado) {
                $selColor = '#64748b';
                $selTitle = 'Retirado sin cobro este período — se puede marcar para la cuenta de cobro';
            } else {
                $selColor = '#2563eb';
                $selTitle = 'Seleccionar para facturar';
            }
        @endphp
        <input type="checkbox" class="chk-row" value="{{ $c->id }}"
               @if($selFueraLote) data-fuera-lote="1" @endif
               @if($selRetiroFacturable) data-es-retiro-facturable="1" data-dias-retiro="{{ $selDiasRetiro }}" @endif
               onchange="onCheckChange()"
               style="width:1.1rem;height:1.1rem;cursor:pointer;accent-color:{{ $selColor }};"
               title="{{ $selTitle }}">
    </td>
</tr>
@empty
<tr><td colspan="19" style="text-align:center;padding:2rem;color:#94a3b8">No hay contratos activos ni retiros del mes anterior para esta empresa en este período.</td></tr>
@endforelse
</tbody>
<tfoot>
<tr class="totales">
    <td colspan="6" style="padding:.5rem;font-size:.73rem;">TOTALES ({{ $contratos->count() }} contratos)</td>
    <td class="num-col tot-val">${{ number_format($totEps,  0,',','.') }}</td>
    <td class="num-col tot-val">${{ number_format($totArl,  0,',','.') }}</td>
    <td class="num-col tot-val">${{ number_format($totCaja, 0,',','.') }}</td>
    <td class="num-col tot-val">${{ number_format($totPen,  0,',','.') }}</td>
    <td class="num-col tot-val" id="tot-admon-val">${{ number_format($totAdmon,0,',','.') }}</td>
    <td class="num-col tot-val col-iva" id="tot-iva-val" @if(!$mostrarIva) style="display:none" @endif>${{ number_format($totIva,  0,',','.') }}</td>
    <td class="num-col tot-val" id="tot-general-val" style="font-size:.9rem">${{ number_format($totTotal,0,',','.') }}</td>
    @if($hayMora)
    <td class="num-col tot-val" style="color:#fbbf24;font-weight:800;">
        {{ $totMora > 0 ? '$'.number_format($totMora,0,',','.') : '—' }}
    </td>
    @endif
    {{-- Anticipo (si hay), recibo, estado, NP y selección --}}
    <td colspan="{{ $hayAnticipos ? 5 : 4 }}"></td>
</tr>
</tfoot>
</table>
</div>
</div>

{{-- ─── Checkbox: cobrar administración completa en retiros ──────────────────── --}}
<div id="panel-admon-retiro" style="display:none;align-items:center;gap:.65rem;margin-top:.5rem;padding:.5rem .9rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;width:fit-content;">
    <input type="checkbox" id="chk-admon-retiro" checked onchange="actualizarAdmonRetiro(this.checked)"
           style="width:1rem;height:1rem;cursor:pointer;accent-color:#ea580c;flex-shrink:0;">
    <label for="chk-admon-retiro" style="font-size:.73rem;font-weight:700;color:#c2410c;cursor:pointer;user-select:none;">
        💼 Cobrar administración en retiros cortos
    </label>
    <span style="font-size:.65rem;color:#9a3412;font-weight:500;">
        — Desmarcado: sin admon si ≤ 3 días (ya facturado antes de retirarse)
    </span>
</div>
<script>
function actualizarAdmonRetiro(admonCompleta) {
    document.querySelectorAll('tr[data-es-retiro-facturable="1"]').forEach(function(tr) {
        const admonFull = parseInt(tr.dataset.admonFull || 0);
        const diasRet   = parseInt(tr.dataset.diasRetiro || 0);
        const vss       = parseInt(tr.dataset.vssRetiro || 0);
        const ivaPct    = parseFloat(tr.dataset.ivaPct || 0);

        // Regla: marcado = admon completa siempre
        // Desmarcado: si días <= 3 → sin admon; si días > 3 → admon completa igualmente
        let nuevoAdmon;
        if (admonCompleta) {
            nuevoAdmon = admonFull;
        } else {
            nuevoAdmon = diasRet <= 3 ? 0 : admonFull;
        }

        // El IVA grava la admon que se termine cobrando: si no se cobra, no hay IVA
        const nuevoIva = Math.round(nuevoAdmon * ivaPct / 100);
        const nuevoTot = vss + nuevoAdmon + nuevoIva;
        tr.dataset.vadmon = nuevoAdmon;
        tr.dataset.viva   = nuevoIva;
        tr.dataset.vtot   = nuevoTot;

        const celdaAdmon = tr.querySelector('.celda-admon');
        if (celdaAdmon) celdaAdmon.textContent = '$' + nuevoAdmon.toLocaleString('es-CO');
        const celdaIva = tr.querySelector('.celda-iva');
        if (celdaIva) celdaIva.textContent = nuevoIva > 0 ? '$' + nuevoIva.toLocaleString('es-CO') : '—';
        const celdaTot = tr.querySelector('.celda-tot');
        if (celdaTot) celdaTot.textContent = '$' + nuevoTot.toLocaleString('es-CO');
    });

    // Recalcular dinámicamente la suma acumulada de administración y total general en el tfoot de la tabla
    let totalAdmonAcum = 0;
    let totalGeneralAcum = 0;
    let totalIvaAcum = 0;
    document.querySelectorAll('tbody tr[data-vadmon]').forEach(function(tr) {
        totalAdmonAcum += parseInt(tr.dataset.vadmon || 0);
        totalGeneralAcum += parseInt(tr.dataset.vtot || 0);
        totalIvaAcum += parseInt(tr.dataset.viva || 0);
    });

    const elTotAdmon = document.getElementById('tot-admon-val');
    if (elTotAdmon) elTotAdmon.textContent = '$' + totalAdmonAcum.toLocaleString('es-CO');
    const elTotIva = document.getElementById('tot-iva-val');
    if (elTotIva) elTotIva.textContent = '$' + totalIvaAcum.toLocaleString('es-CO');
    const elTotGen = document.getElementById('tot-general-val');
    if (elTotGen) elTotGen.textContent = '$' + totalGeneralAcum.toLocaleString('es-CO');
}

// Mostrar/ocultar el panel según si hay retiros seleccionados en la selección actual
function actualizarVisibilidadPanelAdmon() {
    const panel = document.getElementById('panel-admon-retiro');
    if (!panel) return;
    const chksRetiro = document.querySelectorAll('.chk-row:checked[data-es-retiro-facturable="1"]');
    panel.style.display = chksRetiro.length > 0 ? 'flex' : 'none';
}
// Inicializar visibilidad al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    actualizarVisibilidadPanelAdmon();
});
</script>

{{-- ─── Pie de la tabla: retirados a la izquierda, saldos a la derecha ───────
     Comparten renglón. El botón de retirados abre la gente que estuvo con la
     empresa y ya no aparece arriba: de solo consulta —sin casillas ni
     facturar— porque son retiros de meses viejos y marcarlos por error saldría
     caro. Una fila por persona: la de su último retiro, que la misma cédula
     puede haber entrado y salido varias veces.

     La lista sale en un modal (ver más abajo), no bajo la tabla: es larga y
     abajo empujaba las tarjetas de saldo fuera de la pantalla.
--}}
<div style="display:flex;align-items:flex-start;gap:.6rem;flex-wrap:wrap;margin-top:.55rem;">
@if($retiradosPreviosTotal > 0)
    <button type="button" onclick="abrirRetirados()" id="btn-retirados"
        style="background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.5rem .9rem;display:inline-flex;align-items:center;gap:.5rem;cursor:pointer;font-family:inherit;font-size:.75rem;font-weight:700;color:#475569;transition:background .15s,border-color .15s;"
        onmouseover="this.style.background='#f8fafc';this.style.borderColor='#cbd5e1';"
        onmouseout="this.style.background='#fff';this.style.borderColor='#e2e8f0';">
        &#128683; Retirados de la empresa
        <span style="background:#f1f5f9;color:#64748b;border-radius:999px;padding:.1rem .45rem;font-size:.7rem;font-weight:800;">{{ $retiradosPreviosTotal }}</span>
        <span style="color:#94a3b8;font-size:.9rem;">&rsaquo;</span>
    </button>
@endif

{{-- ─── Panel saldo neto de la EMPRESA (calculado en el controlador) ─────────
     Usa empresa_id: suma TODOS los saldo_proximo hasta e incluyendo el mes actual.
     Abril: +700k  |  Mayo: +700k - 700k = 0  |  Junio: correcto
--}}
@if($saldoEmpresaFavor > 0 || $saldoEmpresaPendiente > 0 || $totalAnticipoDisponible > 0)
<div style="display:flex;justify-content:flex-end;gap:.6rem;flex-wrap:wrap;margin-left:auto;">

    @if($saldoEmpresaFavor > 0)
    <button type="button" onclick="abrirDetalleSaldoEmpresa()" title="Ver de qué facturas viene este saldo"
        style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:.55rem .9rem;display:flex;align-items:center;gap:.5rem;min-width:210px;cursor:pointer;font-family:inherit;text-align:left;transition:background .15s,border-color .15s;"
        onmouseover="this.style.background='#dcfce7';this.style.borderColor='#22c55e';"
        onmouseout="this.style.background='#f0fdf4';this.style.borderColor='#86efac';">
        <span style="font-size:1.2rem;">✅</span>
        <div style="flex:1;">
            <div style="font-size:.6rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.04em;">Saldo a favor empresa</div>
            <div style="font-size:.95rem;font-weight:800;color:#15803d;">+${{ number_format($saldoEmpresaFavor,0,',','.') }}</div>
            <div style="font-size:.58rem;color:#4ade80;">
                {{ $facturasSaldoEmpresa->count() }} {{ $facturasSaldoEmpresa->count() === 1 ? 'factura' : 'facturas' }} · ver detalle
            </div>
        </div>
        <span style="font-size:.9rem;color:#16a34a;">›</span>
    </button>
    @endif

    @if($saldoEmpresaPendiente > 0)
    <button type="button" onclick="abrirDetalleSaldoEmpresa()" title="Ver en qué facturas quedó debiendo"
        style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:.55rem .9rem;display:flex;align-items:center;gap:.5rem;min-width:210px;cursor:pointer;font-family:inherit;text-align:left;transition:background .15s,border-color .15s;"
        onmouseover="this.style.background='#fee2e2';this.style.borderColor='#ef4444';"
        onmouseout="this.style.background='#fef2f2';this.style.borderColor='#fca5a5';">
        <span style="font-size:1.2rem;">⚠️</span>
        <div style="flex:1;">
            <div style="font-size:.6rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.04em;">Pendiente empresa</div>
            <div style="font-size:.95rem;font-weight:800;color:#dc2626;">-${{ number_format($saldoEmpresaPendiente,0,',','.') }}</div>
            <div style="font-size:.58rem;color:#fca5a5;">
                {{ $facturasSaldoEmpresa->count() }} {{ $facturasSaldoEmpresa->count() === 1 ? 'factura' : 'facturas' }} · ver detalle
            </div>
        </div>
        <span style="font-size:.9rem;color:#dc2626;">›</span>
    </button>
    @endif

    {{-- ─── Panel anticipo disponible: resumen; el detalle va en el modal ── --}}
    @if($totalAnticipoDisponible > 0)
    <button type="button" onclick="abrirDetalleAnticipos()" title="Ver el detalle de los anticipos"
        style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:.55rem .9rem;display:flex;align-items:center;gap:.5rem;min-width:210px;cursor:pointer;font-family:inherit;text-align:left;transition:background .15s,border-color .15s;"
        onmouseover="this.style.background='#fef3c7';this.style.borderColor='#f59e0b';"
        onmouseout="this.style.background='#fffbeb';this.style.borderColor='#fde68a';">
        <span style="font-size:1.2rem;">🟡</span>
        <div style="flex:1;">
            <div style="font-size:.6rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;">Anticipo disponible</div>
            <div style="font-size:.95rem;font-weight:800;color:#92400e;">${{ number_format($totalAnticipoDisponible,0,',','.') }}</div>
            <div style="font-size:.58rem;color:#b45309;">
                {{ $anticiposEmpresa->count() }} {{ $anticiposEmpresa->count() === 1 ? 'anticipo' : 'anticipos' }} · ver detalle
            </div>
        </div>
        <span style="font-size:.9rem;color:#d97706;">›</span>
    </button>
    @endif

</div>
@endif
</div>

{{-- ═══ MODAL: RETIRADOS DE LA EMPRESA ══════════════════════════════
     En modal y no desplegado bajo la tabla: la lista es larga y abajo
     empujaba todo lo demás fuera de la pantalla. Mismo patrón que el
     detalle de saldo (overlay, Escape y clic afuera para cerrar).

     Las filas se piden al abrirlo, no al cargar la página: hay empresas con
     casi mil retirados —dos segundos de consulta y otro tanto de HTML— que
     casi nadie abre. Del servidor solo baja el número para el botón.
--}}
@if($retiradosPreviosTotal > 0)
<div id="ret-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:2100;align-items:center;justify-content:center;padding:.75rem;"
     onclick="if(event.target.id==='ret-overlay') cerrarRetirados()">
    <div style="background:#fff;border-radius:18px;width:min(820px,98vw);max-height:92vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.35);">

        <div style="background:linear-gradient(135deg,#334155 0%,#64748b 100%);padding:.9rem 1.3rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.8rem;">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">&#128683;</div>
                <div>
                    <h2 style="font-size:.95rem;font-weight:800;color:#fff;margin:0;">Retirados de la empresa</h2>
                    <p style="font-size:.68rem;color:rgba(255,255,255,.8);margin:0;">{{ $empresa->empresa }} · {{ $retiradosPreviosTotal }} {{ $retiradosPreviosTotal === 1 ? 'persona' : 'personas' }}</p>
                </div>
            </div>
            <button type="button" onclick="cerrarRetirados()"
                style="width:28px;height:28px;border-radius:7px;border:none;cursor:pointer;background:rgba(255,255,255,.1);color:rgba(255,255,255,.85);font-size:.95rem;">&times;</button>
        </div>

        <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:.55rem 1.3rem;font-size:.7rem;color:#64748b;flex-shrink:0;">
            Estuvieron con la empresa y no aparecen en {{ $meses[$mes] }} de {{ $anio }}.
            De cada persona se muestra su último contrato retirado.
        </div>

        <div style="flex:1;overflow-y:auto;min-height:0;">
            <div id="ret-aviso" style="padding:1.6rem;text-align:center;color:#64748b;font-size:.78rem;">Cargando…</div>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:.5rem 1.3rem;font-size:.6rem;color:#475569;font-weight:800;text-transform:uppercase;background:#fff;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;">Documento</th>
                        <th style="text-align:left;padding:.5rem .6rem;font-size:.6rem;color:#475569;font-weight:800;text-transform:uppercase;background:#fff;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;">Nombre</th>
                        <th style="text-align:left;padding:.5rem .6rem;font-size:.6rem;color:#475569;font-weight:800;text-transform:uppercase;background:#fff;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;">Razón social</th>
                        <th style="text-align:center;padding:.5rem .6rem;font-size:.6rem;color:#475569;font-weight:800;text-transform:uppercase;background:#fff;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;">Ingreso</th>
                        <th style="text-align:center;padding:.5rem 1.3rem;font-size:.6rem;color:#475569;font-weight:800;text-transform:uppercase;background:#fff;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;">Retiro</th>
                    </tr>
                </thead>
                <tbody id="ret-tbody"></tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    const URL_RETIRADOS = "{{ route('admin.facturacion.empresa.retirados', $empresa->id) }}?mes={{ $mes }}&anio={{ $anio }}";
    let cargados = false;

    // Las celdas se arman con textContent y no con innerHTML: los nombres y las
    // razones sociales los escribe el usuario y no tienen por qué ser HTML.
    function celda(texto, estilo) {
        const td = document.createElement('td');
        td.style.cssText = estilo;
        td.textContent = texto || '—';
        return td;
    }

    function fila(p) {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f1f5f9';

        const doc = celda('', 'font-family:monospace;font-size:.73rem;padding:.4rem 1.3rem;white-space:nowrap;');
        if (p.tipo_doc) {
            const t = document.createElement('span');
            t.style.cssText = 'color:#94a3b8;font-weight:700;';
            t.textContent = p.tipo_doc + ' ';
            doc.appendChild(t);
        }
        doc.appendChild(document.createTextNode(p.cedula ?? ''));
        tr.appendChild(doc);

        const tdNom = celda('', 'font-size:.75rem;padding:.4rem .6rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;');
        if (p.cliente_url) {
            const a = document.createElement('a');
            a.href = p.cliente_url;
            a.title = 'Ver cliente';
            a.style.cssText = 'color:#1d4ed8;text-decoration:none;font-weight:600;';
            a.textContent = nombreOracion(p.nombre);
            a.onmouseover = () => a.style.textDecoration = 'underline';
            a.onmouseout  = () => a.style.textDecoration = 'none';
            tdNom.appendChild(a);
        } else {
            tdNom.textContent = nombreOracion(p.nombre);
        }
        tr.appendChild(tdNom);

        const rs = celda(p.razon_social, 'font-size:.7rem;color:#64748b;padding:.4rem .6rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;');
        rs.title = p.razon_social || '—';
        tr.appendChild(rs);

        tr.appendChild(celda(p.ingreso, 'font-size:.72rem;color:#475569;padding:.4rem .6rem;text-align:center;white-space:nowrap;'));
        tr.appendChild(celda(p.retiro,  'font-size:.72rem;color:#b91c1c;font-weight:600;padding:.4rem 1.3rem;text-align:center;white-space:nowrap;'));
        return tr;
    }

    function cargar() {
        const aviso = document.getElementById('ret-aviso');
        const tbody = document.getElementById('ret-tbody');
        aviso.textContent = 'Cargando…';
        aviso.style.display = '';

        fetch(URL_RETIRADOS, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
            .then(d => {
                tbody.textContent = '';
                (d.retirados || []).forEach(p => tbody.appendChild(fila(p)));
                aviso.style.display = 'none';
                cargados = true;
            })
            .catch(e => {
                // Sin cargados=true: al volver a abrir se reintenta.
                aviso.textContent = 'No se pudo cargar la lista (' + e.message + '). Cierra y vuelve a abrir para reintentar.';
            });
    }

    window.abrirRetirados = function () {
        document.getElementById('ret-overlay').style.display = 'flex';
        if (!cargados) cargar();
    };
    window.cerrarRetirados = function () {
        document.getElementById('ret-overlay').style.display = 'none';
    };
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const ov = document.getElementById('ret-overlay');
        if (ov && ov.style.display === 'flex') cerrarRetirados();
    });
})();
</script>
@endif

{{-- ═══ MODAL DETALLE DEL SALDO DE LA EMPRESA ══════════════════════
     El saldo que se muestra en la tarjeta es el NETO de saldo_proximo de
     todas las facturas de la empresa. Aquí se ve factura por factura de
     dónde sale, porque unas pueden quedar a favor y otras debiendo.
--}}
@if($saldoEmpresaFavor > 0 || $saldoEmpresaPendiente > 0)
@php
    $seNeto      = $saldoEmpresaFavor > 0 ? $saldoEmpresaFavor : -$saldoEmpresaPendiente;
    $seAFavor    = $seNeto > 0;
    $seColor     = $seAFavor ? '#15803d' : '#dc2626';
    $seFondo     = $seAFavor ? '#f0fdf4' : '#fef2f2';
    $seBorde     = $seAFavor ? '#86efac' : '#fca5a5';
    $seDegradado = $seAFavor ? 'linear-gradient(135deg,#15803d 0%,#22c55e 100%)' : 'linear-gradient(135deg,#b91c1c 0%,#ef4444 100%)';
    $seMeses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
                7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
@endphp
<div id="se-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:2100;align-items:center;justify-content:center;padding:.75rem;"
     onclick="if(event.target.id==='se-overlay') cerrarDetalleSaldoEmpresa()">
    <div style="background:#fff;border-radius:18px;width:min(760px,98vw);max-height:92vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.35);">

        <div style="background:{{ $seDegradado }};padding:.9rem 1.3rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.8rem;">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">{{ $seAFavor ? '✅' : '⚠️' }}</div>
                <div>
                    <h2 style="font-size:.95rem;font-weight:800;color:#fff;margin:0;">
                        {{ $seAFavor ? 'Saldo a favor de la empresa' : 'Pendiente de la empresa' }}
                    </h2>
                    <p style="font-size:.68rem;color:rgba(255,255,255,.8);margin:0;">{{ $empresa->empresa }} · de dónde viene el saldo</p>
                </div>
            </div>
            <button type="button" onclick="cerrarDetalleSaldoEmpresa()"
                style="width:28px;height:28px;border-radius:7px;border:none;cursor:pointer;background:rgba(255,255,255,.1);color:rgba(255,255,255,.85);font-size:.95rem;">&times;</button>
        </div>

        <div style="background:{{ $seFondo }};border-bottom:1px solid {{ $seBorde }};padding:.6rem 1.3rem;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
            <div>
                <div style="font-size:.58rem;font-weight:800;color:{{ $seColor }};text-transform:uppercase;letter-spacing:.04em;">Saldo neto</div>
                <div style="font-size:1.05rem;font-weight:900;color:{{ $seColor }};">
                    {{ $seAFavor ? '+' : '-' }}${{ number_format(abs($seNeto),0,',','.') }}
                </div>
                <div style="font-size:.58rem;color:{{ $seColor }};opacity:.75;">
                    {{ $seAFavor ? 'Se descuenta automáticamente al facturar' : 'Se suma al total al facturar' }}
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.58rem;font-weight:800;color:{{ $seColor }};text-transform:uppercase;letter-spacing:.04em;">Facturas</div>
                <div style="font-size:1.05rem;font-weight:900;color:{{ $seColor }};">{{ $facturasSaldoEmpresa->count() }}</div>
            </div>
        </div>

        <div style="flex:1;overflow-y:auto;padding:.5rem 1rem 1rem;min-height:0;">
            @if($facturasSaldoEmpresa->isEmpty())
                <div style="padding:1.5rem;text-align:center;color:#64748b;font-size:.78rem;">
                    No hay facturas con saldo pendiente ni a favor.
                </div>
            @else
            <table style="width:100%;border-collapse:collapse;font-size:.75rem;">
                <thead>
                    <tr>
                        @foreach(['Factura','Cliente','Período','Estado'] as $th)
                        <th style="text-align:left;background:#f8fafc;color:#475569;padding:.5rem .6rem;font-size:.6rem;font-weight:800;text-transform:uppercase;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;">{{ $th }}</th>
                        @endforeach
                        <th style="text-align:right;background:#f8fafc;color:#475569;padding:.5rem .6rem;font-size:.6rem;font-weight:800;text-transform:uppercase;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;">Saldo</th>
                        <th style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0;position:sticky;top:0;z-index:5;width:70px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($facturasSaldoEmpresa as $f)
                    @php
                        $fSaldo   = (int) $f->saldo_proximo;
                        $fAFavor  = $fSaldo > 0;
                    @endphp
                    <tr>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;font-family:monospace;color:#334155;font-weight:700;">
                            {{ $f->numero_factura ?: '—' }}
                            <div style="font-size:.58rem;color:#94a3b8;font-family:inherit;font-weight:500;">
                                {{ $f->fecha_pago ? $f->fecha_pago->format('d/m/Y') : 'sin fecha' }}
                            </div>
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;color:#1e293b;font-weight:700;">
                            {{ nombre_oracion($f->contrato?->cliente?->nombre_completo ?? '—') }}
                            <div style="font-size:.62rem;color:#64748b;font-weight:500;">{{ $f->cedula }}</div>
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;color:#475569;white-space:nowrap;">
                            {{ $seMeses[(int) $f->mes] ?? $f->mes }} {{ $f->anio }}
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;color:#475569;">
                            {{ ucfirst($f->estado) }}
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-family:monospace;font-weight:800;white-space:nowrap;color:{{ $fAFavor ? '#15803d' : '#dc2626' }};">
                            {{ $fAFavor ? '+' : '-' }}${{ number_format(abs($fSaldo),0,',','.') }}
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;text-align:center;">
                            <button type="button" onclick="abrirRecibo('{{ route('admin.facturacion.recibo', $f->id) }}?modal=1')"
                                style="background:#fff;border:1.5px solid #cbd5e1;color:#475569;border-radius:6px;padding:.2rem .45rem;font-size:.62rem;font-weight:700;cursor:pointer;font-family:inherit;">Recibo</button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>

        <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:.7rem 1.3rem;display:flex;justify-content:flex-end;flex-shrink:0;">
            <button type="button" onclick="cerrarDetalleSaldoEmpresa()"
                style="padding:.42rem 1.1rem;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;border:none;background:#e2e8f0;color:#334155;font-family:inherit;">Cerrar</button>
        </div>
    </div>
</div>

<script>
function abrirDetalleSaldoEmpresa() {
    document.getElementById('se-overlay').style.display = 'flex';
}
function cerrarDetalleSaldoEmpresa() {
    document.getElementById('se-overlay').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    const ov = document.getElementById('se-overlay');
    if (ov && ov.style.display === 'flex') cerrarDetalleSaldoEmpresa();
});
</script>
@endif

{{-- ═══ MODAL DETALLE DE ANTICIPOS DISPONIBLES ═════════════════════ --}}
@if($totalAnticipoDisponible > 0)
<div id="da-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:2100;align-items:center;justify-content:center;padding:.75rem;"
     onclick="if(event.target.id==='da-overlay') cerrarDetalleAnticipos()">
    <div style="background:#fff;border-radius:18px;width:min(720px,98vw);max-height:92vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.35);">

        <div style="background:linear-gradient(135deg,#b45309 0%,#d97706 100%);padding:.9rem 1.3rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.8rem;">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">🟡</div>
                <div>
                    <h2 style="font-size:.95rem;font-weight:800;color:#fff;margin:0;">Anticipos disponibles</h2>
                    <p style="font-size:.68rem;color:rgba(255,255,255,.75);margin:0;">{{ $empresa->empresa }} · se pueden aplicar al facturar este mes</p>
                </div>
            </div>
            <button type="button" onclick="cerrarDetalleAnticipos()"
                style="width:28px;height:28px;border-radius:7px;border:none;cursor:pointer;background:rgba(255,255,255,.1);color:rgba(255,255,255,.85);font-size:.95rem;">&times;</button>
        </div>

        <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:.6rem 1.3rem;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
            <div>
                <div style="font-size:.58rem;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.04em;">Total disponible</div>
                <div style="font-size:1.05rem;font-weight:900;color:#92400e;">${{ number_format($totalAnticipoDisponible,0,',','.') }}</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.58rem;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.04em;">Cantidad</div>
                <div style="font-size:1.05rem;font-weight:900;color:#92400e;">{{ $anticiposEmpresa->count() }}</div>
            </div>
        </div>

        <div style="flex:1;overflow-y:auto;padding:.5rem 1rem 1rem;min-height:0;">
            <table style="width:100%;border-collapse:collapse;font-size:.75rem;">
                <thead>
                    <tr>
                        <th style="text-align:left;background:#fffbeb;color:#92400e;padding:.5rem .6rem;font-size:.6rem;font-weight:800;text-transform:uppercase;border-bottom:1.5px solid #fde68a;position:sticky;top:0;z-index:5;">Cliente</th>
                        <th style="text-align:left;background:#fffbeb;color:#92400e;padding:.5rem .6rem;font-size:.6rem;font-weight:800;text-transform:uppercase;border-bottom:1.5px solid #fde68a;position:sticky;top:0;z-index:5;">Pago</th>
                        <th style="text-align:right;background:#fffbeb;color:#92400e;padding:.5rem .6rem;font-size:.6rem;font-weight:800;text-transform:uppercase;border-bottom:1.5px solid #fde68a;position:sticky;top:0;z-index:5;">Disponible</th>
                        <th style="background:#fffbeb;border-bottom:1.5px solid #fde68a;position:sticky;top:0;z-index:5;width:70px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($anticiposEmpresa as $ant)
                    <tr>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #fef3c7;color:#1e293b;font-weight:700;">
                            @if($ant->contrato?->cliente)
                                {{ nombre_oracion($ant->contrato->cliente->nombre_completo) }}
                                <div style="font-size:.62rem;color:#64748b;font-weight:500;">{{ $ant->cedula }}</div>
                            @else
                                <span style="color:#b45309;">🏢 Abono libre de empresa</span>
                            @endif
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #fef3c7;color:#475569;">
                            {{ ucfirst($ant->forma_pago) }} · {{ $ant->fecha_pago->format('d/m/Y') }}
                            @if($ant->estado === 'parcial')
                                <span style="background:#fed7aa;color:#c2410c;border-radius:4px;padding:.05rem .25rem;font-size:.55rem;font-weight:700;">Parcial</span>
                            @endif
                            @if($ant->valor_disponible != $ant->valor)
                                <div style="font-size:.6rem;color:#94a3b8;">de ${{ number_format($ant->valor,0,',','.') }}</div>
                            @endif
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #fef3c7;text-align:right;font-family:monospace;font-weight:800;color:#92400e;white-space:nowrap;">
                            ${{ number_format($ant->valor_disponible,0,',','.') }}
                        </td>
                        <td style="padding:.4rem .6rem;border-bottom:1px solid #fef3c7;text-align:center;">
                            <button type="button" onclick="abrirRecibo('/admin/anticipos/{{ $ant->id }}/recibo?modal=1')"
                                style="background:#fff;border:1.5px solid #fcd34d;color:#92400e;border-radius:6px;padding:.2rem .45rem;font-size:.62rem;font-weight:700;cursor:pointer;font-family:inherit;">Recibo</button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:.7rem 1.3rem;display:flex;justify-content:flex-end;flex-shrink:0;">
            <button type="button" onclick="cerrarDetalleAnticipos()"
                style="padding:.42rem 1.1rem;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;border:none;background:#e2e8f0;color:#334155;font-family:inherit;">Cerrar</button>
        </div>
    </div>
</div>

<script>
function abrirDetalleAnticipos() {
    document.getElementById('da-overlay').style.display = 'flex';
}
function cerrarDetalleAnticipos() {
    document.getElementById('da-overlay').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    const ov = document.getElementById('da-overlay');
    if (ov && ov.style.display === 'flex') cerrarDetalleAnticipos();
});
</script>
@endif

{{-- ═══ MODAL CARGA MASIVA CÉDULAS (NP PROVISIONAL) ════════════════ --}}
<style>
#modalCargaCedulas .cc-modal-inner {
    background: #fff;
    border-radius: 16px;
    width: min(640px, 96vw);
    max-height: 92vh;
    overflow-y: auto;
    box-shadow: 0 24px 60px rgba(0,0,0,.22);
    display: flex;
    flex-direction: column;
}
#modalCargaCedulas .cc-header {
    background: linear-gradient(135deg, #0f172a, #134e4a);
    border-radius: 16px 16px 0 0;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
#modalCargaCedulas .cc-header-title {
    color: #fff;
    font-size: .95rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: .5rem;
}
#modalCargaCedulas .cc-header-sub {
    color: #5eead4;
    font-size: .68rem;
    margin-top: .15rem;
    font-weight: 500;
}
#modalCargaCedulas .cc-close {
    background: rgba(255,255,255,.1);
    border: none;
    border-radius: 8px;
    color: #cbd5e1;
    font-size: 1.1rem;
    width: 30px; height: 30px;
    cursor: pointer;
    transition: background .15s;
    display: flex; align-items: center; justify-content: center;
}
#modalCargaCedulas .cc-close:hover { background: rgba(239,68,68,.35); color: #fff; }
#modalCargaCedulas .cc-body { padding: 1.1rem 1.25rem; }
#modalCargaCedulas .cc-label {
    display: block;
    font-size: .63rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: .25rem;
}
#modalCargaCedulas .cc-select,
#modalCargaCedulas .cc-textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    padding: .4rem .55rem;
    font-size: .82rem;
    color: #1e293b;
    transition: border-color .15s;
    outline: none;
}
#modalCargaCedulas .cc-select:focus,
#modalCargaCedulas .cc-textarea:focus { border-color: #0d9488; }
#modalCargaCedulas .cc-textarea {
    font-family: monospace;
    line-height: 1.6;
    resize: vertical;
}
#modalCargaCedulas .cc-footer {
    padding: .85rem 1.25rem;
    border-top: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .5rem;
    background: #f8fafc;
    border-radius: 0 0 16px 16px;
}
#modalCargaCedulas .cc-btn-prim {
    padding: .45rem 1.3rem;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 700;
    font-size: .82rem;
    transition: opacity .15s;
}
#modalCargaCedulas .cc-btn-prim:hover { opacity: .88; }
#modalCargaCedulas .cc-btn-prim:disabled { opacity: .45; cursor: not-allowed; }
#modalCargaCedulas .cc-btn-sec {
    padding: .4rem .9rem;
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    background: #fff;
    color: #475569;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
#modalCargaCedulas .cc-btn-sec:hover { border-color: #94a3b8; background: #f8fafc; }
#modalCargaCedulas .cc-chip-group {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: .5rem;
    margin-bottom: .8rem;
}
#modalCargaCedulas .cc-chip {
    border-radius: 10px;
    padding: .55rem .4rem;
    text-align: center;
    border: 1px solid;
}
#modalCargaCedulas .cc-chip .cc-num { font-size: 1.5rem; font-weight: 900; line-height: 1; }
#modalCargaCedulas .cc-chip .cc-lab { font-size: .6rem; font-weight: 700; margin-top: .15rem; }
#modalCargaCedulas .cc-copybox { border-radius: 8px; overflow: hidden; margin-bottom: .6rem; }
#modalCargaCedulas .cc-copybox-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .35rem .6rem;
    font-size: .65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
}
#modalCargaCedulas .cc-copybox textarea {
    width: 100%;
    box-sizing: border-box;
    border: none;
    padding: .45rem .6rem;
    font-family: monospace;
    font-size: .73rem;
    resize: none;
    outline: none;
}
#modalCargaCedulas .cc-copy-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: .75rem;
    padding: 0;
    opacity: .7;
    transition: opacity .15s;
}
#modalCargaCedulas .cc-copy-btn:hover { opacity: 1; }
</style>

<div id="modalCargaCedulas" class="modal-overlay" style="display:none;" onclick="cerrarSi(event,'modalCargaCedulas')">
<div class="cc-modal-inner" onclick="event.stopPropagation()">

    {{-- Header --}}
    <div class="cc-header">
        <div>
            <div class="cc-header-title">📋 Cargar Lista de Cédulas</div>
            <div class="cc-header-sub">Asignar NP provisional · {{ $meses[$mes-1] }} {{ $anio }}</div>
        </div>
        <button class="cc-close" onclick="cerrar('modalCargaCedulas')">&times;</button>
    </div>

    {{-- Paso 1: Entrada --}}
    <div id="cc-paso1" class="cc-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-bottom:.8rem;">
            <div>
                <label class="cc-label">NP Provisional (1–5)</label>
                <select id="cc-np" class="cc-select" style="font-size:.9rem;font-weight:700;text-align:center;">
                    <option value="1">NP 1</option>
                    <option value="2">NP 2</option>
                    <option value="3">NP 3</option>
                    <option value="4">NP 4</option>
                    <option value="5">NP 5</option>
                </select>
            </div>
            <div>
                <label class="cc-label">Período activo</label>
                <div style="padding:.4rem .55rem;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;font-size:.88rem;font-weight:700;color:#15803d;text-align:center;">
                    {{ $meses[$mes-1] }} {{ $anio }}
                </div>
            </div>
        </div>

        <div style="margin-bottom:.8rem;">
            <label class="cc-label">Cédulas (una por línea, coma o espacio)</label>
            <textarea id="cc-cedulas" class="cc-textarea" rows="9"
                placeholder="1000000001&#10;1000000002&#10;1000000003..."></textarea>
            <div style="margin-top:.3rem;font-size:.63rem;color:#94a3b8;">Separa por salto de línea, coma, punto y coma o espacio. Los dúpicados se eliminan automáticamente.</div>
        </div>

        <div class="cc-footer" style="margin:-1.1rem -1.25rem -1.1rem;flex-wrap:wrap;gap:.4rem;">
            <button class="cc-btn-sec" onclick="document.getElementById('cc-cedulas').value=''">🗑 Limpiar Texto</button>
            <div style="display:flex;gap:.45rem;">
                <button id="cc-btn-reset-todos" class="cc-btn-sec"
                    style="color:#dc2626;border-color:#fca5a5;"
                    onclick="ccResetearNPsEmpresa('cc-btn-reset-todos')" title="Borrar el NP provisional de TODOS los contratos activos de esta empresa en la base de datos">
                    🗑 Resetear NPs Empresa
                </button>
                <button id="cc-btn-verificar" class="cc-btn-prim"
                    style="background:linear-gradient(135deg,#0f766e,#0d9488);color:#fff;"
                    onclick="ccVerificar()">
                    🔍 Verificar →
                </button>
            </div>
        </div>
    </div>

    {{-- Paso 2: Resultado --}}
    <div id="cc-paso2" class="cc-body" style="display:none;">

        {{-- Chips resumen --}}
        <div id="cc-resumen" class="cc-chip-group"></div>

        {{-- Copybox: No encontradas --}}
        <div id="cc-wrap-no-enc" class="cc-copybox" style="display:none;border:1px solid #fca5a5;">
            <div class="cc-copybox-head" style="background:#fef2f2;color:#dc2626;">
                <span>❌ No encontradas</span>
                <button class="cc-copy-btn" onclick="ccCopiar('cc-txt-no-enc')" title="Copiar">📋 Copiar</button>
            </div>
            <textarea id="cc-txt-no-enc" rows="3" readonly
                style="background:#fef2f2;color:#dc2626;"></textarea>
        </div>

        {{-- Copybox: Ya facturadas --}}
        <div id="cc-wrap-ya-fac" class="cc-copybox" style="display:none;border:1px solid #fde68a;">
            <div class="cc-copybox-head" style="background:#fffbeb;color:#d97706;">
                <span>⚠️ Ya tienen factura este mes</span>
                <button class="cc-copy-btn" onclick="ccCopiar('cc-txt-ya-fac')" title="Copiar">📋 Copiar</button>
            </div>
            <textarea id="cc-txt-ya-fac" rows="3" readonly
                style="background:#fffbeb;color:#d97706;"></textarea>
        </div>

        <div class="cc-footer" style="margin:-1.1rem -1.25rem -1.1rem;flex-wrap:wrap;gap:.4rem;">
            <button class="cc-btn-sec" onclick="ccVolver()">← Volver</button>
            <div style="display:flex;gap:.45rem;">
                <button id="cc-btn-limpiar-np" class="cc-btn-sec"
                    style="color:#dc2626;border-color:#fca5a5;"
                    onclick="ccResetearNPsEmpresa('cc-btn-limpiar-np')" title="Borrar el NP provisional de TODOS los contratos activos de esta empresa en la base de datos">
                    🗑 Resetear NPs Empresa
                </button>
                <button id="cc-btn-asignar" class="cc-btn-prim"
                    style="background:linear-gradient(135deg,#0f766e,#0d9488);color:#fff;"
                    onclick="ccAsignar()">
                    ✅ Asignar NP y Seleccionar
                </button>
            </div>
        </div>
    </div>

</div>
</div>

{{-- ═══ MODAL FACTURAR UNIFICADO ══════════════════════════════════ --}}
@php $mfMes = $mes; $mfAnio = $anio; @endphp
@include('admin.facturacion._modal_facturar', ['bancos' => $bancos, 'mfMes' => $mfMes, 'mfAnio' => $mfAnio])

{{-- ═══ MODAL OTRO INGRESO ═════════════════════════════════════════ --}}
@php $oiMes = $mes; $oiAnio = $anio; $oiEmpresaId = $empresa->id; @endphp
@include('admin.facturacion._modal_otro_ingreso', [
    'bancos' => $bancos, 'oiMes' => $oiMes, 'oiAnio' => $oiAnio, 'oiEmpresaId' => $oiEmpresaId
])

{{-- ═══ MODAL ANTICIPO DISTRIBUIDO ═════════════════════════════════ --}}
@include('admin.facturacion._modal_anticipo_distribuido', ['bancos' => $bancos])

{{-- ═══ MODAL COBROS ADICIONALES DE LA EMPRESA ════════════════════ --}}
@include('admin.facturacion._modal_cobros_adicionales')

{{-- ═══ MODAL ABONAR ═════════════════════════════════════════════ --}}
<div id="modalAbonar" class="modal-overlay" style="display:none;" onclick="cerrarSi(event,'modalAbonar')">
<div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-title">💵 Registrar Abono</div>
    <input type="hidden" id="ab_id">
    <div class="resumen-box" style="margin-bottom:.7rem;">
        <div class="resumen-row"><span>Total factura</span><strong id="ab_total">$0</strong></div>
        <div class="resumen-row"><span>Ya abonado</span><span id="ab_ya">$0</span></div>
        <div class="resumen-row total"><span>Saldo restante</span><strong id="ab_rest" style="color:#dc2626">$0</strong></div>
    </div>
    <div class="form-row">
        <div><label class="flb">Valor a abonar</label><input type="text" id="ab_valor" class="finp"></div>
        <div>
            <label class="flb">Forma de pago</label>
            <select id="ab_forma" class="finp" onchange="onAbForma()">
                <option value="efectivo">Efectivo</option>
                <option value="consignacion">Consignación</option>
                <option value="mixto">Mixto</option>
            </select>
        </div>
    </div>
    <div id="ab_banco_wrap" style="display:none;margin-bottom:.5rem;">
        <label class="flb">Cuenta bancaria</label>
        <select id="ab_banco" class="finp">
            <option value="">-- Seleccionar --</option>
            @foreach($bancos as $b)
            <option value="{{ $b->id }}">{{ strtoupper($b->banco) }}   {{ $b->nombre }}   # {{ $b->numero_cuenta }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-row form-full" style="margin-bottom:.4rem;">
        <div class="form-full"><label class="flb">Observación</label><input type="text" id="ab_obs" class="finp" placeholder="Opcional..."></div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:.4rem;">
        <button class="btn-cancelar" onclick="cerrar('modalAbonar')">Cancelar</button>
        <button class="btn-guardar" style="width:auto;padding:.48rem 1.4rem;" onclick="guardarAbono()">💵 Registrar</button>
    </div>
</div>
</div>

@push('scripts')
<script src="/js/modal_facturar_v2.js?v={{ time() }}"></script>
<script>
const CSRF    = document.querySelector('meta[name="csrf-token"]').content;
const URL_FAC = '{{ route('admin.facturacion.facturar') }}';
let selec = [];

const numFmt   = v => '$' + Math.round(v||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.');
const numParse = s => parseInt((s||'0').replace(/[^0-9]/g,''))||0;

// ── Inicializar modal unificado en modo masivo ──────────────────
MF.init({
    modo: 'masivo',
    urlFacturar: URL_FAC,
    urlMesPagado: '', // no aplica en masivo
    urlSaldosContratos: '{{ route('admin.facturacion.api.saldos_contratos') }}',
    urlVerificarPeriodo: '{{ route('admin.facturacion.api.verificar_periodo') }}',
    urlConsignacionImagen: '{{ route('admin.facturacion.consignacion.imagen.subir', ['id' => '__ID__']) }}',
    csrf: CSRF,
    empresaId: {{ $empresa->id }}, // para identificar pagos de empresa
    // Período de la vista: el modal resincroniza mf-mes/mf-anio con esto cada
    // vez que se abre, para que no quede pegado en un mes anterior.
    mes:  {{ $mes }},
    anio: {{ $anio }},
    salarioMinimo: {{ (int) \App\Models\ConfiguracionBrynex::obtener('salario_minimo', 1423500) }},
    onExito: (data) => {
        MF.cerrar();
        if (data.recibo_url) {
            // Abrir recibo en el modal iframe existente (no nueva pestaña)
            abrirRecibo(data.recibo_url + '?modal=1');
            // El reload ocurre al cerrar el modal de recibo (ver cerrarRecibo)
        } else {
            location.reload();
        }
    }
});

// Bancos disponibles como array JS
const BANCOS = [
    {id:'', label:'-- Seleccionar banco --'},
    @foreach($bancos as $b)
    {id:{{ $b->id }}, label:{!! json_encode(strtoupper($b->banco) . '   ' . $b->nombre . '   # ' . $b->numero_cuenta) !!}},
    @endforeach
];

// ─── Ordenación de columnas ──────────────────────────────────────────
// La tabla ya llega ordenada por nombre desde el controlador: se arranca con
// ese estado para que el ícono lo muestre y el primer clic invierta el orden.
let _sortCampo = 'nombre';
let _sortAsc = true;

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('sort-icon-nombre')?.classList.add('asc');
});

function ordenarTabla(campo) {
    const tbody = document.querySelector('#tblTrab tbody');
    if (!tbody) return;
    const filas = Array.from(tbody.querySelectorAll('tr'));
    if (filas.length <= 1 && filas[0]?.cells?.length === 1 && filas[0]?.cells[0]?.colSpan > 1) {
        return; // Fila vacía
    }

    if (_sortCampo === campo) {
        _sortAsc = !_sortAsc;
    } else {
        _sortCampo = campo;
        _sortAsc = true;
    }

    // Actualizar iconos visuales
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.classList.remove('asc', 'desc');
    });
    const activeIcon = document.getElementById(`sort-icon-${campo}`);
    if (activeIcon) {
        activeIcon.classList.add(_sortAsc ? 'asc' : 'desc');
    }

    filas.sort((a, b) => {
        let valA, valB;
        switch (campo) {
            case 'cedula':
                valA = parseInt(a.dataset.cedula) || 0;
                valB = parseInt(b.dataset.cedula) || 0;
                break;
            case 'nombre':
                valA = (a.dataset.nombre || '').toLowerCase();
                valB = (b.dataset.nombre || '').toLowerCase();
                return _sortAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
            case 'fecha':
                valA = a.dataset.fecha_ingreso_retiro || '';
                valB = b.dataset.fecha_ingreso_retiro || '';
                break;
            case 'dias':
                valA = parseInt(a.dataset.dias) || 0;
                valB = parseInt(b.dataset.dias) || 0;
                break;
            case 'total':
                valA = parseInt(a.dataset.vtot) || 0;
                valB = parseInt(b.dataset.vtot) || 0;
                break;
            case 'mora':
                valA = parseInt(a.dataset.vmora) || 0;
                valB = parseInt(b.dataset.vmora) || 0;
                break;
            default:
                return 0;
        }

        if (valA < valB) return _sortAsc ? -1 : 1;
        if (valA > valB) return _sortAsc ? 1 : -1;
        return 0;
    });

    // Reinsertar en el DOM
    filas.forEach(fila => tbody.appendChild(fila));
}

// ─── Estado del filtro activo (para combinarlo con la búsqueda) ───────
let _filtroActivo = 'todos';

// ─── Filtro por estado ───────────────────────────────────────────────
function filtrar(btn, tipo) {
    _filtroActivo = tipo;
    document.querySelectorAll('.fil-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    aplicarFiltrosTabla();
}

// ─── Búsqueda por nombre / cédula ───────────────────────────────────
function buscar(q) {
    const btnLimp = document.getElementById('btn-limpiar-bus');
    btnLimp.style.display = q.trim() ? 'block' : 'none';
    aplicarFiltrosTabla();
}
function limpiarBuscar() {
    const inp = document.getElementById('inp-buscar');
    inp.value = '';
    document.getElementById('btn-limpiar-bus').style.display = 'none';
    inp.focus();
    aplicarFiltrosTabla();
}

// ─── Títulos originales de las columnas para los filtros ──────────────
const TITULOS_FILTROS = {
    'filter-tipo': 'TIPO',
    'filter-rs': 'RAZÓN SOCIAL',
    'filter-eps': 'EPS',
    'filter-arl': 'ARL',
    'filter-caja': 'CAJA',
    'filter-pension': 'PENSIÓN',
    'filter-admon': 'ADMON',
    'filter-estado': 'ESTADO',
    'filter-np': 'NP'
};

// ─── Inicializar y autopoblar los filtros dropdown ───────────────────
function inicializarFiltrosTabla() {
    const filas = Array.from(document.querySelectorAll('#tblTrab tbody tr'));
    if (filas.length <= 1 && filas[0]?.cells?.length === 1 && filas[0]?.cells[0]?.colSpan > 1) {
        return; // Fila vacía
    }

    const tipos = new Set();
    const razones = new Set();
    const epsVals = new Set();
    const arlVals = new Set();
    const cajaVals = new Set();
    const penVals = new Set();
    const admVals = new Set();
    const estados = new Set();
    const npVals = new Set();

    filas.forEach(tr => {
        if (tr.dataset.tipomod) tipos.add(tr.dataset.tipomod.trim());
        if (tr.dataset.rs) razones.add(tr.dataset.rs.trim());

        const eps = parseInt(tr.dataset.veps) || 0;
        if (eps > 0) epsVals.add(eps);

        const arl = parseInt(tr.dataset.varl) || 0;
        if (arl > 0) arlVals.add(arl);

        const caja = parseInt(tr.dataset.vcaja) || 0;
        if (caja > 0) cajaVals.add(caja);

        const pen = parseInt(tr.dataset.vpen) || 0;
        if (pen > 0) penVals.add(pen);

        const adm = parseInt(tr.dataset.vadmon) || 0;
        if (adm > 0) admVals.add(adm);

        const estTxt = (tr.dataset.estadoLabel || '').trim();
        if (estTxt && estTxt !== '—') estados.add(estTxt);

        const np = (tr.dataset.np || '').trim();
        if (np) npVals.add(np);
    });

    const setOptions = (id, set, isNum = false) => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const selectedVal = sel.value;
        const titulo = TITULOS_FILTROS[id] || 'FILTRO';
        let html = `<option value="todos">${titulo} ▾</option>`;
        if (isNum || id === 'filter-np') {
            html += '<option value="-">-</option>';
        }
        
        let sortedArr = Array.from(set);
        if (isNum) {
            sortedArr.sort((a, b) => a - b);
        } else {
            sortedArr.sort();
        }

        sortedArr.forEach(v => {
            const label = isNum ? numFmt(v) : v;
            html += `<option value="${v}">${label}</option>`;
        });
        sel.innerHTML = html;
        if (Array.from(set).includes(isNum ? parseInt(selectedVal) : selectedVal) || selectedVal === '-' || selectedVal === 'todos') {
            sel.value = selectedVal;
        }
    };

    setOptions('filter-tipo', tipos);
    setOptions('filter-rs', razones);
    setOptions('filter-eps', epsVals, true);
    setOptions('filter-arl', arlVals, true);
    setOptions('filter-caja', cajaVals, true);
    setOptions('filter-pension', penVals, true);
    setOptions('filter-admon', admVals, true);
    setOptions('filter-estado', estados);
    setOptions('filter-np', npVals);
}

// ─── Motor combinado de filtros: buscador + estado + dropdowns ──────
function aplicarFiltrosTabla() {
    const q = (document.getElementById('inp-buscar')?.value || '').trim().toLowerCase();
    const tokens = q ? q.split(/\s+/).filter(t => t.length > 0) : [];

    const fTipo = document.getElementById('filter-tipo')?.value || 'todos';
    const fRS = document.getElementById('filter-rs')?.value || 'todos';
    const fEps = document.getElementById('filter-eps')?.value || 'todos';
    const fArl = document.getElementById('filter-arl')?.value || 'todos';
    const fCaja = document.getElementById('filter-caja')?.value || 'todos';
    const fPen = document.getElementById('filter-pension')?.value || 'todos';
    const fAdm = document.getElementById('filter-admon')?.value || 'todos';
    const fEst = document.getElementById('filter-estado')?.value || 'todos';
    const fNP = document.getElementById('filter-np')?.value || 'todos';

    // Manejar estilo visual si el filtro está activo
    const toggleActiveFilter = (id, val) => {
        const sel = document.getElementById(id);
        if (sel) {
            if (val !== 'todos') {
                sel.classList.add('active-filter');
            } else {
                sel.classList.remove('active-filter');
            }
        }
    };
    toggleActiveFilter('filter-tipo', fTipo);
    toggleActiveFilter('filter-rs', fRS);
    toggleActiveFilter('filter-eps', fEps);
    toggleActiveFilter('filter-arl', fArl);
    toggleActiveFilter('filter-caja', fCaja);
    toggleActiveFilter('filter-pension', fPen);
    toggleActiveFilter('filter-admon', fAdm);
    toggleActiveFilter('filter-estado', fEst);
    toggleActiveFilter('filter-np', fNP);

    document.querySelectorAll('#tblTrab tbody tr').forEach(tr => {
        if (tr.cells.length === 1 && tr.cells[0].colSpan > 1) return; // Fila vacía

        const est = tr.dataset.estado;

        // 1) Botones de estado superior
        let showEstadoBtn = true;
        if (_filtroActivo === 'pendiente') showEstadoBtn = !['pagada','prestamo'].includes(est);
        else if (_filtroActivo === 'pago')  showEstadoBtn = est === 'pagada';

        // 2) Buscador global
        let showTexto = true;
        if (tokens.length > 0) {
            const nombre  = (tr.dataset.nombre  || '').toLowerCase();
            const cedula  = (tr.dataset.cedula  || '').toLowerCase();
            const haystack = nombre + ' ' + cedula;
            showTexto = tokens.every(t => haystack.includes(t));
        }

        // 3) Dropdowns
        let showTipo = fTipo === 'todos' || tr.dataset.tipomod === fTipo;
        let showRS = fRS === 'todos' || tr.dataset.rs === fRS;

        const vEps = parseInt(tr.dataset.veps) || 0;
        let showEps = fEps === 'todos' || (fEps === '-' ? vEps === 0 : vEps === parseInt(fEps));

        const vArl = parseInt(tr.dataset.varl) || 0;
        let showArl = fArl === 'todos' || (fArl === '-' ? vArl === 0 : vArl === parseInt(fArl));

        const vCaja = parseInt(tr.dataset.vcaja) || 0;
        let showCaja = fCaja === 'todos' || (fCaja === '-' ? vCaja === 0 : vCaja === parseInt(fCaja));

        const vPen = parseInt(tr.dataset.vpen) || 0;
        let showPen = fPen === 'todos' || (fPen === '-' ? vPen === 0 : vPen === parseInt(fPen));

        const vAdm = parseInt(tr.dataset.vadmon) || 0;
        let showAdm = fAdm === 'todos' || (fAdm === '-' ? vAdm === 0 : vAdm === parseInt(fAdm));

        const trEst = (tr.dataset.estadoLabel || '').trim();
        let showEst = fEst === 'todos' || trEst === fEst;

        const trNP = (tr.dataset.np || '').trim();
        let showNP = fNP === 'todos' || (fNP === '-' ? !trNP : trNP === fNP);

        const visible = showEstadoBtn && showTexto && showTipo && showRS && showEps && showArl && showCaja && showPen && showAdm && showEst && showNP;
        tr.style.display = visible ? '' : 'none';
    });

    onCheckChange();
}


// ─── Checkboxes ───────────────────────────────────────────────
function toggleAll(chk){
    // Solo selecciona checkboxes de filas VISIBLES (respeta el filtro activo).
    // Deja fuera lo que ya está facturado y los retirados sin cobro: la casilla
    // de esas filas existe para la cuenta de cobro, pero "seleccionar todos" es
    // para facturar el mes y marcarlas solo haría rebotar el lote.
    document.querySelectorAll('.chk-row:not(:disabled):not([data-fuera-lote])').forEach(c=>{
        const fila = c.closest('tr');
        if (fila && fila.style.display !== 'none') {
            c.checked = chk.checked;
        }
    });
    onCheckChange();
}
function onCheckChange(){
    selec=[...document.querySelectorAll('.chk-row:checked')].map(c=>c.closest('tr'));
    const n=selec.length;
    document.getElementById('ctSelec').textContent=n;
    const sinSel = n===0;
    document.getElementById('btnFacturarSel').disabled=sinSel;
    document.getElementById('btnCuentaCobro').disabled=sinSel;
    if (typeof actualizarResumen === 'function') {
        actualizarResumen();
    }
    if (typeof actualizarVisibilidadPanelAdmon === 'function') {
        actualizarVisibilidadPanelAdmon();
    }
}

// ─── Cuenta de Cobro ─────────────────────────────────────────────
function abrirCuentaCobro(tipo) {
    if (!selec.length) return;
    const ids = selec.map(r => r.dataset.contrato);
    window.__ccContratos = ids;

    // Leer si se cobra admon completa en retiros
    const chkAdmonRetiro = document.getElementById('chk-admon-retiro');
    const admonCompletaEnRetiros = chkAdmonRetiro ? (chkAdmonRetiro.checked ? '1' : '0') : '1';

    const queryParams = new URLSearchParams({
        tipo: tipo,
        mes: new URLSearchParams(location.search).get('mes') || '{{ $mes }}',
        anio: new URLSearchParams(location.search).get('anio') || '{{ $anio }}',
        empresa_id: '{{ $empresa->id }}',
        admon_retiro_completa: admonCompletaEnRetiros,
    });

    let url = '{{ route("admin.facturacion.cuenta_cobro.preview") }}?' + queryParams.toString();
    ids.forEach(id => {
        url += '&contratos[]=' + id;
    });

    window.open(url, '_blank');
}

// ─── Resumen ─────────────────────────────────────────────
function _buildContratosSelec() {
    // Leer si el checkbox de admon completa en retiros está activo
    const chkAdmonRetiro = document.getElementById('chk-admon-retiro');
    const admonCompletaEnRetiros = chkAdmonRetiro ? chkAdmonRetiro.checked : true;

    return selec.map(r => {
        const esRetFacturable = r.getAttribute('data-es-retiro-facturable') === "1";
        const diasRet = esRetFacturable ? parseInt(r.getAttribute('data-dias-retiro') || 0) : 0;

        return {
            id:        r.dataset.contrato,
            eps:       parseInt(r.dataset.veps   || 0),
            arl:       parseInt(r.dataset.varl   || 0),
            afp:       parseInt(r.dataset.vpen   || 0),
            caja:      parseInt(r.dataset.vcaja  || 0),
            paraf:     parseInt(r.dataset.vparaf || 0),
            admon:     parseInt(r.dataset.vadmon || 0),  // ya fue actualizado por actualizarAdmonRetiro()
            seg:       parseInt(r.dataset.seguro || 0),
            iva:       parseInt(r.dataset.viva   || 0),
            mora:      parseInt(r.dataset.vmora  || 0),
            arl_nivel: parseInt(r.dataset.arlnivel || 1),
            dias:      esRetFacturable ? diasRet : parseInt(r.dataset.dias || 30),
            nombre:    r.dataset.nombre || '',
            tipo:      r.dataset.tipo   || 'planilla',
            afiliacion: parseInt(r.dataset.afiliacion || 0),
            esindact:  r.dataset.esindact === '1',
            tipo_modalidad_id: parseInt(r.dataset.tipo_modalidad_id || 0),
            es_retiro_facturable: esRetFacturable,
            dias_retiro: diasRet,
            incluir_admon_retiro_corto: esRetFacturable ? admonCompletaEnRetiros : true,
        };
    });
}

// ─── Abrir modal facturar ───────────────────────────────────
function abrirModalFacturar(){
    if(!selec.length) return;
    // La casilla está disponible en las filas ya facturadas y en los retirados
    // sin cobro para poder armar la cuenta de cobro. Facturarlas es otra cosa:
    // el servidor rebota el lote completo si alguna ya tiene factura del
    // período, así que se avisa aquí antes de abrir el modal.
    const fuera = [...document.querySelectorAll('.chk-row:checked[data-fuera-lote]')];
    if (fuera.length) {
        const nombres = fuera
            .map(c => c.closest('tr')?.dataset.nombre || c.closest('tr')?.cells[2]?.textContent.trim())
            .filter(Boolean)
            .join(', ');
        alert('No se puede facturar: ' + fuera.length + ' de las filas marcadas ya están facturadas '
            + 'o son retiros sin cobro en este período.\n\n' + nombres
            + '\n\nQuítalas de la selección (o anula su factura) y vuelve a intentar.');
        return;
    }
    const contratos = _buildContratosSelec();
    MF.abrir(contratos, selec.length + ' trabajadores');
}

function facturarUno(id){
    const chk = document.querySelector(`.chk-row[value="${id}"]`);
    if(chk && !chk.disabled){ chk.checked=true; onCheckChange(); }
    abrirModalFacturar();
}

// guardarFactura() ha sido reemplazada por MF.guardar() en modal_facturar.js

// ─── Modal Abonar ─────────────────────────────────────────────
function abrirAbono(id,total,ya){
    document.getElementById('ab_id').value=id;
    document.getElementById('ab_total').textContent=numFmt(total);
    document.getElementById('ab_ya').textContent=numFmt(ya);
    document.getElementById('ab_rest').textContent=numFmt(total-ya);
    document.getElementById('ab_valor').value=0;
    document.getElementById('modalAbonar').style.display='flex';
}
function onAbForma(){
    const f=document.getElementById('ab_forma').value;
    document.getElementById('ab_banco_wrap').style.display=['consignacion','mixto'].includes(f)?'':'none';
}
async function guardarAbono(){
    const id  = document.getElementById('ab_id').value;
    const val = numParse(document.getElementById('ab_valor').value);
    if(!val) return alert('Ingrese un valor válido.');
    try{
        const res=await fetch(`{{ url('admin/facturacion/abonar') }}/${id}`,{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
            body:JSON.stringify({
                valor:           val,
                forma_pago:      document.getElementById('ab_forma').value,
                banco_cuenta_id: document.getElementById('ab_banco').value||null,
                observacion:     document.getElementById('ab_obs').value,
            })
        });
        const data=await res.json();
        if(data.ok){
            cerrar('modalAbonar');
            if(data.recibo_url) abrirRecibo(data.recibo_url + '?modal=1');
            location.reload();
        } else alert(data.mensaje||'Error al abonar');
    }catch(e){ alert('Error de conexión'); }
}

// ─── Helpers ──────────────────────────────────────────────
function cerrar(id){ const e = document.getElementById(id); if(e) e.style.display='none'; }
function cerrarSi(e,id){ if(e.target.id===id) cerrar(id); }

// ─── Modal Recibo (iframe) ─────────────────────────────────
function abrirRecibo(url) {
    document.getElementById('recibo-frame').src = url;
    document.getElementById('recibo-modal-ov').style.display = 'flex';
}
function cerrarRecibo() {
    document.getElementById('recibo-modal-ov').style.display = 'none';
    document.getElementById('recibo-frame').src = '';
    location.reload(); // refrescar tabla después de ver el recibo
}

// ─── Otro Ingreso — abrir desde empresa ───────────────────────
function OI_abrirEmpresa() {
    OI.abrir({
        cedula:       null, // sin cédula fija — se pedirá por campo en el modal si aplica
        empresaId:    {{ $empresa->id }},
        subtitulo:    '{{ addslashes($empresa->empresa) }}',
        aplicaIva:    {{ strtoupper($empresa->iva ?? '') === 'SI' ? 'true' : 'false' }},
        pctIva:       19,   // porcentaje estándar
        mes:          {{ $mes }},
        anio:         {{ $anio }},
        asesorId:     {!! json_encode($empresa->asesor_id) !!},
        asesorNombre: {!! json_encode($empresa->asesor?->nombre ?? '') !!},
    });
}
// ─── Exportación a Excel en el lado del cliente (redirección al backend) ──────────────────────
function exportarExcel() {
    const btn = document.querySelector('.btn-exp');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Exportando...';
    btn.style.opacity = '0.7';

    const urlParams = new URLSearchParams(window.location.search);
    const mes = urlParams.get('mes') || '{{ $mes }}';
    const anio = urlParams.get('anio') || '{{ $anio }}';

    // Redireccionar a la ruta del backend
    window.location.href = `{{ route('admin.facturacion.empresa.exportar', $empresa->id) }}?mes=${mes}&anio=${anio}`;

    setTimeout(() => {
        // Restaurar botón después de iniciar la descarga
        btn.disabled = false;
        btn.innerHTML = originalText;
        btn.style.opacity = '1';
    }, 2000); // 2 segundos de feedback visual
}

// ─── Forzar campo buscador vacío al cargar (evita autorrelleno del navegador) ───
document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('inp-buscar');
    if (inp) { inp.value = ''; }
    const btnLimp = document.getElementById('btn-limpiar-bus');
    if (btnLimp) btnLimp.style.display = 'none';
    inicializarFiltrosTabla();
    aplicarFiltrosTabla();
});
</script>

{{-- Modal Recibo reutilizable --}}
<div id="recibo-modal-ov"
     onclick="if(event.target.id==='recibo-modal-ov')cerrarRecibo()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99999;align-items:center;justify-content:center;">
    <div style="position:relative;width:96vw;max-width:1100px;height:93vh;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.5);display:flex;flex-direction:column;">
        {{-- Header del modal --}}
        <div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:.6rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.5rem;">
                <span style="font-size:1.1rem;">🧾</span>
                <span style="color:#fff;font-size:.9rem;font-weight:700;letter-spacing:.02em;">Recibo de Pago</span>
            </div>
            <button onclick="cerrarRecibo()"
                    style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:6px;width:28px;height:28px;font-size:1rem;cursor:pointer;line-height:1;font-weight:700;transition:background .15s;"
                    onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">&#x2715;</button>
        </div>
        <div style="flex:1;background:#e8edf2;padding:.35rem 0 0;overflow:hidden;">
            <iframe id="recibo-frame" src="" style="width:100%;height:100%;border:none;display:block;"></iframe>
        </div>
    </div>
</div>
@endpush

{{-- Panel Claves y Accesos de la Empresa --}}
@include('admin.facturacion.partials.clave_accesos_empresa')

{{-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: Registrar / Editar Retiro Pendiente
     Se abre al hacer clic en el ícono 🗓/✏️ junto a la columna DÍAS.
     Guarda la fecha de retiro y la decisión de cobrar admon via AJAX.
     El contrato permanece 'vigente' hasta que se facture.
═══════════════════════════════════════════════════════════════════════════ --}}
<div id="modal-retiro-pendiente-ov"
     onclick="if(event.target.id==='modal-retiro-pendiente-ov')cerrarModalRetiroPendiente()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.4);width:100%;max-width:460px;overflow:hidden;">

        {{-- Header --}}
        <div style="background:linear-gradient(135deg,#78350f,#b45309);padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:.5rem;">
                <span style="font-size:1.1rem;">📅</span>
                <span style="color:#fff;font-size:.95rem;font-weight:700;">Registrar Retiro Pendiente</span>
            </div>
            <button onclick="cerrarModalRetiroPendiente()"
                    style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:6px;width:28px;height:28px;font-size:1rem;cursor:pointer;font-weight:700;">✕</button>
        </div>

        {{-- Body --}}
        <div style="padding:1.25rem 1.4rem;">
            {{-- Info del trabajador --}}
            <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:.6rem .9rem;margin-bottom:1rem;">
                <div style="font-size:.78rem;color:#78350f;font-weight:600;" id="rp-nombre-label">—</div>
                <div style="font-size:.72rem;color:#92400e;" id="rp-rs-label">—</div>
            </div>

            {{-- Fecha de retiro --}}
            <div style="margin-bottom:1rem;">
                <label for="rp-fecha" style="display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.3rem;">
                    📅 Fecha del último día trabajado
                </label>
                <input type="date" id="rp-fecha"
                       oninput="rpCalcularDias()"
                       style="width:100%;border:1.5px solid #d1d5db;border-radius:8px;padding:.5rem .75rem;font-size:.9rem;color:#111827;outline:none;box-sizing:border-box;"
                       onfocus="this.style.borderColor='#b45309'" onblur="this.style.borderColor='#d1d5db'">
            </div>

            {{-- Días calculados --}}
            <div id="rp-dias-box" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.6rem .9rem;margin-bottom:1rem;">
                <div style="font-size:.8rem;color:#166534;">
                    📊 Días a cotizar: <strong id="rp-dias-valor" style="font-size:1rem;color:#15803d;">0</strong>
                </div>
                <div id="rp-admon-hint" style="font-size:.72rem;color:#15803d;margin-top:.2rem;"></div>
            </div>

            {{-- Checkbox admon --}}
            <div id="rp-admon-box" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.65rem .9rem;margin-bottom:1.2rem;">
                <label style="display:flex;align-items:center;gap:.55rem;cursor:pointer;">
                    <input type="checkbox" id="rp-cobrar-admon"
                           style="width:1rem;height:1rem;accent-color:#2563eb;cursor:pointer;">
                    <span style="font-size:.82rem;color:#1e40af;font-weight:600;">💼 Cobrar administración en este retiro</span>
                </label>
                <div id="rp-admon-valor-label" style="font-size:.72rem;color:#3b82f6;margin-top:.3rem;margin-left:1.55rem;"></div>
            </div>

            {{-- Alerta si ya tiene retiro pendiente --}}
            <div id="rp-alerta-existente" style="display:none;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:.55rem .8rem;margin-bottom:1rem;font-size:.75rem;color:#c2410c;">
                ⚠️ Este contrato ya tiene un retiro pendiente registrado. Al guardar se reemplazará.
            </div>

            {{-- Botones --}}
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <button id="rp-btn-guardar"
                        onclick="rpGuardar()"
                        style="flex:1;background:linear-gradient(135deg,#b45309,#d97706);color:#fff;border:none;border-radius:8px;padding:.55rem .8rem;font-size:.85rem;font-weight:700;cursor:pointer;min-width:120px;">
                    ✅ Guardar Retiro
                </button>
                <button id="rp-btn-quitar"
                        onclick="rpQuitarRetiro()"
                        style="display:none;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;padding:.55rem .8rem;font-size:.82rem;font-weight:600;cursor:pointer;">
                    🗑 Quitar Retiro
                </button>
                <button onclick="cerrarModalRetiroPendiente()"
                        style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:8px;padding:.55rem .8rem;font-size:.82rem;cursor:pointer;">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Retiro Pendiente — Estado del modal ──────────────────────────────────────
var _rpContratoId = null;
var _rpAdmonTotal = 0;
var _rpTieneRetiroActual = false;

function abrirModalRetiroPendiente(contratoId, nombre, rs, admonTotal, fechaActual, cobrarAdmonActual) {
    _rpContratoId    = contratoId;
    _rpAdmonTotal    = admonTotal;
    _rpTieneRetiroActual = !!fechaActual;

    document.getElementById('rp-nombre-label').textContent = nombre;
    document.getElementById('rp-rs-label').textContent     = rs;

    var fechaInput = document.getElementById('rp-fecha');
    fechaInput.value = fechaActual || '{{ $fechaPredeterminada }}';

    // Mostrar botón quitar solo si ya tiene retiro pendiente
    document.getElementById('rp-btn-quitar').style.display  = fechaActual ? 'inline-block' : 'none';
    document.getElementById('rp-alerta-existente').style.display = fechaActual ? 'block' : 'none';

    // Si hay un valor de fecha (sea la predeterminada o la existente), calcular días de inmediato
    if (fechaInput.value) {
        rpCalcularDias();
        if (fechaActual) {
            document.getElementById('rp-cobrar-admon').checked = !!cobrarAdmonActual;
        }
    } else {
        document.getElementById('rp-dias-box').style.display  = 'none';
        document.getElementById('rp-admon-box').style.display = 'none';
    }


    var ov = document.getElementById('modal-retiro-pendiente-ov');
    ov.style.display = 'flex';
}

function cerrarModalRetiroPendiente() {
    document.getElementById('modal-retiro-pendiente-ov').style.display = 'none';
    _rpContratoId = null;
}

function rpCalcularDias() {
    var fecha = document.getElementById('rp-fecha').value;
    if (!fecha) {
        document.getElementById('rp-dias-box').style.display  = 'none';
        document.getElementById('rp-admon-box').style.display = 'none';
        return;
    }
    // Extraer el día del mes directamente del string YYYY-MM-DD (evita problemas de zona horaria)
    var parts = fecha.split('-');
    var dia = parseInt(parts[2], 10);

    document.getElementById('rp-dias-valor').textContent = dia;
    document.getElementById('rp-dias-box').style.display  = 'block';
    document.getElementById('rp-admon-box').style.display = 'block';

    // Sugerencia de admon: marcado si > 3 días, desmarcado si ≤ 3
    var chk = document.getElementById('rp-cobrar-admon');
    if (!_rpTieneRetiroActual) {
        chk.checked = (dia > 3);
    }

    // Label del hint
    var hint = document.getElementById('rp-admon-hint');
    if (dia <= 3) {
        hint.textContent = '⚠️ ≤ 3 días: se sugiere NO cobrar administración.';
        hint.style.color = '#b45309';
    } else {
        hint.textContent = '✅ ' + dia + ' días: se sugiere SÍ cobrar administración.';
        hint.style.color = '#166534';
    }

    // Mostrar valor de admon
    document.getElementById('rp-admon-valor-label').textContent =
        'Valor admon: $' + Number(_rpAdmonTotal).toLocaleString('es-CO') + ' (completo mensual)';
}

function rpGuardar() {
    var fecha = document.getElementById('rp-fecha').value;
    if (!fecha) { alert('Por favor selecciona la fecha del último día trabajado.'); return; }

    var cobrarAdmon = document.getElementById('rp-cobrar-admon').checked ? 1 : 0;
    var btn = document.getElementById('rp-btn-guardar');
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    fetch('{{ route("admin.facturacion.contrato.retiro_pendiente", ["contrato" => ":cid"]) }}'.replace(':cid', _rpContratoId), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'
        },
        body: JSON.stringify({ fecha_retiro: fecha, cobrar_admon: cobrarAdmon })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = '✅ Guardar Retiro';
        if (data.ok) {
            cerrarModalRetiroPendiente();
            location.reload();
        } else {
            alert(data.mensaje || 'Error al guardar el retiro.');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = '✅ Guardar Retiro';
        alert('Error de conexión. Intenta de nuevo.');
    });
}

function rpQuitarRetiro() {
    if (!confirm('¿Seguro que deseas quitar el retiro pendiente de este contrato? Volverá a los 30 días normales.')) return;

    fetch('{{ route("admin.facturacion.contrato.retiro_pendiente", ["contrato" => ":cid"]) }}'.replace(':cid', _rpContratoId), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'
        },
        body: JSON.stringify({ fecha_retiro: '', cobrar_admon: 0 })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            cerrarModalRetiroPendiente();
            location.reload();
        } else {
            alert(data.mensaje || 'Error al quitar el retiro.');
        }
    })
    .catch(function() { alert('Error de conexión.'); });
}
</script>

<script>
// ════════════════════════════════════════════════════════════════════════
//  MODAL CARGA MASIVA DE CÉDULAS — NP PROVISIONAL
// ════════════════════════════════════════════════════════════════════════
const CC_URL_VERIFICAR = '{{ route("admin.facturacion.empresa.verificar_cedulas", $empresa->id) }}';
const CC_URL_ASIGNAR   = '{{ route("admin.facturacion.empresa.asignar_np", $empresa->id) }}';
const CC_MES  = {{ $mes }};
const CC_ANIO = {{ $anio }};

let _ccExitosas = [];

// ════════════════════════════════════════════════════════════════════════
//  NP EDITABLE EN LA TABLA — clic en la celda → select → guarda en BD
//  Usa el mismo endpoint que la carga masiva de cédulas (asignar-np).
// ════════════════════════════════════════════════════════════════════════

// Pinta el contenido de una celda NP (chip clicable o guion clicable)
function npRenderCelda(td, np) {
    if (!td) return;
    const val = (np || '').toString().trim();
    td.innerHTML = val
        ? `<span class="np-chip" onclick="npEditar(this)" title="Clic para cambiar el NP">${val}</span>`
        : `<span class="np-vacio" onclick="npEditar(this)" title="Clic para asignar NP">—</span>`;
}

// Celda NP de una fila (o null si la fila no es editable — ya facturada)
function npCelda(tr) {
    const td = tr?.querySelector('td.td-np');
    return (td && td.querySelector('.np-chip, .np-vacio, .np-select')) ? td : null;
}

// Clic → convierte la celda en un <select>
function npEditar(el) {
    const td = el.closest('td');
    const tr = td.closest('tr');
    if (td.querySelector('select')) return;

    const actual = (tr.dataset.npProv || '').trim();
    const opts = ['', '1', '2', '3', '4', '5']
        .map(v => `<option value="${v}"${v === actual ? ' selected' : ''}>${v === '' ? '—' : 'NP ' + v}</option>`)
        .join('');

    td.innerHTML = `<select class="np-select" onchange="npGuardar(this)" onblur="npCancelar(this)">${opts}</select>`;
    const sel = td.querySelector('select');
    sel.focus();
    // Escape = cancelar sin guardar
    sel.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); sel.blur(); } });
}

function npCancelar(sel) {
    const td = sel.closest('td');
    if (td.dataset.guardando === '1') return;   // el onchange ya está guardando
    npRenderCelda(td, td.closest('tr').dataset.npProv);
}

function npGuardar(sel) {
    const td  = sel.closest('td');
    const tr  = td.closest('tr');
    const nuevo    = sel.value;
    const anterior = (tr.dataset.npProv || '').trim();

    if (nuevo === anterior) { npRenderCelda(td, anterior); return; }

    td.dataset.guardando = '1';
    sel.disabled = true;

    fetch(CC_URL_ASIGNAR, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ contrato_ids: [parseInt(tr.dataset.contrato)], np: nuevo === '' ? 0 : parseInt(nuevo) })
    })
    .then(r => r.json())
    .then(data => {
        td.dataset.guardando = '0';
        if (!data.ok) { alert(data.message || 'Error al guardar el NP.'); npRenderCelda(td, anterior); return; }

        tr.dataset.np     = nuevo;
        tr.dataset.npProv = nuevo;
        npRenderCelda(td, nuevo);

        // Flash verde de confirmación
        td.classList.add('np-ok');
        setTimeout(() => td.classList.remove('np-ok'), 600);

        // Repoblar el dropdown de filtro NP (y reaplicar si hay filtro activo)
        inicializarFiltrosTabla();
        const filtroNP = document.getElementById('filter-np');
        if (filtroNP && filtroNP.value !== 'todos') setTimeout(aplicarFiltrosTabla, 650);
    })
    .catch(() => {
        td.dataset.guardando = '0';
        alert('Error de conexión al guardar el NP.');
        npRenderCelda(td, anterior);
    });
}

function abrirModalCargaCedulas() {
    ccVolver();
    document.getElementById('cc-cedulas').value = '';
    document.getElementById('cc-np').value = '1';
    document.getElementById('modalCargaCedulas').style.display = 'flex';
}

function ccVolver() {
    document.getElementById('cc-paso1').style.display = 'block';
    document.getElementById('cc-paso2').style.display = 'none';
    _ccExitosas = [];
}

function ccParsearCedulas() {
    const raw = document.getElementById('cc-cedulas').value;
    return [...new Set(
        raw.split(/[\n,;\s]+/)
            .map(s => s.replace(/[^0-9]/g, '').trim())
            .filter(s => s.length >= 5)
    )];
}

// ── Paso 1: Verificar ─────────────────────────────────────────────────
function ccVerificar() {
    const cedulas = ccParsearCedulas();
    if (cedulas.length === 0) { alert('Pega al menos una cédula válida.'); return; }

    const btn = document.getElementById('cc-btn-verificar');
    btn.disabled = true;
    btn.textContent = '⏳ Verificando…';

    fetch(CC_URL_VERIFICAR, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ cedulas, mes: CC_MES, anio: CC_ANIO })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = '🔍 Verificar →';
        if (!data.ok) { alert(data.message || 'Error al verificar.'); return; }

        _ccExitosas = data.exitosas || [];
        const ok    = _ccExitosas.length;
        const yaFac = (data.ya_facturadas || []).length;
        const noEnc = (data.no_encontradas || []).length;
        const total = data.total_input;

        // Chips resumen (usan clase cc-chip del CSS del modal)
        document.getElementById('cc-resumen').innerHTML = `
            <div class="cc-chip" style="background:#f0fdf4;border-color:#86efac;">
                <div class="cc-num" style="color:#15803d;">${ok}</div>
                <div class="cc-lab" style="color:#166534;">✅ Listas para facturar</div>
            </div>
            <div class="cc-chip" style="background:#fffbeb;border-color:#fde68a;">
                <div class="cc-num" style="color:#d97706;">${yaFac}</div>
                <div class="cc-lab" style="color:#92400e;">⚠️ Ya facturadas</div>
            </div>
            <div class="cc-chip" style="background:#fef2f2;border-color:#fca5a5;">
                <div class="cc-num" style="color:#dc2626;">${noEnc}</div>
                <div class="cc-lab" style="color:#991b1b;">❌ No encontradas</div>
            </div>
            <div style="grid-column:1/-1;text-align:center;font-size:.68rem;color:#94a3b8;margin-top:-.2rem;">${total} cédulas procesadas</div>`;

        // Copybox no encontradas
        const wNoEnc = document.getElementById('cc-wrap-no-enc');
        if (noEnc > 0) {
            document.getElementById('cc-txt-no-enc').value = (data.no_encontradas || []).join('\n');
            wNoEnc.style.display = 'block';
        } else { wNoEnc.style.display = 'none'; }

        // Copybox ya facturadas
        const wYaFac = document.getElementById('cc-wrap-ya-fac');
        if (yaFac > 0) {
            document.getElementById('cc-txt-ya-fac').value =
                (data.ya_facturadas || []).map(f => `${f.cedula} - ${f.nombre}`).join('\n');
            wYaFac.style.display = 'block';
        } else { wYaFac.style.display = 'none'; }

        document.getElementById('cc-btn-asignar').disabled    = ok === 0;
        document.getElementById('cc-btn-limpiar-np').disabled = false;

        document.getElementById('cc-paso1').style.display = 'none';
        document.getElementById('cc-paso2').style.display = 'block';
    })
    .catch(() => { btn.disabled = false; btn.textContent = '🔍 Verificar →'; alert('Error de conexión.'); });
}

// ── Paso 2: Asignar NP y seleccionar ─────────────────────────────────
// Si un contrato ya tenía NP distinto, se sobreescribe con el nuevo.
function ccAsignar() {
    if (_ccExitosas.length === 0) return;

    const np          = parseInt(document.getElementById('cc-np').value);
    const contratoIds = _ccExitosas.map(e => e.contrato_id);
    const btn = document.getElementById('cc-btn-asignar');
    btn.disabled = true; btn.textContent = '⏳ Asignando…';

    fetch(CC_URL_ASIGNAR, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ contrato_ids: contratoIds, np })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false; btn.textContent = '✅ Asignar NP y Seleccionar';
        if (!data.ok) { alert(data.message || 'Error al asignar NP.'); return; }

        // Actualizar DOM — sobreescribe cualquier NP anterior que tuviera
        const npStr = String(np);
        _ccExitosas.forEach(item => {
            const tr = document.querySelector(`tr[data-contrato="${item.contrato_id}"]`);
            if (!tr) return;
            tr.dataset.np    = npStr;
            tr.dataset.npProv = npStr;
            npRenderCelda(npCelda(tr), npStr);
        });

        // Reinicializar filtros y aplicar NP
        inicializarFiltrosTabla();
        const filtroNP = document.getElementById('filter-np');
        if (filtroNP) { filtroNP.value = npStr; filtroNP.classList.add('active-filter'); }
        aplicarFiltrosTabla();

        // Marcar checkboxes de filas visibles (solo las facturables)
        document.querySelectorAll('.chk-row:not(:disabled):not([data-fuera-lote])').forEach(chk => {
            const fila = chk.closest('tr');
            if (fila && fila.style.display !== 'none') chk.checked = true;
        });
        onCheckChange();
        cerrar('modalCargaCedulas');
    })
    .catch(() => { btn.disabled = false; btn.textContent = '✅ Asignar NP y Seleccionar'; alert('Error de conexión.'); });
}

// ── Resetear NP de todos los contratos activos de esta empresa en la BD y DOM ──
function ccResetearNPsEmpresa(btnId) {
    if (!confirm('¿Seguro que deseas borrar el NP de TODOS los contratos activos de esta empresa?')) return;

    const btn = document.getElementById(btnId);
    let originalText = '';
    if (btn) {
        btn.disabled = true;
        originalText = btn.textContent;
        btn.textContent = '⏳ Limpiando…';
    }

    // 1. Limpiar DOM inmediatamente para todos los que tienen NP provisional
    const trConNP = Array.from(document.querySelectorAll('tr[data-np-prov]'))
        .filter(tr => tr.dataset.npProv && tr.dataset.npProv !== '');

    trConNP.forEach(tr => {
        tr.dataset.np     = '';
        tr.dataset.npProv = '';
        npRenderCelda(npCelda(tr), '');
    });

    // 2. Limpiar los filtros si es necesario
    const filtroNP = document.getElementById('filter-np');
    if (filtroNP && filtroNP.value !== 'todos') {
        filtroNP.value = 'todos';
        filtroNP.classList.remove('active-filter');
    }
    inicializarFiltrosTabla();
    aplicarFiltrosTabla();

    // 3. Petición al backend
    fetch(CC_URL_ASIGNAR, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ np: 0, limpiar_todos: true })
    })
    .then(r => r.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        if (data.ok) {
            alert('NPs de contratos activos limpiados correctamente.');
        } else {
            alert(data.message || 'Error al limpiar NPs.');
        }
    })
    .catch(() => {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Error de conexión al limpiar NPs.');
    });
}

// ── Copiar textarea ───────────────────────────────────────────────────
function ccCopiar(textareaId) {
    const ta = document.getElementById(textareaId);
    if (!ta || !ta.value.trim()) return;
    navigator.clipboard.writeText(ta.value.trim()).then(() => {
        const box = ta.closest('.cc-copybox');
        const btn = box?.querySelector('.cc-copy-btn');
        if (btn) { const o = btn.textContent; btn.textContent = '✅ Copiado'; setTimeout(() => btn.textContent = o, 1800); }
    }).catch(() => { ta.select(); document.execCommand('copy'); });
}
</script>

@endsection
