@extends(request()->boolean('modal') ? 'layouts.modal' : 'layouts.app')
@section('modulo','Recibo de Pago')

@php
use Carbon\Carbon;
$meses=['Enero','Febrero','Marzo','Abril','Mayo','Junio',
        'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fmt   = fn($v) => '$'.number_format($v ?? 0, 0, ',', '.');
$esGrupo = $grupoNp && $grupoNp->count() > 0 && !request()->boolean('individual');
$filas   = $esGrupo ? $grupoNp : collect([$factura]);

// ── Retiro marcado al facturar ────────────────────────────────────────
// Devuelve "31/07/2026 · Sandra Villota" para la fila cuyo plano trae fecha
// de retiro. Antes el recibo no decía nada del retiro y había que buscar en
// la bitácora quién lo había marcado.
$retiroDe = function ($f) use ($retirosGrupo) {
    $r = ($retirosGrupo ?? collect())->get($f->id);
    if (!$r) { return null; }
    $fec = $r['fecha'] ? Carbon::parse($r['fecha'])->format('d/m/Y') : null;
    return trim(($fec ?: '') . ($r['usuario'] ? ($fec ? ' · ' : '').$r['usuario'] : '')) ?: null;
};

// ── Detectar par Afiliación + Planilla (independientes, modo "ambos") ──
// Cuando el mismo numero_factura tiene 2 registros del mismo contrato
// con tipos afiliacion y planilla, redirigimos la referencia al registro
// de planilla para que el recibo se vea como planilla (con SS, entidades)
// mientras que totAfil suma el valor de la afiliación como ítem extra.
$esPar = false;
if ($filas->count() === 2) {
    $tipos       = $filas->pluck('tipo')->sort()->values()->toArray();
    $contratoIds = $filas->pluck('contrato_id')->unique();
    if ($contratoIds->count() === 1
        && in_array('afiliacion', $tipos)
        && in_array('planilla',   $tipos)
    ) {
        $esPar       = true;
        $factPlanRef = $filas->firstWhere('tipo', 'planilla');
        if ($factPlanRef) {
            $factura = $factPlanRef; // usar planilla como referencia de la vista
        }
    }
}

// ── ¿A nombre de quién va el recibo? ──────────────────────────────────
// Se decide por facturas.empresa_id, NO por clientes.cod_empresa: ese
// último es el canal/referido comercial del cliente y no tiene nada que
// ver con a quién se le factura. Usarlo hacía que un recibo individual
// saliera encabezado con una empresa ajena (p.ej. "REFERIDOS EMERMEDICA"
// sobre un recibo de una sola persona).
$empresaObj = null;
if ($esGrupo && $factura->empresa_id) {
    $empresaObj = \App\Models\Empresa::find($factura->empresa_id);
}

// Sin empresa: el recibo es de una persona (o de un puñado sin empresa).
// Se arma el título con el trabajador cuando es uno solo, y con la razón
// social compartida —si la hay— cuando son varios.
$tituloPersona = null;
$subtituloPersona = null;
if ($esGrupo && !$empresaObj) {
    if ($filas->count() === 1) {
        $fUno   = $filas->first();
        $cliUno = $fUno->contrato?->cliente;
        // Un trámite (otro_ingreso) se factura sin contrato, así que por esa vía
        // no hay cliente y el recibo salía encabezado "C.C. 123 / C.C. 123".
        // El cliente se resuelve por cédula dentro del aliado, igual que hace la
        // vista individual de _recibo_cuerpo. Se limita a los trámites a
        // propósito: hay facturas legacy sin contrato cuyo encabezado no se
        // quiere cambiar de rebote.
        if (!$cliUno && $fUno->tipo === 'otro_ingreso') {
            $cliUno = \App\Models\Cliente::where('aliado_id', $fUno->aliado_id)
                ->where('cedula', $fUno->cedula)->first();
        }
        $tituloPersona = trim(($cliUno?->primer_nombre ?? '').' '.($cliUno?->segundo_nombre ?? '')
                            .' '.($cliUno?->primer_apellido ?? '').' '.($cliUno?->segundo_apellido ?? ''));
        $tituloPersona = nombre_oracion($tituloPersona);
        $tituloPersona = $tituloPersona ?: ('C.C. '.$fUno->cedula);
        $subtituloPersona = 'C.C. '.$fUno->cedula;
    } else {
        $rsUnicas = $filas->map(fn($x) => $x->contrato?->razonSocial?->razon_social)
                          ->filter()->unique()->values();
        $tituloPersona = $rsUnicas->count() === 1
            ? $rsUnicas->first()
            : $filas->count().' trabajadores';
    }
}

// Totales del grupo
$totSS=$totAdmon=$totSeg=$totAfil=$totIva=$totTotal=$totPrest=$totMora=0;
// "Otros planilla" y mensajería: cobros del lote que no son SS ni administración.
// Sin esta línea el resumen suma menos que el TOTAL y el recibo no cuadra.
$totOtros=0;
$totEfect=$totConsig=$totBanco2=$totAnticipo=0;
foreach ($filas as $f) {
    $totSS    += (int)($f->total_ss ?? 0);
    $totAdmon += (int)($f->admon ?? 0) + (int)($f->admin_asesor ?? 0) + (int)($f->otros_admon ?? 0);
    $totOtros += (int)($f->otros ?? 0) + (int)($f->mensajeria ?? 0);
    $totSeg   += (int)($f->seguro ?? 0);
    $totAfil  += (int)($f->afiliacion ?? 0);
    $totIva   += (int)($f->iva ?? 0);
    $totMora  += (int)($f->mora ?? 0);
    $totTotal += (int)($f->total ?? 0);
    $totPrest += (int)($f->valor_prestamo ?? 0);
    $totEfect += (int)($f->valor_efectivo ?? 0);
    $totConsig+= (int)($f->valor_consignado ?? 0);
    $totBanco2+= (int)($f->valor_banco2 ?? 0);
    $totAnticipo += (int)($f->anticipo_aplicado ?? 0);
}
// Abonos al préstamo del grupo: cuándo y cómo se pagó lo que se fió, con su
// soporte. Sin esto el recibo de un préstamo ya saldado solo decía «Préstamo».
$abonosPrestamo = \App\Models\Abono::whereIn('factura_id', collect($filas)->pluck('id')->filter()->all())
    ->orderBy('fecha')->orderBy('id')->get();
$bancosAbonos = DB::table('banco_cuentas')->whereIn('id', $abonosPrestamo->pluck('banco_cuenta_id')->filter()->unique()->all())->pluck('nombre', 'id');

// Agrupar todas las consignaciones del lote/grupo para que se muestren unificadas sin importar con qué ID del lote se abrió el recibo
$consignacionesGrupo = collect();
foreach ($filas as $f) {
    if (is_object($f) && isset($f->consignaciones)) {
        foreach ($f->consignaciones as $csg) {
            $consignacionesGrupo->push($csg);
        }
    } else {
        $csgs = DB::table('consignaciones')->where('factura_id', $f->id)->get();
        foreach ($csgs as $csg) {
            if (isset($csg->banco_cuenta_id)) {
                $csg->bancoCuenta = DB::table('banco_cuentas')->find($csg->banco_cuenta_id);
            }
            $consignacionesGrupo->push($csg);
        }
    }
}
$consignacionesGrupo = $consignacionesGrupo->unique('id');

// Si estado es préstamo y valor_prestamo=0, calcularlo como total - lo recibido
if ($factura->estado === 'prestamo' && $totPrest === 0) {
    $totPrest = max(0, $totTotal - $totEfect - $totConsig);
}

// Un préstamo chico (una mora, un redondeo) no le cambia la cara al recibo: el
// cliente ve PAGO y la deuda igual queda viva en /admin/prestamos. Antes el
// corte era "la mitad del total", con lo que un faltante de $500.000 sobre
// $1.100.000 se imprimía como pagado; ahora manda el monto (ver el modelo).
$estadoVisual = $factura->estado;
if ($factura->estado === 'prestamo' && $totPrest <= \App\Models\Factura::PRESTAMO_SELLO_PAGO) {
    $estadoVisual = 'pagada';
}


$estadoLabel = fn($e) => match($e) {
    'pagada'      => 'PAGO',
    'pre_factura' => 'PRE-FACTURA',
    'prestamo'    => 'PRÉSTAMO',
    'abono'       => 'ABONO',
    default       => strtoupper($e)
};
$estadoCls = fn($e) => match($e) {
    'pagada'  => 'badge-pago',
    'prestamo'=> 'badge-prest',
    'abono'   => 'badge-abono',
    default   => 'badge-pre'
};
@endphp

@section('contenido')
<style>
/* ─── Google Fonts ───────────────────────────── */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

/* ─── PRINT ──────────────────────────────────── */
/* Hoja CARTA VERTICAL. El recibo mantiene su diseño ancho (277mm) y se
   reduce con transform:scale para caber en el ancho útil de la carta,
   quedando pegado arriba y dejando la mitad inferior en blanco. */
@page {
    size: letter portrait;
    /* En doble copia se aprieta el margen a 6mm para ganar ancho y alto
       útiles (cada copia dispone de más espacio antes de escalarse).
       6mm es el mínimo seguro: por debajo hay impresoras que recortan. */
    margin: {{ ($reciboDoble ?? false) ? '6mm' : '8mm' }};
}
@media print {
    body * { visibility: hidden !important; }
    #recibo-print-area, #recibo-print-area * { visibility: visible !important; }
    /* Modo simple (una copia por hoja) — comportamiento original */
    #recibo-print-area.hoja-fondo {
        position: fixed;
        top: 0; left: 0; right: auto; bottom: auto;
        width: 277mm;                /* ancho de diseño original */
        height: auto;
        transform: scale(0.721);     /* 199.9mm útiles de carta / 277mm */
        transform-origin: top left;
        padding: 0; background: #fff; z-index: 9999;
        box-shadow: none !important;
    }
    .no-print { display: none !important; }
    /* Estas reglas reacomodan el recibo SOLO en el modo simple. En doble
       copia no deben aplicar: cambiarían el layout respecto a lo que se ve
       en pantalla y el escalado calculado en pantalla dejaría de servir. */
    .hoja-fondo .recibo-wrap { box-shadow: none !important; border-radius: 0 !important; border: none !important; overflow: visible !important; }
    .hoja-fondo .recibo-inner { margin: 0 !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; overflow: visible !important; }
    .hoja-fondo .recibo-inner-wrap { margin: 0 !important; overflow: visible !important; }
    .hoja-fondo .fact-header { border: none !important; border-radius: 0 !important; }
    .fact-sello { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .hoja-fondo { background: #fff !important; padding: 0 !important; }
    /* Colores de fondo se imprimen */
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    /* Vista simple: ocultar resumen/detalle al imprimir */
    .bloque-resumen { display: none !important; }
    .g-adm-row { display: none !important; }
    .g-adm-footer { display: none !important; }
    .g-val { display: none !important; }
    /* Vista detallada: mostrar todo si el wrapper tiene .det
       (selector por clase, no por #rw: en modo doble copia hay dos wrappers) */
    .recibo-wrap.det .bloque-resumen { display: grid !important; }
    .recibo-wrap.det .g-adm-row { display: table-row !important; }
    .recibo-wrap.det .g-adm-footer { display: table-row !important; }
    .recibo-wrap.det .g-val { display: block !important; }
    .recibo-wrap.det .col-valor-det { display: table-cell !important; }
    /* Tabla: auto-layout con fuente compacta para que entre en el ancho útil.
       Solo modo simple: en doble copia la tabla se imprime tal cual se ve y
       es el transform:scale de cada copia el que la hace caber. */
    .hoja-fondo .fact-table {
        table-layout: auto !important;
        width: 100% !important;
        font-size: .58rem !important;
    }
    .hoja-fondo .fact-table td, .hoja-fondo .fact-table th {
        overflow: visible !important;
        white-space: normal !important;
        word-break: normal !important;
        padding: .25rem .4rem !important;
    }
    .hoja-fondo .fact-table td.right, .hoja-fondo .fact-table tfoot td.right {
        white-space: nowrap !important;
    }
    /* Padding interno de la tabla no se corte */
    .hoja-fondo .fact-section-title + div[style*="padding"] {
        padding: 0 !important;
    }
}

/* ─── Fondo tipo hoja ─────────────────────────── */
.hoja-fondo {
    background: #e8edf2;
    padding: 1.5rem 1.2rem;
    min-height: 100vh;
}

/* ─── Base ───────────────────────────────────── */
#recibo-print-area, #recibo-print-area * { font-family: 'Inter', sans-serif; }
.recibo-wrap {
    max-width: 1150px; margin: 0 auto; background: #fff;
    border-radius: 6px;
    box-shadow:
        0 1px 3px rgba(0,0,0,.14),
        0 4px 14px rgba(0,0,0,.10),
        0 10px 40px rgba(0,0,0,.09),
        0 0 0 1px rgba(0,0,0,.06);
    overflow: hidden;
    border: 1px solid #c9d2dc;
    position: relative;
}

/* Padding interno: separa el contenido de los bordes del recuadro */
.recibo-inner {
    margin: 1rem 1.2rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(30,58,95,.07);
}

/* El header y bottom-bar del individual van dentro del recibo-inner-wrap con margen top/bot */
.recibo-inner-wrap {
    margin: 1rem 1.2rem 0;
    border-radius: 6px 6px 0 0;
    overflow: hidden;
}
/* El recibo-inner de datos se conecta sin brecha con el wrap del header */
.recibo-inner-wrap + .recibo-inner {
    margin-top: 0;
    border-top: none;
    border-radius: 0 0 6px 6px;
}

/* ─── BOTONES ─────────────────────────────────── */
.btn-a {
    padding: .4rem .9rem; border-radius: 7px; border: none;
    font-weight: 600; cursor: pointer; font-size: .82rem;
    text-decoration: none; font-family: 'Inter', sans-serif;
}

/* ─── BADGE ESTADO ───────────────────────────── */
.badge {
    display: inline-block; padding: .18rem .6rem;
    border-radius: 20px; font-size: .72rem; font-weight: 700;
}
.badge-pago  { background: #dcfce7; color: #15803d; }
.badge-pre   { background: #f1f5f9; color: #475569; }
.badge-prest { background: #ede9fe; color: #6d28d9; }
.badge-abono { background: #fef3c7; color: #92400e; }

/* ─── SELLO DIAGONAL (individual) ───────────── */
.fact-sello-wrap {
    position: absolute; top: 0; right: 0;
    width: 160px; height: 160px; overflow: hidden;
    pointer-events: none; z-index: 10;
}
.fact-sello {
    position: absolute; top: 32px; right: -32px;
    width: 170px; text-align: center;
    padding: 7px 0; font-size: .72rem; font-weight: 900;
    letter-spacing: .12em; text-transform: uppercase;
    transform: rotate(45deg);
    box-shadow: 0 3px 10px rgba(0,0,0,.25);
    border-radius: 3px;
}
.sello-pagado  { background: #15803d; color: #fff; }
.sello-pre     { background: #64748b; color: #fff; }
.sello-prest   { background: #7c3aed; color: #fff; }
.sello-abono   { background: #d97706; color: #fff; }

/* ─── CABECERA FACTURA ───────────────────────── */
.fact-header {
    display: grid;
    grid-template-columns: 1fr auto 220px;
    gap: 0;
    border-bottom: 3px solid #1e3a5f;
    padding: 0;
}
.fact-h-empresa {
    padding: 1rem 1.2rem;
    border-right: 1.5px solid #e2e8f0;
}
.fact-h-recibo {
    background: linear-gradient(135deg,#1e3a5f,#0f172a);
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.6rem 1.2rem;
    min-width: 200px;
    text-align: center;
}
.fact-h-logo {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border-left: 1.5px solid #e2e8f0;
    position: relative;
}

/* ─── DATOS CLIENTE ──────────────────────────── */
.fact-cliente {
    background: linear-gradient(to right, #f0f7ff, #fff);
    border-bottom: 1.5px solid #e2e8f0;
    padding: .65rem 1.2rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .2rem .6rem;
    font-size: .78rem;
}
.fact-cliente-row {
    display: flex;
    gap: .4rem;
    align-items: baseline;
    padding: .12rem 0;
    border-bottom: .5px solid #e9f0f8;
}
.fact-cliente-lbl {
    font-weight: 700;
    color: #1e3a5f;
    min-width: 90px;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    flex-shrink: 0;
}
.fact-cliente-val {
    color: #0f172a;
    font-weight: 600;
    font-size: .8rem;
}

/* ─── TABLA ENTIDADES (estilo factura) ───────── */
.fact-body { padding: 0; }
.fact-section-title {
    background: linear-gradient(90deg, #1e3a5f, #2563eb);
    color: #fff;
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    padding: .35rem 1.2rem;
}
.fact-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .79rem;
}
.fact-table th {
    background: #e8f0fe;
    color: #1e3a5f;
    font-size: .62rem;
    font-weight: 800;
    text-transform: uppercase;
    padding: .32rem .55rem;
    letter-spacing: .05em;
    border-bottom: 2px solid #c7d7f5;
    text-align: left;
    overflow: hidden;
    white-space: nowrap;
}
.fact-table th.right { text-align: right; }
.fact-table td {
    padding: .35rem .55rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    overflow: hidden;
    word-break: break-word;
}
.fact-table tbody tr:nth-child(odd) td  { background: #f8fafc; }
.fact-table tbody tr:nth-child(even) td { background: #ffffff; }
.fact-table tbody tr:hover td { background: #eff6ff; transition: background .15s; }
.fact-table td.right { text-align: right; font-family: monospace; font-weight: 700; white-space: nowrap; }
.fact-table td.entidad {
    font-weight: 700;
    color: #1d4ed8;
    font-size: .78rem;
}
.fact-table td.concepto {
    color: #334155;
    font-weight: 600;
}
.fact-table td.tag {
    font-size: .62rem;
    color: #64748b;
}
.fact-table tfoot td {
    background: #1e3a5f;
    color: #fff;
    font-weight: 800;
    padding: .45rem .55rem;
    font-size: .78rem;
}
.fact-table tfoot td.right {
    color: #93c5fd;
    font-family: monospace;
    font-size: .82rem;
    white-space: nowrap;
}

/* ─── PIE: Nota + Total ──────────────────────── */
.fact-footer-area {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0;
    border-top: 2px solid #1e3a5f;
    min-height: 64px;
}
.fact-nota {
    padding: .65rem 1rem;
    font-size: .68rem;
    color: #92400e;
    background: #fffbeb;
    border-right: 1.5px solid #fde68a;
    line-height: 1.55;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.fact-total-bloque {
    background: linear-gradient(135deg, #1e3a5f, #0f172a);
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: .75rem 1.5rem;
    min-width: 220px;
    gap: .1rem;
}
.fact-total-label {
    font-size: .63rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: #93c5fd;
}
.fact-total-valor {
    font-size: 1.6rem;
    font-weight: 900;
    color: #fbbf24;
    font-family: monospace;
    letter-spacing: -.02em;
}

/* ─── DATOS PAGO (bajo tabla) ────────────────── */
.fact-pago-area {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border-top: 1.5px solid #e2e8f0;
}
.fact-pago-col {
    padding: .6rem 1.1rem;
    font-size: .76rem;
}
.fact-pago-col:first-child {
    border-right: 1.5px solid #e2e8f0;
}
.fact-pago-hdr {
    font-size: .61rem;
    font-weight: 800;
    color: #1e3a5f;
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: .35rem;
    padding-bottom: .2rem;
    border-bottom: 1.5px solid #bfdbfe;
}
.fact-pago-row {
    display: flex;
    justify-content: space-between;
    padding: .14rem 0;
    border-bottom: .5px solid #f1f5f9;
    color: #374151;
}
.fact-pago-row span:first-child { color: #64748b; }
.fact-pago-row strong { color: #0f172a; font-weight: 700; }
.fact-bottom-bar {
    background: #0f172a;
    color: #94a3b8;
    font-size: .65rem;
    padding: .45rem 1.2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* ─── ALERTA PRÉSTAMO ────────────────────────── */
.alerta-prest {
    background: #fdf4ff; border: 2px solid #c4b5fd; border-radius: 0;
    padding: .55rem 1.2rem; display: flex; align-items: center; gap: .6rem;
    border-left: 4px solid #7c3aed; margin: 0;
}

/* ─── TABLA GRUPO (NP) ───────────────────────── */
.tbl { width:100%;border-collapse:collapse;font-size:.74rem }
.tbl th { background:#0f172a;color:#94a3b8;font-size:.61rem;text-transform:uppercase;
    padding:.38rem .42rem;white-space:nowrap;text-align:center }
.tbl td { padding:.28rem .42rem;border-bottom:1px solid #f1f5f9;vertical-align:top }
.tbl tbody tr:nth-child(even) td { background:#fafafa }
.tbl tfoot td { background:#0f172a;color:#fff;font-weight:700;padding:.4rem .42rem }
.n-r { text-align:right;font-family:monospace }
.tot-v { color:#34d399 }
.rec-body{ padding:1rem 1.4rem }
.box2 { display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin:.6rem 0 }
.ibox { background:#f8fafc;border-radius:8px;padding:.5rem .75rem;font-size:.79rem }
.ilbl { font-size:.63rem;color:#94a3b8;text-transform:uppercase;font-weight:700;margin-bottom:.25rem }
.srow { display:flex;justify-content:space-between;padding:.09rem 0 }
.total-bx { background:#0f172a;color:#fff;border-radius:8px;padding:.7rem 1rem;
    display:flex;justify-content:space-between;align-items:center;margin-top:.5rem }
.total-v { font-size:1.4rem;font-weight:900;color:#fbbf24 }

/* ─── MODO GRUPO: encabezado tipo rec original ── */
.rec-hdr { background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;
    padding:1rem 1.4rem;display:flex;justify-content:space-between;align-items:flex-start }
.rec-num { font-size:1.55rem;font-weight:900;color:#fbbf24 }

/* ─── Vista simple / detallada (individual y grupo) ─ */
/* Por defecto = vista simple */
.col-valor-det { display:none }
.bloque-resumen { display:none }
/* ── Grupo: valores bajo entidad ───────────── */
.g-val { display:none; font-size:.6rem; color:#64748b; font-style:italic; margin-top:.08rem; }
.g-adm-row { display:none }
.g-adm-footer { display:none }
/* Con clase .det: todo visible */
.det .col-valor-det { display:table-cell }
.det .bloque-resumen { display:grid }
.det .g-val { display:block }
.det .g-adm-row { display:table-row }
.det .g-adm-footer { display:table-row }

/* ═══════════════════════════════════════════════════════════════════════
   MODO DOBLE COPIA — dos recibos en una hoja carta, para partir al medio
   ---------------------------------------------------------------------
   Geometría (con @page margin: 6mm sobre carta 215.9 × 279.4mm):
     ancho útil = 215.9 − 12 = 203.9mm
     alto  útil = 279.4 − 12 = 267.4mm
     línea de corte = 4mm  →  slot = (267.4 − 4) / 2 = 131.7mm
   Se usan 130.5mm por slot (265mm de total): deja ~2.4mm de holgura para
   que un redondeo del navegador no empuje la hoja a una segunda página.
   La MISMA geometría se usa en pantalla y en impresión, así que lo que se
   ve es lo que sale. El escalado de cada copia lo calcula ajustarCopias().
═══════════════════════════════════════════════════════════════════════ */
.hoja-doble {
    --hoja-ancho:  203.9mm;
    --slot-alto:   130.5mm;
    --marca-alto:  4.6mm;
    --corte-alto:  4mm;
    --diseno-ancho: 1150px;   /* ancho inicial; ajustarCopias() busca el mejor */
    box-sizing: border-box;
    background: #fff;
    margin: 0 auto;
}
.hoja-doble * { box-sizing: border-box; }

.copia-slot {
    width: 100%;
    height: var(--slot-alto);
    overflow: hidden;
    position: relative;
}
.copia-marca {
    height: var(--marca-alto);
    display: flex; align-items: center; justify-content: flex-end;
    padding-right: .25rem;
    font-size: .58rem; font-weight: 800; letter-spacing: .18em;
    text-transform: uppercase; color: #94a3b8;
    font-family: 'Inter', sans-serif;
}
.copia-escala {
    height: calc(var(--slot-alto) - var(--marca-alto));
    overflow: hidden;
    position: relative;
}
/* El recibo se dibuja a su ancho de diseño y JS le aplica el transform
   (translate + scale) que lo centra dentro de .copia-escala. */
.copia-escala > .recibo-wrap {
    position: absolute; top: 0; left: 0;
    width: var(--diseno-ancho);
    max-width: none;
    margin: 0;
    transform-origin: top left;
    box-shadow: none;
    border: 1px solid #c9d2dc;   /* igual en pantalla y en papel */
    border-radius: 0;
}
/* Clase temporal que pone ajustarCopias() mientras mide: replica el layout
   de impresión (sin los elementos .no-print) para que la escala calculada
   en pantalla sea exactamente la que necesita el papel. */
.hoja-doble.midiendo .no-print { display: none !important; }
.linea-corte {
    height: var(--corte-alto);
    border-top: 1px dashed #cbd5e1;
    display: flex; align-items: center; justify-content: center;
    font-size: .5rem; letter-spacing: .16em; color: #cbd5e1;
    text-transform: uppercase; font-family: 'Inter', sans-serif;
}

/* ── Pantalla: se ve la hoja carta completa sobre el fondo gris ──────── */
@media screen {
    .hoja-doble-fondo { background: #e8edf2; padding: 1.5rem 1.2rem; min-height: 100vh; }
    .hoja-doble {
        width: 215.9mm;          /* hoja carta completa */
        padding: 6mm;            /* equivalente al margin del @page */
        box-shadow: 0 2px 6px rgba(0,0,0,.14), 0 12px 44px rgba(0,0,0,.13);
    }
}

/* ── Impresión: la hoja ocupa el área útil, sin escala global ────────── */
@media print {
    #recibo-print-area.hoja-doble {
        position: fixed;
        top: 0; left: 0; right: auto; bottom: auto;
        width: var(--hoja-ancho);
        padding: 0;
        transform: none;
        background: #fff;
        z-index: 9999;
        box-shadow: none !important;
    }
    .hoja-doble-fondo { background: #fff !important; padding: 0 !important; min-height: 0 !important; }
    .copia-slot, .copia-escala { overflow: hidden !important; }
}

/* ─── LIQUIDACIÓN DE UNA SOLA PERSONA (copia cliente) ─────────────────
   Reemplaza la tabla de 6 columnas cuando el recibo es de un afiliado:
   dos columnas de etiqueta/valor ocupan menos ancho y algo más de alto,
   que es justo lo que necesita el escalado para llenar la media hoja.  */
.liq-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 1.1rem;
}
.liq-item {
    display: flex; justify-content: space-between; align-items: baseline;
    gap: .6rem; padding: .26rem .1rem;
    border-bottom: 1px solid #eef2f7;
}
.liq-item > span {
    color: #64748b; font-weight: 700; font-size: .64rem;
    text-transform: uppercase; letter-spacing: .05em; white-space: nowrap;
}
.liq-item > b {
    font-weight: 800; font-size: .84rem; text-align: right; line-height: 1.2;
}
/* Valor en pesos: sin `display` propio, para que mande la regla .g-val
   (oculto en vista simple, visible con .det). */
.liq-item em {
    font-size: .64rem; color: #64748b;
    font-style: italic; font-weight: 600; font-family: monospace;
}
.det .liq-item em { display: block; }
/* Trámite: el concepto ocupa las dos columnas — una descripción no cabe
   en media grilla y partirla la vuelve ilegible. */
.liq-item-full { grid-column: 1 / -1; justify-content: flex-start; }
.liq-item-full > b {
    flex: 1; text-align: left; color: #065f46; font-size: .8rem;
    white-space: normal; line-height: 1.3;
}
.liq-total {
    background: #0f172a;
    display: flex; justify-content: space-between; align-items: center;
    padding: .5rem .85rem; margin-top: .35rem;
}
.liq-total span {
    color: #93c5fd; font-size: .74rem; font-weight: 800; letter-spacing: .08em;
}
.liq-total b {
    color: #fbbf24; font-size: 1.35rem; font-weight: 900;
    font-family: monospace; letter-spacing: -.02em;
}

/* ─── DESGLOSE COPIA EMPRESA ──────────────────────────────────────────
   Bloque que solo aparece en la copia de la empresa: administración
   separada (empresa / asesor), seguro, 4×1000 y los tres saldos.       */
.desglose-emp {
    border: 1.5px solid #cbd5e1;
    border-radius: 7px;
    overflow: hidden;
    background: #fff;
}
.desglose-emp-hdr {
    background: linear-gradient(90deg, #334155, #64748b);
    color: #fff;
    font-size: .6rem; font-weight: 800;
    letter-spacing: .09em; text-transform: uppercase;
    padding: .28rem .7rem;
}
.desglose-emp-grid {
    display: grid;
    grid-template-columns: 1fr 1.15fr 1.2fr 1.1fr;
    gap: 0;
}
.dg-col { padding: .4rem .7rem; }
.dg-col + .dg-col { border-left: 1px solid #e2e8f0; }
.dg-col-hdr {
    font-size: .58rem; font-weight: 800; color: #475569;
    text-transform: uppercase; letter-spacing: .07em;
    padding-bottom: .18rem; margin-bottom: .22rem;
    border-bottom: 1.5px solid #cbd5e1;
}
.dg-row {
    display: flex; justify-content: space-between; align-items: baseline;
    gap: .5rem; padding: .09rem 0;
    font-size: .7rem; color: #475569;
}
.dg-row b {
    font-family: monospace; font-weight: 700; color: #0f172a;
    white-space: nowrap;
}
.dg-row.dg-sub {
    border-top: 1px solid #e2e8f0;
    margin-top: .18rem; padding-top: .18rem;
    font-weight: 700; color: #1e3a5f;
}
.dg-row.dg-saldo {
    margin-top: .25rem; padding: .2rem .4rem;
    border-radius: 5px; font-weight: 800; font-size: .72rem;
}
.dg-row.dg-favor { background: #dcfce7; color: #15803d; }
.dg-row.dg-favor b { color: #15803d; }
.dg-row.dg-debe  { background: #fee2e2; color: #b91c1c; }
.dg-row.dg-debe b { color: #b91c1c; }
.dg-row.dg-cero  { background: #f1f5f9; color: #64748b; }
.dg-row.dg-cero b { color: #64748b; }
</style>

{{-- Botonera (no se imprime) --}}
<div class="no-print" style="max-width:1150px;margin:0 auto .65rem;display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap;">
    @if($grupoNp && $grupoNp->count() > 1 && request()->boolean('individual'))
    <a href="{{ route('admin.facturacion.recibo', $factura->id) }}?modal={{ request()->get('modal', 0) }}" class="btn-a" style="background:#1e3a5f;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem">🏢 Ver Recibo Empresa</a>
    @endif
    <button class="btn-a" id="btnToggleVista" style="background:#f1f5f9;color:#475569" onclick="toggleVistaDet()">📋 Vista detallada</button>
    <button class="btn-a" style="background:#0f172a;color:#fff" onclick="window.print()">🖨 Imprimir</button>
    @if((auth()->user()?->hasRole('admin') || auth()->user()?->hasRole('superadmin')) && !request()->boolean('no_anular'))
    <button class="btn-a" style="background:#1e3a5f;color:#fff" onclick="abrirCorregirPago()">💳 Corregir pago</button>
    <button class="btn-a" style="background:#dc2626;color:#fff" onclick="abrirAnular()">🗑 Anular</button>
    @endif
    @if(request()->boolean('modal'))
    <button class="btn-a" style="background:#64748b;color:#fff"
            onclick="if(window.parent && window.parent.cerrarRecibo){ window.parent.cerrarRecibo(); } else { window.close(); }">✕ Cerrar</button>
    @else
    <a href="{{ $factura->empresa_id
            ? route('admin.facturacion.empresa', ['id' => $factura->empresa_id, 'mes' => $factura->mes, 'anio' => $factura->anio])
            : route('admin.facturacion.index') }}"
       class="btn-a" style="background:#f1f5f9;color:#475569">← Volver</a>
    @endif
</div>

@php $doble = (bool)($reciboDoble ?? false); @endphp

@if($doble)
{{-- ══════════════════════════════════════════════════════════════════════
     MODO DOBLE COPIA — una hoja carta partida por la mitad
       · Arriba: copia CLIENTE — respeta el botón "Vista detallada"
       · Abajo:  copia EMPRESA — siempre detallada + desglose y saldos
     Cada copia se escala con JS (ajustarCopias) para caber en su mitad.
     Lo que se ve en pantalla es exactamente lo que sale impreso.
══════════════════════════════════════════════════════════════════════════ --}}
<div class="hoja-doble-fondo">
<div id="recibo-print-area" class="hoja-doble">

    {{-- ── Copia 1: CLIENTE ─────────────────────────────────────────── --}}
    <div class="copia-slot">
        <div class="copia-marca">Copia cliente</div>
        <div class="copia-escala">
            <div class="recibo-wrap copia" id="rw">
                @include('admin.facturacion._recibo_cuerpo', ['copia' => 'cliente'])
            </div>
        </div>
    </div>

    {{-- ── Línea de corte ───────────────────────────────────────────── --}}
    <div class="linea-corte"><span>&#9986;&nbsp;&nbsp;cortar por aquí</span></div>

    {{-- ── Copia 2: EMPRESA (siempre .det) ──────────────────────────── --}}
    <div class="copia-slot">
        <div class="copia-marca">Copia empresa</div>
        <div class="copia-escala">
            <div class="recibo-wrap copia det" id="rw2">
                @include('admin.facturacion._recibo_cuerpo', ['copia' => 'empresa'])
            </div>
        </div>
    </div>

</div>{{-- /recibo-print-area --}}
</div>{{-- /hoja-doble-fondo --}}
@else
<div id="recibo-print-area" class="hoja-fondo">
<div class="recibo-wrap" id="rw">
@include('admin.facturacion._recibo_cuerpo', ['copia' => 'cliente'])
</div>{{-- /recibo-wrap --}}
</div>{{-- /recibo-print-area --}}
@endif
{{-- Modal Anular (solo admin) --}}
@if(auth()->user()?->hasRole('admin') || auth()->user()?->hasRole('superadmin'))
<div id="modalAnular" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:460px;width:95%;box-shadow:0 8px 32px rgba(0,0,0,.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.9rem">
        <h3 style="margin:0;color:#dc2626;font-size:1rem">⚠️ Anular Factura</h3>
        <button onclick="document.getElementById('modalAnular').style.display='none'" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:.6rem .8rem;margin-bottom:.9rem;font-size:.81rem;color:#991b1b">
        Esta acción es <strong>irreversible</strong>. Se eliminará la factura con sus abonos y plano. Todo quedará en la bitácora de auditoría.
    </div>
    @if(($planillasGrupo ?? collect())->isNotEmpty())
    <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:.6rem .8rem;margin-bottom:.9rem;font-size:.79rem;color:#92400e;line-height:1.5">
        <strong>⚠️ Este recibo ya tiene pago confirmado al operador.</strong><br>
        Los planos que se anulen quedan <strong>sin número de planilla</strong> y hay que re-vincularlos a mano:
        <ul style="margin:.35rem 0 0;padding-left:1.1rem">
            @foreach($planillasGrupo as $pl)
            <li>{{ $pl['nombre'] }} (CC {{ $pl['cedula'] }}) — planilla Nº <strong>{{ $pl['planilla'] }}</strong></li>
            @endforeach
        </ul>
    </div>
    @endif
    <div style="margin-bottom:.75rem">
        <label style="font-size:.78rem;font-weight:700;color:#374151;display:block;margin-bottom:.3rem">Motivo de anulación <span style="color:#dc2626">*</span></label>
        <textarea id="an_motivo" rows="3" placeholder="Motivo obligatorio..." style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.42rem .6rem;font-size:.82rem;resize:vertical;box-sizing:border-box"></textarea>
    </div>
    @php
        // Lo que arrastra "todo_np" es el lote completo del numero_factura, NO lo que
        // se ve en pantalla: abierto con ?individual=1 el recibo muestra una sola fila
        // y el conteo decía "1 factura" mientras se anulaban todas las del recibo.
        $grupoAnular   = ($grupoNp && $grupoNp->count()) ? $grupoNp : collect([$factura]);
        $ctosAnular    = $grupoAnular->pluck('contrato_id')->filter()->unique()->count()
                         ?: $grupoAnular->count();
    @endphp
    @if($factura->np && $grupoAnular->count() > 1)
    <label style="display:flex;align-items:flex-start;gap:.45rem;font-size:.81rem;margin-bottom:.75rem;cursor:pointer">
        <input type="checkbox" id="an_np" style="width:16px;height:16px;margin-top:.12rem;flex:none">
        <span>
            Anular <strong>el recibo completo — {{ $ctosAnular }} contrato{{ $ctosAnular == 1 ? '' : 's' }}</strong> del NP {{ $factura->np }}.<br>
            <span style="color:#6b7280;font-size:.76rem">Sin marcar, solo se anula la factura de este contrato.</span>
        </span>
    </label>
    @endif
    <div style="display:flex;justify-content:flex-end;gap:.5rem">
        <button class="btn-a" style="background:#f1f5f9;color:#475569" onclick="document.getElementById('modalAnular').style.display='none'">Cancelar</button>
        <button class="btn-a" style="background:#dc2626;color:#fff" id="btnAnular" onclick="confirmarAnulacion()">🗑 Confirmar Anulación</button>
    </div>
</div>
</div>
@endif

<script>
const CSRF_REC  = document.querySelector('meta[name="csrf-token"]').content;
const URL_ANUL  = '{{ route('admin.facturacion.anular', $factura->id) }}';
const URL_IDX   = '{{ route('admin.facturacion.index') }}';

let simp = true;  // Vista simplificada por defecto al abrir
function toggleSimp() {
    simp = !simp;
    document.getElementById('rw').classList.toggle('simp', simp);
    // Actualizar texto del botón
    const btn = document.querySelector('button[onclick="toggleSimp()"]');
    if (btn) btn.textContent = simp ? '📋 Vista detallada' : '👁 Vista simplificada';
}
// ── Vista detallada / simple para recibo individual ─────────
// Solo afecta a #rw (la copia del cliente). En modo doble copia, la copia
// de la empresa lleva .det fijo: siempre sale detallada.
let _modoDetallado = false;
function toggleVistaDet() {
    _modoDetallado = !_modoDetallado;
    const rw  = document.getElementById('rw');
    const btn = document.getElementById('btnToggleVista');
    if (_modoDetallado) {
        rw.classList.add('det');
        if (btn) { btn.textContent = '📄 Vista simple'; btn.style.background = '#1e3a5f'; btn.style.color = '#fff'; }
    } else {
        rw.classList.remove('det');
        if (btn) { btn.textContent = '📋 Vista detallada'; btn.style.background = '#f1f5f9'; btn.style.color = '#475569'; }
    }
    ajustarCopias();
}

// ── Modo doble copia: escalar cada recibo para que quepa en su mitad ──
// Se aplica en pantalla y en impresión (misma geometría), así que lo que
// se ve en pantalla es exactamente lo que sale por la impresora.
//
// El recibo es fluido: al dibujarlo más angosto, el contenido se reacomoda
// en más filas y crece de alto. Eso cambia la escala que cabe en la media
// hoja, y no siempre el ancho más grande es el que mejor aprovecha: un
// recibo de pocas filas dibujado a 1150px se queda limitado por el ancho
// (~0.66) desperdiciando alto, mientras que a 900px puede subir bastante.
// Por eso aquí se prueban varios anchos y se elige el de mayor escala.
const ANCHOS_DISENO = [1150, 1060, 980, 900, 830, 770, 710];

function ajustarCopias() {
    const hoja = document.querySelector('.hoja-doble');
    if (!hoja) return;

    const copias = [...document.querySelectorAll('.copia-escala')]
        .map(slot => ({ slot, wrap: slot.querySelector('.recibo-wrap') }))
        .filter(c => c.wrap && c.slot.clientWidth > 0 && c.slot.clientHeight > 0);
    if (!copias.length) return;

    // ── Pasada 1: con el layout de impresión (sin los .no-print, que en
    //    pantalla ocupan espacio —el link 👤 de cada fila— y al imprimir
    //    desaparecen). Aquí se elige el ancho de diseño de cada copia.
    hoja.classList.add('midiendo');
    for (const c of copias) {
        const dispW = c.slot.clientWidth, dispH = c.slot.clientHeight;
        c.wrap.style.transform = 'none';      // medir sin escala previa

        // Perfil alto/escala para cada ancho candidato
        const perfil = [];
        for (const w of ANCHOS_DISENO) {
            c.wrap.style.width = w + 'px';
            const h = c.wrap.scrollHeight;
            if (h) perfil.push({ w, h, s: Math.min(dispW / w, dispH / h) });
        }
        if (!perfil.length) { c.wrap.style.width = ANCHOS_DISENO[0] + 'px'; c.malo = true; continue; }

        // Descartar los anchos donde el contenido empieza a comprimirse: si
        // al estrechar crece el alto, la tabla ya está partiendo palabras
        // ("INDEPENDIENT/E", "Ningun/a") — gana escala pero se ve mal.
        const hMin  = Math.min(...perfil.map(p => p.h));
        const sanos = perfil.filter(p => p.h <= hMin * 1.01);

        // De los sanos, la mayor escala; a igualdad el ancho más grande
        // (misma letra impresa, pero la tabla respira más).
        const sMax = Math.max(...sanos.map(p => p.s));
        c.w = Math.max(...sanos.filter(p => p.s >= sMax * 0.995).map(p => p.w));

        c.wrap.style.width = c.w + 'px';
        c.hPrint = c.wrap.scrollHeight;
    }
    hoja.classList.remove('midiendo');

    // ── Pasada 2: mismo ancho, pero midiendo con los .no-print visibles.
    //    La escala se calcula con el ALTO MAYOR de los dos estados, para
    //    que la copia no se corte ni en pantalla ni en el papel.
    for (const c of copias) {
        if (c.malo) continue;
        const dispW = c.slot.clientWidth, dispH = c.slot.clientHeight;
        const natH  = Math.max(c.hPrint, c.wrap.scrollHeight);
        const s = Math.min(dispW / c.w, dispH / natH);
        const x = Math.max(0, (dispW - c.w * s) / 2);
        const y = Math.max(0, (dispH - natH * s) / 2);
        c.wrap.style.transform = `translate(${x}px, ${y}px) scale(${s})`;
    }
}
window.addEventListener('load',   ajustarCopias);
window.addEventListener('resize', ajustarCopias);
window.addEventListener('beforeprint', ajustarCopias);
// La fuente Inter cambia el alto del contenido al terminar de cargar
if (document.fonts && document.fonts.ready) document.fonts.ready.then(ajustarCopias);
// Aplicar modo simplificado al cargar
document.addEventListener('DOMContentLoaded', () => {
    // Grupo: inicia en modo simplificado
    const rw = document.getElementById('rw');
    if (rw && rw.querySelector('.simp-only') !== null) {
        // es grupo: aplicar simp
        rw.classList.add('simp');
        const btn = document.querySelector('button[onclick="toggleSimp()"]');
        if (btn) btn.textContent = '📋 Vista detallada';
    }
    // Individual: modo simple por defecto (sin .det)
    // rw ya no tiene .det, las columnas .col-valor-det están ocultas
    ajustarCopias();
});
function abrirAnular() {
    document.getElementById('modalAnular').style.display = 'flex';
}
// `conf` acumula las confirmaciones que pide el servidor (planilla pagada,
// factura electrónica): cada 409 dice qué casilla falta y se reenvía con ella.
async function confirmarAnulacion(conf = {}) {
    const motivo = (document.getElementById('an_motivo')?.value ?? '').trim();
    const todoNp = document.getElementById('an_np')?.checked ?? false;
    if (!motivo) { alert('Ingrese el motivo de anulación.'); return; }
    const btn = document.getElementById('btnAnular');
    btn.disabled = true; btn.textContent = '⏳ Anulando...';
    try {
        const res  = await fetch(URL_ANUL, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_REC },
            body: JSON.stringify({ motivo, todo_np: todoNp, ...conf })
        });
        const data = await res.json();
        // Planilla pagada al operador o factura electrónica emitida: se confirma
        // viendo el detalle y se reenvía con la casilla que pidió el servidor.
        if (!data.ok && data.requiere_confirmacion) {
            btn.disabled = false; btn.textContent = '🗑 Confirmar Anulación';
            const campo = data.campo_confirmacion || 'confirmar_planilla';
            const detalle = (data.afectados || []).join('\n • ');
            const aviso = data.aviso || 'Acepte solo si está seguro: tendrá que re-vincular la planilla a mano después de re-facturar.';
            if (confirm(data.message + '\n\n • ' + detalle + '\n\n' + aviso)) {
                btn.disabled = true; btn.textContent = '⏳ Anulando...';
                return confirmarAnulacion({ ...conf, [campo]: true });
            }
            return;
        }
        if (data.ok) {
            // Cerrar el modal de anulación
            document.getElementById('modalAnular').style.display = 'none';
            setTimeout(() => {
                if (window.parent && window.parent !== window && typeof window.parent.cerrarRecibo === 'function') {
                    // Estamos dentro del iframe del modal recibo → cerrar y recargar la página padre
                    window.parent.cerrarRecibo(true);
                } else if (window.opener) {
                    // Popup independiente → recargar abridor y cerrarse
                    window.opener.location.reload();
                    window.close();
                } else {
                    // Página directa → ir al índice (comportamiento original)
                    window.location.href = URL_IDX;
                }
            }, 300);
        } else {
            const detalle = (data.afectados || []).length ? '\n\n • ' + data.afectados.join('\n • ') : '';
            alert((data.message || 'Error al anular.') + detalle);
            btn.disabled = false;
            btn.textContent = '🗑 Confirmar Anulación';
        }
    } catch(e) {
        alert('Error de conexión.');
        btn.disabled = false;
    }
}
// ── Soporte consignación ─────────────────────────────────
function verSoporte(url) {
    const ov   = document.getElementById('soporte-ov');
    const img  = document.getElementById('soporte-img');
    const fr   = document.getElementById('soporte-frame');
    const isPdf = url.match(/\.pdf(\?|$)/i) || url.endsWith('/imagen'); // redirige, asumir PDF posible
    // Usamos iframe para cualquier contenido (funciona para imágenes y PDFs)
    img.style.display = 'none';
    fr.src = url;
    fr.style.display = 'block';
    ov.style.display = 'flex';
}
</script>

@if((auth()->user()?->hasRole('admin') || auth()->user()?->hasRole('superadmin')) && !request()->boolean('no_anular'))
@include('admin.facturacion._modal_corregir_pago')
@endif

{{-- Modal soporte consignación --}}
<div id="soporte-ov" onclick="if(event.target===this)document.getElementById('soporte-ov').style.display='none'"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.77);z-index:99999;align-items:center;justify-content:center;">
    <div style="position:relative;max-width:92vw;max-height:92vh;background:#1e293b;border-radius:12px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.6);">
        <button onclick="document.getElementById('soporte-ov').style.display='none'"
                style="position:absolute;top:8px;right:10px;background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:50%;width:28px;height:28px;font-size:1.1rem;cursor:pointer;z-index:1;line-height:1;">×</button>
        <img  id="soporte-img"  style="display:none;max-width:88vw;max-height:88vh;object-fit:contain;padding:8px;" alt="Soporte">
        <iframe id="soporte-frame" src="" style="display:block;width:82vw;height:88vh;border:none;"></iframe>
    </div>
</div>
@endsection
