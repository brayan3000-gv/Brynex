@extends('layouts.app')
@section('modulo', 'Historial de Pagos')

@php
$fmt   = fn($v) => '$' . number_format($v ?? 0, 0, ',', '.');
$meses_full = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
// $cliente viene directo del controlador (no depende de $contrato)
$nombre  = trim(($cliente->primer_nombre ?? '') . ' ' . ($cliente->segundo_nombre ?? '') . ' ' . ($cliente->primer_apellido ?? '') . ' ' . ($cliente->segundo_apellido ?? ''));
$nombre = nombre_oracion($nombre);
if (!$nombre) $nombre = $cliente->nombre_completo ?? ('CC ' . $cedula);
$esAdmin       = in_array(auth()->user()->rol ?? '', ['superadmin','admin']);
// SuperAdmin de BryNex: puede anular facturas con planilla pagada
$esSuperBrynex = auth()->user()->es_brynex && auth()->user()->hasRole('superadmin');

@endphp

@section('contenido')
<style>
/* ════ Historial de Pagos ════ */
.hi-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;padding:1rem 1.4rem;margin-bottom:1rem;color:#fff}
.hi-h-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.hi-nom{font-size:1.3rem;font-weight:800}
.hi-meta{font-size:.78rem;color:#94a3b8;display:flex;flex-wrap:wrap;gap:1.2rem;margin-top:.3rem}
.hi-filtros{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.65rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
.hi-filtros label{font-size:.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.hi-sel{padding:.3rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.8rem;background:#f8fafc;cursor:pointer;font-family:inherit;outline:none;color:#0f172a;transition:border-color .15s}
.hi-sel:focus{border-color:#3b82f6}
.hi-pill{padding:.25rem .75rem;border-radius:20px;font-size:.72rem;font-weight:700;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer;transition:all .15s;text-decoration:none}
.hi-pill.active,.hi-pill:hover{background:#eff6ff;border-color:#3b82f6;color:#1d4ed8}
/* Grupos acordeón */
.hi-group{background:#fff;border:1px solid #e2e8f0;border-radius:13px;overflow:hidden;margin-bottom:.75rem}
.hi-group-hdr{display:flex;align-items:center;justify-content:space-between;padding:.65rem 1rem;cursor:pointer;user-select:none;transition:background .15s}
.hi-group-hdr:hover{background:#f8fafc}
.hi-group-title{font-size:.9rem;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:.5rem}
.hi-group-badge{font-size:.63rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;background:#eff6ff;color:#1d4ed8}
.hi-group-total{font-size:.82rem;font-weight:900;font-family:monospace;color:#15803d}
.hi-group-caret{font-size:.8rem;color:#94a3b8;transition:transform .2s}
.hi-group-body{display:none}
.hi-group.open .hi-group-body{display:block}
.hi-group.open .hi-group-caret{transform:rotate(180deg)}
/* Sub-grupo por año */
.hi-year{padding:.3rem 1rem .15rem;font-size:.6rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid #f1f5f9;background:#fafafa;display:flex;align-items:center;justify-content:space-between}
.hi-year-total{font-size:.7rem;font-weight:800;color:#475569}
/* Tabla */
table.hi-tbl{width:100%;border-collapse:collapse;font-size:.77rem}
.hi-tbl th{background:#f8fafc;color:#64748b;font-size:.6rem;text-transform:uppercase;letter-spacing:.05em;padding:.35rem .6rem;text-align:center;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.hi-tbl td{padding:.3rem .6rem;border-bottom:1px solid #f8fafc;white-space:nowrap;vertical-align:middle;text-align:center}
.hi-tbl tr:last-child td{border-bottom:none}
.hi-tbl tr:hover td{background:#fafafa}
.num{font-family:monospace;text-align:center;font-weight:700}
/* Badges */
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.12rem .5rem;border-radius:20px;font-size:.65rem;font-weight:700}
.badge-pago    {background:#dcfce7;color:#15803d}
.badge-pre     {background:#fef3c7;color:#92400e}
.badge-prest   {background:#ede9fe;color:#6d28d9}
.badge-abono   {background:#fef9c3;color:#854d0e}
.badge-plan    {background:#dbeafe;color:#1e40af}
.badge-afil    {background:#fce7f3;color:#9d174d}
.badge-tram    {background:#dcfce7;color:#065f46;border:1px solid #a7f3d0}
.hi-tram-desc{font-size:.68rem;font-weight:700;color:#065f46;margin-top:.15rem;line-height:1.25;max-width:220px;white-space:normal}
.hi-tram-obs {font-size:.65rem;font-weight:500;color:#64748b;margin-top:.1rem;line-height:1.25;max-width:220px;white-space:normal;font-style:italic}
.badge-ret     {background:#ffe4e6;color:#9f1239;border:1px solid #fecdd3}
.badge-ret-info{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.badge-emp     {background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-ind     {background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
/* Botones acción */
.btn-act-sm{padding:.2rem .55rem;border-radius:6px;font-size:.68rem;font-weight:700;border:none;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:.25rem;text-decoration:none}
.btn-recibo{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.btn-recibo:hover{background:#dbeafe}
.btn-plano {background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.btn-plano:hover{background:#dcfce7}
/* Modal Plano */
#modal-plano-overlay{position:fixed;inset:0;background:rgba(10,10,20,.65);backdrop-filter:blur(4px);z-index:3000;display:flex;align-items:center;justify-content:center;padding:.75rem}
#modal-plano-box{background:#fff;border-radius:16px;width:min(620px,97vw);max-height:90vh;overflow-y:auto;box-shadow:0 24px 70px rgba(0,0,0,.4)}
.mp-hdr{background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:.75rem 1.1rem;display:flex;justify-content:space-between;align-items:center;border-radius:16px 16px 0 0}
.mp-title{font-size:.9rem;font-weight:800;color:#fff}
.mp-close{border:none;background:rgba(255,255,255,.12);color:#fff;border-radius:6px;width:26px;height:26px;cursor:pointer;font-size:.9rem}
.mp-body{padding:1rem 1.1rem}
.mp-sec{font-size:.6rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin:.75rem 0 .3rem}
.mp-grid{display:grid;grid-template-columns:1fr 1fr;gap:.3rem .5rem}
.mp-row{display:flex;justify-content:space-between;align-items:center;padding:.2rem 0;border-bottom:1px solid #f8fafc}
.mp-lbl{font-size:.73rem;color:#64748b;font-weight:600}
.mp-val{font-size:.74rem;font-weight:800;color:#0f172a;font-family:monospace}
.mp-total{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:9px;padding:.45rem .75rem;display:flex;justify-content:space-between;align-items:center;margin-top:.6rem}
.mp-total-lbl{font-size:.7rem;color:rgba(255,255,255,.7);font-weight:700;text-transform:uppercase}
.mp-total-val{font-size:1rem;font-weight:900;color:#fff;font-family:monospace}
.btn-anular{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}.btn-anular:hover{background:#ffe4e6}
.badge-planilla{display:inline-flex;align-items:center;gap:.25rem;padding:.13rem .5rem;border-radius:20px;font-size:.62rem;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;font-family:monospace;letter-spacing:.03em}
.badge-planilla-lock{background:#fef9c3;color:#854d0e;border-color:#fde68a}
.btn-soporte-planilla{display:inline-flex;align-items:center;gap:.2rem;padding:.1rem .38rem;border-radius:5px;font-size:.62rem;font-weight:700;background:#fffbeb;color:#b45309;border:1px solid #fde68a;cursor:pointer;transition:all .15s;vertical-align:middle;margin-left:.25rem}
.btn-soporte-planilla:hover{background:#fef3c7;border-color:#f59e0b}
</style>

{{-- HEADER --}}
<div class="hi-header">
    <div class="hi-h-top">
        <div>
            <a href="{{ route('admin.clientes.edit', $cliente->id) }}" style="color:#94a3b8;font-size:.8rem;text-decoration:none">← Volver a la ficha del cliente</a>
            <div class="hi-nom" style="margin-top:.2rem">🕒 Historial de Pagos</div>
            <div class="hi-meta">
                <span>👤 {{ $nombre }}</span>
                <span>CC {{ $cedula }}</span>
            </div>
        </div>
        <div style="text-align:right">
            @if($sinFiltros)
            <span style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);font-size:.68rem;font-weight:700;padding:.2rem .6rem;border-radius:5px">Últimas 20 facturas</span>
            @else
            <span style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);font-size:.68rem;font-weight:700;padding:.2rem .6rem;border-radius:5px">{{ collect($agrupado)->flatten(2)->count() }} facturas</span>
            @endif
        </div>
    </div>
</div>

{{-- FILTROS --}}
<form method="GET" class="hi-filtros" id="formFiltro">
    <div style="display:flex;flex-direction:column;gap:.15rem">
        <label>Año</label>
        <select name="anio" class="hi-sel" onchange="document.getElementById('formFiltro').submit()">
            <option value="0" {{ !$filtroAnio ? 'selected' : '' }}>Todos</option>
            @foreach($aniosDisp as $a)
            <option value="{{ $a }}" {{ $filtroAnio == $a ? 'selected' : '' }}>{{ $a }}</option>
            @endforeach
        </select>
    </div>
    <div style="display:flex;flex-direction:column;gap:.15rem">
        <label>Razón Social</label>
        <select name="razon_social_id" class="hi-sel" onchange="document.getElementById('formFiltro').submit()">
            <option value="" {{ $filtroRs === '' ? 'selected' : '' }}>Todas</option>
            @foreach($rsSocDisp as $rs)
            <option value="{{ $rs['id'] }}" {{ $filtroRs == $rs['id'] ? 'selected' : '' }}>{{ $rs['label'] }}</option>
            @endforeach
        </select>
    </div>
    @if(!$sinFiltros)
    <a href="{{ route('admin.facturacion.historial', $cedula) }}" class="hi-pill active" style="margin-top:1rem">✕ Limpiar filtros</a>
    @endif
</form>

{{-- CONTENIDO AGRUPADO POR CONTRATO Y AÑO --}}
@forelse($agrupado as $grupoKey => $porAnio)
@php $totalGrupo = collect($porAnio)->flatten()->sum('total'); $countGrupo = collect($porAnio)->flatten()->count(); @endphp
<div class="hi-group open">
    <div class="hi-group-hdr" onclick="toggleGrupo(this)">
        <div class="hi-group-title">
            📝 {{ $grupoKey }}
            <span class="hi-group-badge">{{ $countGrupo }} factura(s)</span>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem">
            <span class="hi-group-total">{{ $fmt($totalGrupo) }}</span>
            <span class="hi-group-caret">▼</span>
        </div>
    </div>
    <div class="hi-group-body">
        @foreach($porAnio as $anio => $facturas)
        @php $totalAnio = collect($facturas)->sum('total'); @endphp
        <div class="hi-year">
            <span>📅 {{ $anio }}</span>
            <span class="hi-year-total">{{ count($facturas) }} fact. · {{ $fmt($totalAnio) }}</span>
        </div>
        <table class="hi-tbl">
            <thead><tr>
                <th>N° Recibo</th>
                <th>Fecha</th>
                <th>Servicio</th>
                <th>Tipo</th>
                <th class="num">Total</th>
                <th>Estado</th>
                <th>Plano</th>
                <th>Origen</th>
                <th>N° Planilla</th>
                <th>Operador</th>
                <th>Acciones</th>
            </tr></thead>
            <tbody>
            @foreach($facturas as $f)
            @php
            $estadoBadge = match($f->estado) {
                'pagada'      => ['badge-pago',  '✅ Pagada'],
                'pre_factura' => ['badge-pre',   '📋 Pre-factura'],
                'prestamo'    => ['badge-prest', '💳 Préstamo'],
                'abono'       => ['badge-abono', '💰 Abono'],
                default       => ['badge-pre',   ucfirst($f->estado)],
            };
            $esTramite = $f->tipo === 'otro_ingreso';
            $esRetiro = !$esTramite && (((int)$f->numero_factura === 0) || ($f->plano && !is_null($f->plano->fecha_ret)));
            $esRetiroReal = $esRetiro && (int)($f->dias_cotizados ?? 0) > 0;
            $tipoBadge = $esRetiro
                ? ($esRetiroReal
                    ? ['badge-ret',      '🛑 Retiro Real']
                    : ['badge-ret-info', '🔕 Retiro Informativo'])
                : ($f->tipo === 'afiliacion'
                    ? ['badge-afil', '📌 Afiliación']
                    : ($esTramite
                        ? ['badge-tram', '💼 Trámite']
                        : ['badge-plan', '📄 Planilla']));

            // Mes que cubre el plano PILA. No es el de la columna Servicio: un cobro
            // de agosto paga la planilla de julio (salvo Indep. Mes Actual). Se anexa
            // al badge para no tener que abrir el plano y verlo. En afiliación no,
            // que ahí ambos meses son el mismo.
            $mesPlano = '';
            if ($f->plano && (int)$f->plano->mes_plano) {
                $mesPlano = mb_strtolower($meses_full[(int)$f->plano->mes_plano] ?? '');
                if ($mesPlano && (int)$f->plano->anio_plano !== (int)$f->anio) {
                    $mesPlano .= ' ' . $f->plano->anio_plano;
                }
            }
            if ($mesPlano && ($esRetiro || $f->tipo === 'planilla')) {
                $tipoBadge[1] .= ' ' . $mesPlano;
            }
            // Título del modal del plano: su propio período, no el del recibo.
            $periodoPlanoLabel = $f->plano && (int)$f->plano->mes_plano
                ? (($meses[(int)$f->plano->mes_plano] ?? '') . ' ' . $f->plano->anio_plano)
                : (($meses[$f->mes] ?? '') . ' ' . $f->anio);

            $esPorEmpresa = !is_null($f->empresa_id);
            // N° Planilla del operador (si el plano fue pagado al operador)
            $numeroPlanillaOp = $f->plano?->numero_planilla;
            // Puede anular: admin/superadmin + si tiene planilla solo superadmin BryNex
            $puedeAnular = $esAdmin && (!$numeroPlanillaOp || $esSuperBrynex);
            
             // Nombre y ID del operador
             $nombreOp = '—';
             $operadorId = null;
             if ($numeroPlanillaOp) {
                 $opInfo = $operadoresPlanillaInfo->get($numeroPlanillaOp);
                 $nombreOp = $opInfo?->nombre 
                          ?? $gastosPlanilla->get($numeroPlanillaOp)?->pagado_a 
                          ?? $cliente->operadorPlanilla?->nombre 
                          ?? 'Desconocido';
                 
                 // Buscar ID del operador basado en el nombre registrado en gastos si no está en la API
                 $gastoOpNombre = $gastosPlanilla->get($numeroPlanillaOp)?->pagado_a;
                 $operadorId = $opInfo?->id 
                            ?? ($gastoOpNombre ? ($operadoresTodosMap->get(mb_strtoupper(trim($gastoOpNombre))) ?? null) : null)
                            ?? $cliente->operador_planilla_id;
             }
             @endphp
            @php
            $vSS = ($f->v_eps ?? 0) + ($f->v_afp ?? 0) + ($f->v_arl ?? 0) + ($f->v_caja ?? 0);
            // Datos normalizados del plano para el modal — evita serializar la relación completa
            $planoModal = null;
            if ($f->plano) {
                $p = $f->plano;
                $ibcSalud = (float)($p->ibc_salud ?? 0);
                // Tarifas calculadas desde valores de factura ÷ IBC (la tabla planos no las almacena)
                $tarEps   = $ibcSalud > 0 ? round(($f->v_eps  ?? 0) / $ibcSalud * 100, 2) : 0;
                $tarAfp   = $ibcSalud > 0 ? round(($f->v_afp  ?? 0) / $ibcSalud * 100, 2) : 0;
                $tarArl   = $ibcSalud > 0 ? round(($f->v_arl  ?? 0) / $ibcSalud * 100, 2) : 0;
                $tarCaja  = $ibcSalud > 0 ? round(($f->v_caja ?? 0) / $ibcSalud * 100, 2) : 0;
                $planoModal = [
                    // Identificación
                    'razon_social_nombre' => $p->razon_social // campo string legacy
                                         ?? $f->plano->razonSocial?->razon_social
                                         ?? $f->razonSocial?->razon_social
                                         ?? '—',
                    'n_plano'      => $p->n_plano,
                    'tipo_p'       => $p->tipo_p ?? $p->tipo_reg,
                    // Días: num_dias es el campo real de la tabla planos
                    'num_dias'     => (int)($p->num_dias ?? $f->dias_cotizados ?? 0),
                    'ibc_salud'    => $ibcSalud,
                    'salario_basico' => (float)($p->salario_basico ?? 0),
                    'ing'          => (bool)($p->ing ?? false),
                    'fecha_ing'    => $p->fecha_ing ?? null,
                    // Entidades (snapshots del plano)
                    'nombre_eps'   => $p->nombre_eps,
                    'cod_eps'      => $p->cod_eps,
                    'nombre_afp'   => $p->nombre_afp,
                    'cod_afp'      => $p->cod_afp,
                    'nombre_arl'   => $p->nombre_arl,
                    'cod_arl'      => $p->cod_arl,
                    'nivel_riesgo' => $p->nivel_riesgo ?? '',
                    'nombre_caja'  => $p->nombre_caja,
                    'cod_caja'     => $p->cod_caja,
                    // Valores SS — tomados de la factura (fuente de verdad)
                    'val_eps'      => (float)($f->v_eps  ?? 0),
                    'val_afp'      => (float)($f->v_afp  ?? 0),
                    'val_arl'      => (float)($f->v_arl  ?? 0),
                    'val_caja'     => (float)($f->v_caja ?? 0),
                    'total_cot'    => (float)($f->total_ss ?? $vSS),
                    // Tarifas calculadas
                    'tar_salud'    => $tarEps,
                    'tar_pension'  => $tarAfp,
                    'tar_arl'      => $tarArl,
                    'tar_caja'     => $tarCaja,
                ];
            }
            @endphp
            <tr>
                <td style="font-family:monospace;font-weight:800;color:#1d4ed8;font-size:.76rem">
                    #{{ $f->numero_factura ?? '—' }}
                    <div style="margin-top:.15rem">@include('admin.facturacion._fe_icono', ['contexto' => 'cliente'])</div>
                </td>
                <td style="color:#64748b;font-size:.72rem">{{ $f->fecha_pago?->format('d/m/Y') ?? '—' }}</td>
                <td style="font-weight:600;color:#0f172a">
                    {{ $meses[$f->mes] ?? '' }} {{ $f->anio }}
                    @if($esTramite && filled($f->descripcion_tramite))
                    <div class="hi-tram-desc" title="{{ $f->descripcion_tramite }}">💼 {{ $f->descripcion_tramite }}</div>
                    @endif
                    @if($esTramite && filled($f->observacion))
                    <div class="hi-tram-obs" title="{{ $f->observacion }}">🗒 {{ $f->observacion }}</div>
                    @endif
                </td>
                <td><span class="badge {{ $tipoBadge[0] }}">{{ $tipoBadge[1] }}</span></td>
                <td class="num" style="font-weight:900;color:{{ $f->estado==='pagada'?'#16a34a':'#0f172a' }}">{{ $fmt($f->total) }}</td>
                <td><span class="badge {{ $estadoBadge[0] }}">{{ $estadoBadge[1] }}</span></td>
                {{-- Número de Plano (NP) --}}
                <td style="font-weight:700;color:#1d4ed8;font-family:monospace;font-size:.76rem">{{ $f->n_plano ?? '—' }}</td>
                {{-- Origen --}}
                <td>
                    @if($esPorEmpresa)
                    <span class="badge badge-emp" title="{{ $f->empresa?->empresa ?? 'Empresa' }}">
                        🏢 {{ Str::limit($f->empresa?->empresa ?? 'Empresa', 12) }}
                    </span>
                    @else
                    <span class="badge badge-ind">👤 Individual</span>
                    @endif
                </td>
                {{-- Nº Planilla operador --}}
                <td style="white-space:nowrap">
                    @if($numeroPlanillaOp)
                    <span class="badge-planilla" title="Planilla pagada al operador">
                        📄 {{ $numeroPlanillaOp }}
                    </span>
                    @php $soporte = $soportesPlanilla[$numeroPlanillaOp] ?? null; @endphp
                    @if($soporte)
                    <button class="btn-soporte-planilla"
                            onclick="verSoportePlanilla(
                                '{{ Storage::url($soporte->imagen_path) }}',
                                '{{ addslashes($numeroPlanillaOp) }}',
                                '{{ addslashes($soporte->pagado_a ?? '') }}'
                            )"
                            title="Ver comprobante de pago planilla #{{ $numeroPlanillaOp }}">
                        🖼️
                    </button>
                    @endif
                    @else
                    <span style="color:#cbd5e1;font-size:.7rem">—</span>
                    @endif
                </td>
                {{-- Operador --}}
                <td>
                    @if($numeroPlanillaOp)
                        <span style="font-size:0.7rem; color:#475569; font-weight:600;">{{ Str::limit($nombreOp, 15) }}</span>
                    @else
                        <span style="color:#cbd5e1;font-size:.7rem">—</span>
                    @endif
                </td>
                <td>
                    <div style="display:flex;gap:.3rem;align-items:center;justify-content:center;flex-wrap:wrap">
                        {{-- Recibo --}}
                        <button onclick="abrirRecibo(
                                    '{{ route('admin.facturacion.recibo', $f->id) }}?modal=1&individual=1',
                                    {{ $planoModal ? json_encode($planoModal) : 'null' }},
                                    '{{ $periodoPlanoLabel }}'
                                )"
                                class="btn-act-sm btn-recibo" title="Ver recibo">
                            📄 Recibo
                        </button>
                        
                        {{-- PDF Planilla: la real del operador (ARUS/Simple) si se puede; si no, la de BryNex --}}
                        @php
                            $verif = $numeroPlanillaOp ? $verificacionPlanillas->get($numeroPlanillaOp) : null;
                            $pagoOp = $numeroPlanillaOp ? $pagosOperador->get($numeroPlanillaOp) : null;
                            $tituloPlanilla = $pagoOp
                                ? 'Planilla real del operador · pagada '.sqldate($pagoOp, 'd/m/Y H:i:s')
                                : (str_contains(mb_strtoupper($nombreOp), 'MI PLANILLA') ? 'Descargar la planilla real de Mi Planilla'
                                : ($aliadoConSoporteOperador ? 'Descargar la planilla del operador (si no está, se genera la de BryNex)' : 'Descargar PDF Planilla'));
                        @endphp
                        @if($numeroPlanillaOp && (int) $operadorId === $miPlanillaId && !$tieneClaveMiPlanilla)
                        {{-- Mi Planilla sin la clave de la persona: se pide, se prueba y se guarda --}}
                        <button type="button" class="btn-act-sm btn-planilla-mp" style="background:#0f172a;color:#fff;border-color:#0f172a;"
                                data-url="{{ route('admin.planos.certificado_pdf') }}?cedula={{ $f->cedula }}&numero_planilla={{ urlencode($numeroPlanillaOp) }}&forzar_operador_id={{ $operadorId }}"
                                onclick="pedirClaveMiPlanilla(this.dataset.url)"
                                title="Falta la clave de Mi Planilla de esta persona: se pide una vez y queda guardada">
                            ⬇️ Planilla
                        </button>
                        @elseif($numeroPlanillaOp && in_array((int) $operadorId, $operadoresConPlanilla, true))
                        <a href="{{ route('admin.planos.certificado_pdf') }}?cedula={{ $f->cedula }}&numero_planilla={{ urlencode($numeroPlanillaOp) }}{{ $operadorId ? '&forzar_operador_id=' . $operadorId : '' }}"
                           onclick="return abrirPlanilla(event, this.href)"
                           target="_blank" class="btn-act-sm" style="background:#0f172a;color:#fff;border-color:#0f172a;" title="{{ $tituloPlanilla }}">
                            ⬇️ Planilla
                        </a>
                        @endif
                        @if($verif && in_array($verif->estado, ['no_encontrada', 'invalida']))
                        <span class="btn-act-sm" style="background:#fef2f2;color:#b91c1c;border-color:#f87171;cursor:help"
                              title="{{ $verif->estado === 'invalida' ? 'No es un número de planilla: corrígelo en la confirmación del pago.' : 'La planilla '.$numeroPlanillaOp.' no aparece en el operador: revisa el número. '.$verif->mensaje }}">⚠️ No cruza</span>
                        @elseif($verif && $verif->estado === 'sin_acceso')
                        <span class="btn-act-sm" style="background:#fffbeb;color:#92400e;border-color:#fbbf24;cursor:help"
                              title="No se pudo verificar en el operador: {{ $verif->mensaje }}">🔒 Sin acceso</span>
                        @elseif($verif && $verif->estado === 'verificando')
                        <span class="btn-act-sm" style="background:#f8fafc;color:#475569;border-color:#cbd5e1;cursor:help"
                              title="{{ $verif->mensaje }}">⏳ Verificando</span>
                        @endif
                        
                        @if($f->plano)
                        <button type="button" class="btn-act-sm btn-plano"
                            onclick="verPlano({{ $planoModal ? json_encode($planoModal) : 'null' }}, '{{ $periodoPlanoLabel }}')"
                            title="Ver datos del plano — también disponible dentro del recibo">
                            📋
                        </button>
                        @endif
                        {{-- Anular --}}
                        @if($puedeAnular)
                        <button type="button" class="btn-act-sm btn-anular"
                            onclick="abrirAnular({{ $f->id }}, '{{ $f->numero_factura }}', {{ $numeroPlanillaOp ? 'true' : 'false' }})">
                            ⛔ Anular
                        </button>
                        @elseif($esAdmin && $numeroPlanillaOp)
                        <span class="badge-planilla-lock" title="Solo superadmin BryNex puede anular. Planilla: {{ $numeroPlanillaOp }}">
                            🔒 Planilla pagada
                        </span>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
        @endforeach
    </div>
</div>
@empty
<div class="hi-empty">
    <div style="font-size:2.5rem;margin-bottom:.5rem">📭</div>
    <div style="font-weight:700;color:#475569">Sin facturas registradas</div>
    <div style="margin-top:.3rem;font-size:.8rem">Este cliente no tiene facturas en el sistema.</div>
</div>
@endforelse

{{-- ══════════════════════ MODAL PLANO ══════════════════════ --}}
<div id="modal-plano-overlay" style="display:none" onclick="if(event.target===this)cerrarPlano()">
<div id="modal-plano-box">
    <div class="mp-hdr">
        <span class="mp-title">📋 Datos del Plano — <span id="mp-periodo"></span></span>
        <button class="mp-close" onclick="cerrarPlano()">✕</button>
    </div>
    <div class="mp-body">
        {{-- Identificación --}}
        <div class="mp-sec">Identificación y Período</div>
        <div class="mp-grid">
            <div class="mp-row" style="grid-column:1/-1">
                <span class="mp-lbl">Razón Social</span>
                <span class="mp-val" id="mp-rs">—</span>
            </div>
            <div class="mp-row"><span class="mp-lbl">N° Plano</span><span class="mp-val" id="mp-nplano">—</span></div>
            <div class="mp-row"><span class="mp-lbl">Tipo</span><span class="mp-val" id="mp-tipo">—</span></div>
            <div class="mp-row"><span class="mp-lbl">Días cotizados</span><span class="mp-val" id="mp-dias">—</span></div>
            <div class="mp-row"><span class="mp-lbl">IBC Salud</span><span class="mp-val" id="mp-ibc">—</span></div>
            <div class="mp-row"><span class="mp-lbl">Salario base</span><span class="mp-val" id="mp-sal">—</span></div>
            <div class="mp-row"><span class="mp-lbl">Novedad Ingreso</span><span class="mp-val" id="mp-ing">—</span></div>
            <div class="mp-row"><span class="mp-lbl">Fecha ingreso</span><span class="mp-val" id="mp-fing">—</span></div>
        </div>

        {{-- Entidades --}}
        <div class="mp-sec">Seguridad Social</div>
        <table style="width:100%;border-collapse:collapse;font-size:.75rem">
            <thead>
                <tr style="background:#f8fafc">
                    <th style="padding:.28rem .5rem;text-align:left;font-size:.6rem;color:#94a3b8;text-transform:uppercase">Fondo</th>
                    <th style="padding:.28rem .5rem;text-align:left;font-size:.6rem;color:#94a3b8;text-transform:uppercase">Entidad</th>
                    <th style="padding:.28rem .5rem;text-align:right;font-size:.6rem;color:#94a3b8;text-transform:uppercase">Tarifa %</th>
                    <th style="padding:.28rem .5rem;text-align:right;font-size:.6rem;color:#94a3b8;text-transform:uppercase">Valor</th>
                </tr>
            </thead>
            <tbody>
                <tr id="mp-row-eps"><td colspan="4" style="padding:.28rem .5rem;color:#94a3b8;font-size:.72rem">—</td></tr>
                <tr id="mp-row-afp"></tr>
                <tr id="mp-row-arl"></tr>
                <tr id="mp-row-caja"></tr>
            </tbody>
        </table>

        {{-- Total --}}
        <div class="mp-total">
            <span class="mp-total-lbl">Total cotización</span>
            <span class="mp-total-val" id="mp-total-cot">$0</span>
        </div>
    </div>
</div>
</div>

{{-- ══ SECCIÓN ANTICIPOS DEL CLIENTE ══════════════════════════════════════════ --}}
<div style="margin-top:1.2rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.65rem;">
        <div style="font-size:.8rem;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:.4rem;">
            💰 Anticipos del Cliente
        </div>
        <button id="btn-toggle-anticipos"
            onclick="toggleAnticiposCliente()"
            style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .85rem;border-radius:20px;font-size:.72rem;font-weight:700;border:1.5px solid #d97706;background:#fff;color:#92400e;cursor:pointer;transition:all .15s;">
            💰 Ver Anticipos
        </button>
    </div>

    <div id="sec-anticipos-cliente" style="display:none;">
        <div id="ant-cli-loading" style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.82rem;">
            ⏳ Cargando anticipos...
        </div>
        <div id="ant-cli-wrap" style="display:none;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04);">
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                <thead>
                    <tr style="background:#fef3c7;">
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;text-align:left;white-space:nowrap;">Fecha</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;text-align:left;">Forma</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;text-align:right;">Valor</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;text-align:right;">Aplicado</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;text-align:right;">Disponible</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;">Estado</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;">Factura</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;">Plan</th>
                        <th style="padding:.45rem .75rem;font-size:.6rem;font-weight:800;color:#78350f;text-transform:uppercase;letter-spacing:.05em;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="ant-cli-tbody"></tbody>
            </table>
            <div id="ant-cli-empty" style="display:none;padding:2rem;text-align:center;color:#94a3b8;font-size:.85rem;">
                <div style="font-size:1.8rem;margin-bottom:.5rem;">💰</div>
                Este cliente no tiene anticipos registrados.
            </div>
        </div>
    </div>
</div>
{{-- ══ FIN SECCIÓN ANTICIPOS ══════════════════════════════════════════════ --}}

@push('scripts')
<script>
const HI_MESES = @json($meses_full);
const HI_FMT   = v => '$' + Math.ceil(v||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.');
const ANULAR_URL = '{{ rtrim(route("admin.facturacion.anular", ["id" => 0]), "/0") }}';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
const CEDULA_CLIENTE = '{{ $cedula }}';
const ANTICIPO_CEDULA_URL = '{{ rtrim(route("admin.anticipos.api.cliente", ["cedula" => "CEDULA_PLH"]), "/CEDULA_PLH") }}';

// ── Anticipos del cliente ──────────────────────────────────────────
let _anticipsLoaded = false;

function toggleAnticiposCliente() {
    const sec = document.getElementById('sec-anticipos-cliente');
    const btn = document.getElementById('btn-toggle-anticipos');
    const visible = sec.style.display !== 'none';

    if (visible) {
        sec.style.display = 'none';
        btn.textContent = '💰 Ver Anticipos';
        btn.style.background = '#fff';
        btn.style.color = '#92400e';
    } else {
        sec.style.display = 'block';
        btn.textContent = '▲ Ocultar Anticipos';
        btn.style.background = '#fef3c7';
        btn.style.color = '#78350f';
        if (!_anticipsLoaded) cargarAnticiposCliente();
    }
}

async function cargarAnticiposCliente() {
    const url = ANTICIPO_CEDULA_URL + '/' + CEDULA_CLIENTE;
    try {
        const resp = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN }
        });
        const data = await resp.json();
        _anticipsLoaded = true;

        document.getElementById('ant-cli-loading').style.display = 'none';
        document.getElementById('ant-cli-wrap').style.display = 'block';

        const tbody = document.getElementById('ant-cli-tbody');
        const empty = document.getElementById('ant-cli-empty');

        if (!data.ok || !data.anticipos || data.anticipos.length === 0) {
            empty.style.display = 'block';
            return;
        }

        const estadoBadge = {
            disponible: 'background:#dcfce7;color:#15803d',
            parcial:    'background:#fef3c7;color:#92400e',
            aplicado:   'background:#f1f5f9;color:#64748b',
            devuelto:   'background:#fee2e2;color:#991b1b',
            anulado:    'background:#fce7f3;color:#831843;text-decoration:line-through',
            distribuido:'background:#ede9fe;color:#6d28d9',
        };

        const formaEmoji = {
            efectivo: '💵', transferencia: '↔️', nequi: '📱', consignacion: '🏦'
        };

        tbody.innerHTML = data.anticipos.map(a => {
            const badge = estadoBadge[a.estado] || estadoBadge.aplicado;
            const emoji = formaEmoji[a.forma_pago] || '💳';
            const facturaHtml = a.factura_numero
                ? `<a href="${a.factura_recibo_url}" target="_blank"
                      style="font-size:.68rem;color:#1d4ed8;font-weight:700;text-decoration:none;
                             background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:.08rem .3rem;">
                      #${a.factura_numero} <span style="opacity:.7">${a.factura_mes}/${a.factura_anio}</span>
                   </a>`
                : `<span style="color:#94a3b8;font-size:.68rem;">—</span>`;

            const anuladoStyle = a.anulado ? 'opacity:.7;' : '';

            return `<tr style="border-bottom:1px solid #f1f5f9;${a.anulado ? 'background:#fff5f5;' : ''}">
                <td style="padding:.38rem .75rem;font-family:monospace;font-size:.72rem;${anuladoStyle}">${a.fecha_pago}</td>
                <td style="padding:.38rem .75rem;">
                    <span style="display:inline-flex;align-items:center;gap:.2rem;font-size:.68rem;font-weight:700;
                                 color:#475569;background:#f8fafc;border:1px solid #e2e8f0;padding:.06rem .35rem;border-radius:4px;">
                        ${emoji} ${a.forma_label}
                    </span>
                </td>
                <td style="padding:.38rem .75rem;text-align:right;font-family:monospace;font-weight:700;color:#1e40af;font-size:.72rem;${a.anulado ? 'text-decoration:line-through;opacity:.6;' : ''}">
                    ${HI_FMT(a.valor)}
                </td>
                <td style="padding:.38rem .75rem;text-align:right;font-family:monospace;font-size:.72rem;color:#15803d;">
                    ${a.valor_aplicado > 0 ? HI_FMT(a.valor_aplicado) : '<span style="color:#94a3b8;">—</span>'}
                </td>
                <td style="padding:.38rem .75rem;text-align:right;font-family:monospace;font-size:.72rem;color:#d97706;font-weight:800;">
                    ${(!a.anulado && a.valor_disponible > 0) ? HI_FMT(a.valor_disponible) : '<span style="color:#94a3b8;">$0</span>'}
                </td>
                <td style="padding:.38rem .75rem;">
                    <span style="display:inline-flex;align-items:center;padding:.12rem .45rem;border-radius:20px;font-size:.62rem;font-weight:800;${badge}">
                        ${a.estado_label}
                    </span>
                    ${a.motivo_anulacion ? `<div style="font-size:.58rem;color:#94a3b8;margin-top:.1rem;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${a.motivo_anulacion}">${a.motivo_anulacion}</div>` : ''}
                </td>
                <td style="padding:.38rem .75rem;">${facturaHtml}</td>
                <td style="padding:.38rem .75rem;font-size:.68rem;color:#64748b;">${a.plan_nombre || '—'}</td>
                <td style="padding:.38rem .75rem;">
                    <a href="${a.recibo_url}" target="_blank"
                       style="display:inline-flex;align-items:center;gap:.2rem;padding:.18rem .48rem;border-radius:5px;
                              font-size:.65rem;font-weight:700;text-decoration:none;
                              background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                        🧾 Recibo
                    </a>
                </td>
            </tr>`;
        }).join('');

    } catch(e) {
        document.getElementById('ant-cli-loading').innerHTML =
            `<span style="color:#be123c;">❌ Error al cargar anticipos: ${e.message}</span>`;
    }
}


function toggleGrupo(hdr) {
    hdr.closest('.hi-group').classList.toggle('open');
}

function cerrarPlano() {
    document.getElementById('modal-plano-overlay').style.display = 'none';
}

function verPlano(p, periodo) {
    document.getElementById('mp-periodo').textContent = periodo;
    document.getElementById('mp-rs').textContent      = p.razon_social_nombre || '—';
    document.getElementById('mp-nplano').textContent  = p.n_plano || '—';
    document.getElementById('mp-tipo').textContent    = p.tipo_p || '—';
    document.getElementById('mp-dias').textContent    = (p.num_dias || 0) + ' días';
    document.getElementById('mp-ibc').textContent     = HI_FMT(p.ibc_salud);
    document.getElementById('mp-sal').textContent     = HI_FMT(p.salario_basico);
    document.getElementById('mp-ing').textContent     = p.ing ? 'Sí (Afiliación)' : 'No';
    document.getElementById('mp-fing').textContent    = p.fecha_ing || '—';

    const fila = (cod, nombre, tar, val) =>
        `<td style="padding:.28rem .5rem;font-weight:600">${cod || '—'}</td>
         <td style="padding:.28rem .5rem">${nombre || '—'}</td>
         <td style="padding:.28rem .5rem;text-align:right;font-family:monospace">${((tar||0)).toFixed(2)}%</td>
         <td style="padding:.28rem .5rem;text-align:right;font-family:monospace;font-weight:800">${HI_FMT(val)}</td>`;

    document.getElementById('mp-row-eps').innerHTML  = fila('EPS',    p.nombre_eps  || p.cod_eps,  p.tar_salud,   p.val_eps);
    document.getElementById('mp-row-afp').innerHTML  = fila('Pensión',p.nombre_afp  || p.cod_afp,  p.tar_pension, p.val_afp);
    document.getElementById('mp-row-arl').innerHTML  = fila('ARL Nv'+p.nivel_riesgo, p.nombre_arl||p.cod_arl, p.tar_arl, p.val_arl);
    document.getElementById('mp-row-caja').innerHTML = fila('Caja',   p.nombre_caja || p.cod_caja, p.tar_caja,    p.val_caja);

    document.getElementById('mp-total-cot').textContent = HI_FMT(p.total_cot);
    document.getElementById('modal-plano-overlay').style.display = 'flex';
}
// ── Modal Recibo ──────────────────────────────────
let _planoDatos = null, _planoPeriodo = '';
function abrirRecibo(url, planoJson, periodo) {
    document.getElementById('recibo-frame').src = url;
    document.getElementById('recibo-modal-ov').style.display = 'flex';
    // Botón plano en el header del recibo
    _planoDatos  = planoJson || null;
    _planoPeriodo = periodo || '';
    const btnP = document.getElementById('btn-plano-recibo');
    if (btnP) btnP.style.display = _planoDatos ? 'inline-flex' : 'none';
}
function _verPlanoDesdeRecibo() {
    if (_planoDatos) verPlano(_planoDatos, _planoPeriodo);
}
function cerrarRecibo(huboCambio = false) {
    document.getElementById('recibo-modal-ov').style.display = 'none';
    document.getElementById('recibo-frame').src = '';
    _planoDatos = null;
    const btnP = document.getElementById('btn-plano-recibo');
    if (btnP) btnP.style.display = 'none';

    if (huboCambio) {
        if (window.parent && typeof window.parent.registrarCambioHistorial === 'function') {
            window.parent.registrarCambioHistorial();
        }
        location.reload();
    }
}

// ── Modal Anular ─────────────────────────────────────
let _anularId = null;
function abrirAnular(id, nroFactura, tienePlanilla) {
    _anularId = id;
    document.getElementById('anular-nro').textContent = '#' + nroFactura;
    document.getElementById('anular-adv-planilla').style.display = tienePlanilla ? 'block' : 'none';
    document.getElementById('anular-motivo').value = '';
    document.getElementById('modal-anular-ov').style.display = 'flex';
}
function cerrarAnular() {
    document.getElementById('modal-anular-ov').style.display = 'none';
    _anularId = null;
}
// `conf` acumula las confirmaciones que pide el servidor (planilla pagada,
// factura electrónica): cada 409 dice qué casilla falta y se reenvía con ella.
async function confirmarAnular(conf = {}) {
    const motivo = document.getElementById('anular-motivo').value.trim();
    if (!motivo) { alert('Debe indicar el motivo de anulación.'); return; }
    const btn = document.getElementById('btn-ejecutar-anular');
    btn.disabled = true; btn.textContent = '⏳ Anulando...';
    try {
        const resp = await fetch(`${ANULAR_URL}/${_anularId}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ motivo, ...conf }),
        });
        const data = await resp.json();
        // Planilla pagada al operador o factura electrónica emitida: se confirma
        // viendo el detalle y se reenvía con la casilla que pidió el servidor.
        if (!data.ok && data.requiere_confirmacion) {
            btn.disabled = false; btn.textContent = '⛔ Confirmar Anulación';
            const campo = data.campo_confirmacion || 'confirmar_planilla';
            const detalle = (data.afectados || []).join('\n • ');
            const aviso = data.aviso || 'Acepte solo si está seguro: tendrá que re-vincular la planilla a mano después de re-facturar.';
            if (confirm(data.message + '\n\n • ' + detalle + '\n\n' + aviso)) {
                btn.disabled = true; btn.textContent = '⏳ Anulando...';
                return confirmarAnular({ ...conf, [campo]: true });
            }
            return;
        }
        if (data.ok) {
            cerrarAnular();
            const toast = document.createElement('div');
            toast.textContent = '✅ ' + data.mensaje;
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;background:#0f172a;color:#e2e8f0;border-left:4px solid #10b981;border-radius:10px;padding:.65rem 1.1rem;font-size:.82rem;font-weight:500;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.3)';
            document.body.appendChild(toast);

            if (window.parent && typeof window.parent.registrarCambioHistorial === 'function') {
                window.parent.registrarCambioHistorial();
            }

            setTimeout(() => location.reload(), 1800);
        } else {
            const detalle = (data.afectados || []).length ? '\n\n • ' + data.afectados.join('\n • ') : '';
            alert('❌ ' + (data.message || data.mensaje || 'Error al anular.') + detalle);
            btn.disabled = false; btn.textContent = '⛔ Confirmar Anulación';
        }
    } catch(e) {
        alert('Error de conexión: ' + e.message);
        btn.disabled = false; btn.textContent = '⛔ Confirmar Anulación';
    }
}
// ── Clave de Mi Planilla ───────────────────────────────
// La planilla real de Mi Planilla se baja con la cuenta de la persona. Si no
// está guardada se pide aquí; el servidor la prueba en el portal antes de
// guardarla, y desde ahí los botones descargan directo.
const MP_CLAVE_URL = '{{ route('admin.planos.miplanilla_clave') }}';
let _mpUrl = null;
function pedirClaveMiPlanilla(url) {
    _mpUrl = url;
    document.getElementById('mp-error').style.display = 'none';
    document.getElementById('mp-descargar').style.display = 'none';
    document.getElementById('mp-form').style.display = 'block';
    document.getElementById('mp-contrasena').value = '';
    document.getElementById('modal-mp-ov').style.display = 'flex';
    setTimeout(() => document.getElementById('mp-contrasena').focus(), 50);
}
function cerrarClaveMiPlanilla() {
    document.getElementById('modal-mp-ov').style.display = 'none';
}
async function guardarClaveMiPlanilla(ev) {
    ev.preventDefault();
    const btn = document.getElementById('mp-guardar');
    const err = document.getElementById('mp-error');
    err.style.display = 'none';
    btn.disabled = true; btn.textContent = '⏳ Probando en Mi Planilla...';
    try {
        const resp = await fetch(MP_CLAVE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                cedula: {{ (int) $cedula }},
                usuario: document.getElementById('mp-usuario').value.trim(),
                contrasena: document.getElementById('mp-contrasena').value,
            }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || !data.ok) {
            const errores = data.errors ? Object.values(data.errors).flat().join(' ') : '';
            throw new Error(data.message && !errores ? data.message : (errores || 'No se pudo guardar la clave.'));
        }
        // Ya hay clave: todos los botones de Mi Planilla pasan a descargar directo.
        document.querySelectorAll('.btn-planilla-mp').forEach(b => {
            b.onclick = (ev) => abrirPlanilla(ev, b.dataset.url);
            b.title = 'Descargar la planilla real de Mi Planilla';
        });
        // Sigue de una vez con la descarga, mostrando los pasos.
        cerrarClaveMiPlanilla();
        abrirPlanilla(null, _mpUrl);
    } catch (e) {
        err.textContent = '❌ ' + e.message;
        err.style.display = 'block';
    } finally {
        btn.disabled = false; btn.textContent = '💾 Guardar y descargar';
    }
}

// ── Descarga de la planilla con sus pasos ──────────────
// El servidor anota en qué va (ingresando, buscando, descargando) y aquí se
// consulta cada medio segundo, con los segundos de cada paso. Al final el PDF
// se ve en el mismo recuadro, con botones para abrirlo o guardarlo.
const PL_PROGRESO_URL = '{{ route('admin.planos.certificado_pdf.progreso') }}';
let _plCorrida = 0, _plBlob = null;
function abrirPlanilla(ev, url) {
    // Ctrl/Cmd + clic o clic del medio: se deja abrir directo en otra pestaña.
    if (ev && (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1)) return true;
    if (ev) ev.preventDefault();

    const corrida = ++_plCorrida;
    const token = Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
    const inicio = performance.now();
    const seg = () => ((performance.now() - inicio) / 1000).toFixed(1);
    const pasos = document.getElementById('pl-pasos');
    const reloj = document.getElementById('pl-reloj');
    let inicioPaso = inicio, vistos = 0;

    if (_plBlob) { URL.revokeObjectURL(_plBlob); _plBlob = null; }
    pasos.innerHTML = '';
    document.getElementById('pl-error').style.display = 'none';
    document.getElementById('pl-visor').style.display = 'none';
    document.getElementById('pl-visor').src = 'about:blank';
    document.getElementById('pl-acciones').style.display = 'none';
    document.getElementById('pl-titulo').textContent = '⏳ Descargando planilla…';
    document.getElementById('modal-pl-ov').style.display = 'flex';

    const cerrarPaso = (estado) => {
        const li = pasos.lastElementChild;
        if (!li) return;
        li.querySelector('.pl-ico').textContent = estado === 'ok' ? '✅' : '❌';
        li.querySelector('.pl-seg').textContent = ((performance.now() - inicioPaso) / 1000).toFixed(1) + ' s';
    };
    const nuevoPaso = (texto) => {
        cerrarPaso('ok');
        inicioPaso = performance.now();
        const li = document.createElement('li');
        li.style.cssText = 'display:flex;align-items:center;gap:.5rem;padding:.3rem 0;font-size:.82rem;color:#334155;border-bottom:1px dashed #e2e8f0';
        li.innerHTML = '<span class="pl-ico">⏳</span><span style="flex:1"></span><span class="pl-seg" style="font-family:monospace;color:#94a3b8;font-size:.75rem"></span>';
        li.children[1].textContent = texto;
        pasos.appendChild(li);
    };
    nuevoPaso('Conectando con BryNex');

    const tic = setInterval(() => {
        if (corrida !== _plCorrida) return clearInterval(tic);
        reloj.textContent = seg() + ' s';
        const li = pasos.lastElementChild;
        if (li && li.querySelector('.pl-ico').textContent === '⏳') {
            li.querySelector('.pl-seg').textContent = ((performance.now() - inicioPaso) / 1000).toFixed(1) + ' s';
        }
    }, 100);
    const sondeo = setInterval(async () => {
        if (corrida !== _plCorrida) return clearInterval(sondeo);
        try {
            const r = await fetch(PL_PROGRESO_URL + '?progreso=' + token, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            const d = await r.json();
            if (corrida === _plCorrida) (d.pasos || []).slice(vistos).forEach(t => { vistos++; nuevoPaso(t); });
        } catch (e) { /* el siguiente sondeo lo intenta otra vez */ }
    }, 500);
    const terminar = () => { clearInterval(tic); clearInterval(sondeo); reloj.textContent = seg() + ' s'; };

    const base = url.split('&t=')[0];
    fetch(base + '&progreso=' + token + '&t=' + Date.now(), { credentials: 'same-origin' })
        .then(async (resp) => {
            if (corrida !== _plCorrida) return;
            const tipo = resp.headers.get('Content-Type') || '';
            if (!resp.ok || !tipo.includes('pdf')) {
                const html = await resp.text();
                const texto = new DOMParser().parseFromString(html, 'text/html').body.innerText.trim();
                throw new Error(texto.replace(/^⚠️\s*No se pudo descargar la planilla\s*/, '') || ('Error ' + resp.status));
            }
            const blob = await resp.blob();
            terminar();
            nuevoPaso('Listo');
            cerrarPaso('ok');
            const origen = resp.headers.get('X-Soporte-Origen');
            document.getElementById('pl-titulo').textContent = origen === 'brynex'
                ? '📄 Planilla (armada por BryNex)' : '📄 Planilla real del operador';
            const nombre = (/filename="([^"]+)"/.exec(resp.headers.get('Content-Disposition') || '') || [])[1] || 'planilla.pdf';
            _plBlob = URL.createObjectURL(blob);
            const visor = document.getElementById('pl-visor');
            visor.src = _plBlob; visor.style.display = 'block';
            const abrir = document.getElementById('pl-abrir'), bajar = document.getElementById('pl-bajar');
            abrir.href = _plBlob; bajar.href = _plBlob; bajar.download = nombre;
            document.getElementById('pl-acciones').style.display = 'flex';
        })
        .catch((e) => {
            if (corrida !== _plCorrida) return;
            terminar();
            cerrarPaso('error');
            document.getElementById('pl-titulo').textContent = '⚠️ No se pudo descargar la planilla';
            const err = document.getElementById('pl-error');
            err.textContent = e.message || 'Error de conexión.';
            err.style.display = 'block';
        });
    return false;
}
function cerrarPlanilla() {
    _plCorrida++;
    document.getElementById('modal-pl-ov').style.display = 'none';
    document.getElementById('pl-visor').src = 'about:blank';
    if (_plBlob) { URL.revokeObjectURL(_plBlob); _plBlob = null; }
}

// ── Modal Soporte Planilla ─────────────────────────────
function verSoportePlanilla(url, nroPlanilla, operador) {
    document.getElementById('sp-numero').textContent   = '#' + nroPlanilla;
    document.getElementById('sp-operador').textContent = operador || '—';
    const visor = document.getElementById('sp-visor');
    const esPdf = url.toLowerCase().endsWith('.pdf');
    if (esPdf) {
        visor.innerHTML = `<iframe src="${url}" style="width:100%;height:100%;border:none;"></iframe>`;
    } else {
        visor.innerHTML = `<img src="${url}" style="max-width:100%;max-height:100%;object-fit:contain;display:block;margin:auto;" alt="Comprobante planilla">`;
    }
    document.getElementById('sp-link').href = url;
    document.getElementById('modal-soporte-ov').style.display = 'flex';
}
function cerrarSoportePlanilla() {
    document.getElementById('modal-soporte-ov').style.display = 'none';
    document.getElementById('sp-visor').innerHTML = '';
}
</script>

{{-- Modal Recibo --}}
<div id="recibo-modal-ov"
     onclick="if(event.target.id==='recibo-modal-ov')cerrarRecibo()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99999;align-items:center;justify-content:center;">
    <div style="position:relative;width:96vw;max-width:1100px;height:93vh;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.5);display:flex;flex-direction:column;">
        {{-- Header del modal --}}
        <div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:.6rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.65rem;">
                <span style="font-size:1.1rem;">🧾</span>
                <span style="color:#fff;font-size:.9rem;font-weight:700;letter-spacing:.02em;">Recibo de Pago</span>
                {{-- Botón Plano dentro del recibo --}}
                <button id="btn-plano-recibo"
                        style="display:none;background:rgba(255,255,255,.14);color:#a7f3d0;border:1px solid rgba(167,243,208,.3);border-radius:6px;padding:.2rem .6rem;font-size:.72rem;font-weight:700;cursor:pointer;transition:background .15s;"
                        onmouseover="this.style.background='rgba(255,255,255,.24)'"
                        onmouseout="this.style.background='rgba(255,255,255,.14)'"
                        onclick="_verPlanoDesdeRecibo()">
                    📋 Ver Plano
                </button>
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

{{-- Modal de descarga de la planilla (pasos + visor) --}}
<div id="modal-pl-ov"
     onclick="if(event.target.id==='modal-pl-ov')cerrarPlanilla()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(3px);z-index:99997;align-items:center;justify-content:center;padding:.75rem">
    <div style="background:#fff;border-radius:14px;width:min(820px,97vw);max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.4);overflow:hidden">
        <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:.7rem 1.1rem;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
            <div style="color:#fff;font-size:.9rem;font-weight:800" id="pl-titulo">⏳ Descargando planilla…</div>
            <div style="display:flex;align-items:center;gap:.6rem">
                <span id="pl-reloj" style="font-family:monospace;color:#bfdbfe;font-size:.85rem;font-weight:700">0.0 s</span>
                <button onclick="cerrarPlanilla()" style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:6px;width:28px;height:28px;font-size:1rem;cursor:pointer;font-weight:700">&#x2715;</button>
            </div>
        </div>
        <div style="padding:.6rem 1.1rem;flex-shrink:0">
            <ul id="pl-pasos" style="list-style:none;margin:0;padding:0"></ul>
            <div id="pl-error" style="display:none;margin-top:.6rem;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:8px;padding:.5rem .7rem;font-size:.8rem"></div>
            <div id="pl-acciones" style="display:none;gap:.5rem;margin-top:.6rem">
                <a id="pl-abrir" href="#" target="_blank" style="background:#1e3a8a;color:#fff;border-radius:8px;padding:.4rem .8rem;font-size:.78rem;font-weight:700;text-decoration:none">↗ Abrir en pestaña</a>
                <a id="pl-bajar" href="#" style="background:#15803d;color:#fff;border-radius:8px;padding:.4rem .8rem;font-size:.78rem;font-weight:700;text-decoration:none">⬇️ Descargar</a>
            </div>
        </div>
        <iframe id="pl-visor" title="Planilla" style="display:none;flex:1;min-height:60vh;border:0;border-top:1px solid #e2e8f0;background:#f1f5f9"></iframe>
    </div>
</div>

{{-- Modal Clave de Mi Planilla --}}
<div id="modal-mp-ov"
     onclick="if(event.target.id==='modal-mp-ov')cerrarClaveMiPlanilla()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(3px);z-index:99998;align-items:center;justify-content:center;padding:.75rem">
    <div style="background:#fff;border-radius:14px;width:min(420px,97vw);box-shadow:0 24px 60px rgba(0,0,0,.4);overflow:hidden">
        <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:.75rem 1.1rem;display:flex;justify-content:space-between;align-items:center">
            <div>
                <div style="color:#fff;font-size:.9rem;font-weight:800">🔑 Clave de Mi Planilla</div>
                <div style="color:rgba(255,255,255,.7);font-size:.68rem;margin-top:.1rem">Para bajar la planilla real. Se guarda una sola vez.</div>
            </div>
            <button onclick="cerrarClaveMiPlanilla()" style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:6px;width:28px;height:28px;font-size:1rem;cursor:pointer;font-weight:700">&#x2715;</button>
        </div>
        <div style="padding:1rem 1.1rem">
            <form id="mp-form" onsubmit="guardarClaveMiPlanilla(event)">
                <label style="display:block;font-size:.72rem;font-weight:700;color:#475569;margin-bottom:.2rem">Usuario</label>
                <input id="mp-usuario" type="text" value="CC{{ (int) $cedula }}" autocomplete="off"
                       style="width:100%;padding:.45rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-bottom:.15rem">
                <div style="font-size:.65rem;color:#94a3b8;margin-bottom:.7rem">Tipo y número de documento pegados, como se entra al portal.</div>
                <label style="display:block;font-size:.72rem;font-weight:700;color:#475569;margin-bottom:.2rem">Contraseña</label>
                <input id="mp-contrasena" type="password" required autocomplete="new-password"
                       style="width:100%;padding:.45rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-bottom:.8rem">
                <div id="mp-error" style="display:none;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:8px;padding:.45rem .6rem;font-size:.75rem;margin-bottom:.7rem"></div>
                <button id="mp-guardar" type="submit"
                        style="width:100%;background:#0f172a;color:#fff;border:none;border-radius:8px;padding:.55rem;font-size:.82rem;font-weight:700;cursor:pointer">💾 Guardar y descargar</button>
            </form>
            <a id="mp-descargar" href="#" target="_blank" onclick="setTimeout(cerrarClaveMiPlanilla, 300)"
               style="display:none;text-align:center;background:#15803d;color:#fff;border-radius:8px;padding:.6rem;font-size:.85rem;font-weight:700;text-decoration:none">
                ✅ Clave guardada · ⬇️ Descargar planilla
            </a>
        </div>
    </div>
</div>

{{-- Modal Soporte Planilla --}}
<div id="modal-soporte-ov"
     onclick="if(event.target.id==='modal-soporte-ov')cerrarSoportePlanilla()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:99998;align-items:center;justify-content:center;padding:.75rem">
    <div style="background:#fff;border-radius:14px;width:min(800px,97vw);max-height:92vh;display:flex;flex-direction:column;box-shadow:0 28px 70px rgba(0,0,0,.5);overflow:hidden">
        {{-- Header --}}
        <div style="background:linear-gradient(135deg,#78350f,#b45309);padding:.65rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
            <div>
                <div style="color:#fff;font-size:.9rem;font-weight:800">🖼️ Comprobante Planilla <span id="sp-numero" style="font-family:monospace"></span></div>
                <div style="color:rgba(255,255,255,.7);font-size:.68rem;margin-top:.1rem">Operador: <span id="sp-operador"></span></div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center">
                <a id="sp-link" href="#" target="_blank"
                   style="background:rgba(255,255,255,.18);color:#fef3c7;border:none;border-radius:6px;padding:.25rem .65rem;font-size:.72rem;font-weight:700;cursor:pointer;text-decoration:none;transition:background .15s"
                   onmouseover="this.style.background='rgba(255,255,255,.3)'" onmouseout="this.style.background='rgba(255,255,255,.18)'">
                    ↗ Abrir
                </a>
                <button onclick="cerrarSoportePlanilla()"
                        style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:6px;width:28px;height:28px;font-size:1rem;cursor:pointer;font-weight:700;transition:background .15s"
                        onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">&#x2715;</button>
            </div>
        </div>
        {{-- Visor --}}
        <div id="sp-visor" style="flex:1;background:#f1f5f9;overflow:auto;min-height:400px;display:flex;align-items:center;justify-content:center;padding:.5rem">
        </div>
    </div>
</div>
@endpush

{{-- Modal de Anulación --}}
<div id="modal-anular-ov"
     onclick="if(event.target.id==='modal-anular-ov')cerrarAnular()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;padding:.75rem">
    <div style="background:#fff;border-radius:14px;width:min(480px,97vw);box-shadow:0 24px 60px rgba(0,0,0,.4);overflow:hidden">
        <div style="background:linear-gradient(135deg,#7f1d1d,#b91c1c);padding:.75rem 1.1rem;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:.9rem;font-weight:800;color:#fff">⛔ Anular Recibo <span id="anular-nro" style="font-family:monospace"></span></span>
            <button onclick="cerrarAnular()" style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:6px;width:26px;height:26px;cursor:pointer;font-size:.9rem">✕</button>
        </div>
        <div style="padding:1rem 1.1rem">
            {{-- Advertencia planilla pagada --}}
            <div id="anular-adv-planilla" style="display:none;background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:.65rem .9rem;font-size:.78rem;color:#92400e;margin-bottom:.9rem;line-height:1.5">
                <strong>⚠️ Atención: Esta factura tiene planilla pagada al operador.</strong><br>
                La anulación es irreversible y solo la puede realizar un <strong>superadmin de BryNex</strong>.
                Verifique que el operador también sea notificado.
            </div>
            <p style="font-size:.82rem;color:#475569;margin-bottom:.75rem">
                Indique el motivo de anulación. Esta acción queda registrada en la bitácora del sistema.
            </p>
            <label style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em">Motivo <span style="color:#ef4444">*</span></label>
            <textarea id="anular-motivo" rows="3"
                style="width:100%;margin-top:.3rem;border:1.5px solid #cbd5e1;border-radius:8px;padding:.45rem .65rem;font-size:.83rem;resize:vertical;outline:none;font-family:inherit;box-sizing:border-box"
                placeholder="Ej: Error de facturación, duplicado, etc."></textarea>
        </div>
        <div style="padding:.75rem 1.1rem 1rem;border-top:1px solid #f1f5f9;display:flex;gap:.5rem;justify-content:flex-end">
            <button onclick="cerrarAnular()" style="padding:.38rem .85rem;border-radius:8px;font-size:.8rem;font-weight:600;border:1px solid #e2e8f0;background:#f1f5f9;color:#64748b;cursor:pointer">
                Cancelar
            </button>
            <button id="btn-ejecutar-anular" onclick="confirmarAnular()"
                style="padding:.38rem .85rem;border-radius:8px;font-size:.8rem;font-weight:700;border:none;background:linear-gradient(135deg,#b91c1c,#7f1d1d);color:#fff;cursor:pointer">
                ⛔ Confirmar Anulación
            </button>
        </div>
    </div>
</div>
@endsection
