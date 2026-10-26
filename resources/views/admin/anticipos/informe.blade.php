@extends('layouts.app')

@section('title', 'Informe de Anticipos')

@section('contenido')
<style>
/* ══════════════════════════════════════
   Informe de Anticipos — BryNex
══════════════════════════════════════ */
.ant-page { max-width: 1300px; margin: 0 auto; padding: 1.5rem 1rem; }

/* Header */
.ant-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: .75rem; margin-bottom: 1.5rem;
}
.ant-title {
    font-size: 1.45rem; font-weight: 900; color: #0f172a;
    display: flex; align-items: center; gap: .55rem;
}
.ant-title span { font-size: 1.6rem; }

/* Filtros */
.ant-filters {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px;
    padding: .9rem 1.2rem; display: flex; gap: .75rem; flex-wrap: wrap;
    align-items: flex-end; margin-bottom: 1.5rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.ant-flt-grp { display: flex; flex-direction: column; gap: .22rem; }
.ant-flt-lbl {
    font-size: .6rem; font-weight: 800; color: #64748b;
    text-transform: uppercase; letter-spacing: .05em;
}
.ant-flt-inp, .ant-flt-sel {
    padding: .38rem .65rem; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: .82rem; outline: none; background: #fff; font-family: inherit;
    transition: border-color .15s;
}
.ant-flt-inp:focus, .ant-flt-sel:focus { border-color: #d97706; }
.ant-flt-btn {
    padding: .42rem 1.2rem; background: linear-gradient(135deg,#78350f,#d97706);
    color: #fff; border: none; border-radius: 8px; cursor: pointer;
    font-size: .82rem; font-weight: 800; transition: all .18s; white-space: nowrap;
}
.ant-flt-btn:hover { opacity: .9; transform: translateY(-1px); }

/* Tarjetas totales */
.ant-totales {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(170px,1fr));
    gap: .85rem; margin-bottom: 1.5rem;
}
.ant-card {
    border-radius: 13px; padding: .85rem 1.1rem;
    display: flex; flex-direction: column; gap: .3rem;
}
.ant-card-label { font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; opacity: .8; }
.ant-card-val   { font-size: 1.45rem; font-weight: 900; font-family: monospace; }
.ant-card-recibido  { background: linear-gradient(135deg,#dbeafe,#eff6ff); color: #1e40af; }
.ant-card-aplicado  { background: linear-gradient(135deg,#dcfce7,#f0fdf4); color: #15803d; }
.ant-card-disponible{ background: linear-gradient(135deg,#fef3c7,#fffbeb); color: #92400e; }
.ant-card-devuelto  { background: linear-gradient(135deg,#fee2e2,#fff1f2); color: #991b1b; }
.ant-card-anulado   { background: linear-gradient(135deg,#fce7f3,#fff0f9); color: #831843; }

/* Tabla */
.ant-table-wrap {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px;
    overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.ant-table-wrap table {
    width: 100%; border-collapse: collapse; font-size: .8rem;
}
.ant-table-wrap thead th {
    background: #f8fafc; border-bottom: 2px solid #e2e8f0;
    padding: .55rem .85rem; font-weight: 800; color: #64748b;
    font-size: .65rem; text-transform: uppercase; letter-spacing: .06em;
    text-align: left; white-space: nowrap;
}
.ant-table-wrap tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .12s; }
.ant-table-wrap tbody tr:last-child { border-bottom: none; }
.ant-table-wrap tbody tr:hover { background: #fafafa; }
.ant-table-wrap tbody tr.fila-anulada { background: #fff5f5; opacity: .8; }
.ant-table-wrap tbody tr.fila-anulada:hover { background: #fee2e2; }
.ant-table-wrap td { padding: .5rem .85rem; color: #1e293b; vertical-align: middle; }
.ant-mono { font-family: 'JetBrains Mono', monospace; font-weight: 700; }

/* Badges */
.ant-badge {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .18rem .55rem; border-radius: 20px; font-size: .65rem; font-weight: 800;
}
.badge-disponible { background: #dcfce7; color: #15803d; }
.badge-parcial    { background: #fef3c7; color: #92400e; }
.badge-aplicado   { background: #f1f5f9; color: #64748b; }
.badge-devuelto   { background: #fee2e2; color: #991b1b; }
.badge-anulado    { background: #fce7f3; color: #831843; text-decoration: line-through; }
.badge-distribuido{ background: #ede9fe; color: #6d28d9; }

/* Forma pago */
.ant-forma {
    display: inline-flex; align-items: center; gap: .22rem;
    font-size: .7rem; font-weight: 700; color: #475569;
    background: #f8fafc; border: 1px solid #e2e8f0;
    padding: .1rem .4rem; border-radius: 5px;
}

/* Factura link */
.ant-factura-link {
    font-size: .7rem; color: #1d4ed8; font-weight: 700;
    text-decoration: none; background: #eff6ff;
    border: 1px solid #bfdbfe; border-radius: 5px; padding: .1rem .4rem;
    transition: background .15s;
}
.ant-factura-link:hover { background: #dbeafe; }

/* Botones de acción */
.btn-act {
    display: inline-flex; align-items: center; gap: .2rem;
    padding: .22rem .55rem; border-radius: 6px; font-size: .68rem;
    font-weight: 700; border: none; cursor: pointer; text-decoration: none;
    transition: all .15s; white-space: nowrap;
}
.btn-recibo  { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.btn-recibo:hover { background: #dbeafe; }
.btn-anular  { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
.btn-anular:hover { background: #ffe4e6; }

/* Empty */
.ant-empty {
    padding: 3rem; text-align: center; color: #94a3b8;
    font-size: .9rem; font-weight: 600;
}
.ant-empty-icon { font-size: 2.5rem; display: block; margin-bottom: .75rem; }

/* Pestañas anticipos / saldos */
.ant-tabs { display: flex; gap: .4rem; margin-bottom: 1.25rem; }
.ant-tab {
    padding: .45rem 1.1rem; border-radius: 9px; text-decoration: none;
    font-size: .82rem; font-weight: 800; border: 1.5px solid #e2e8f0;
    background: #fff; color: #64748b; transition: all .15s;
}
.ant-tab:hover { border-color: #cbd5e1; color: #334155; }
.ant-tab.activa { background: linear-gradient(135deg,#78350f,#d97706); color: #fff; border-color: transparent; }

/* Responsive */
@media (max-width: 640px) {
    .ant-table-wrap { overflow-x: auto; }
}

/* ── Modal Anular ─────────────────────────────────────────────────── */
#modal-anular-overlay {
    position: fixed; inset: 0;
    background: rgba(10,10,20,.65); backdrop-filter: blur(4px);
    z-index: 5000; display: none; align-items: center; justify-content: center; padding: 1rem;
}
#modal-anular-overlay.visible { display: flex; }
#modal-anular-box {
    background: #fff; border-radius: 16px; width: min(500px, 97vw);
    box-shadow: 0 24px 70px rgba(0,0,0,.4); overflow: hidden;
}
.ma-hdr {
    background: linear-gradient(135deg,#7f1d1d,#be123c);
    padding: .85rem 1.2rem; display: flex; justify-content: space-between; align-items: center;
}
.ma-title { font-size: .95rem; font-weight: 800; color: #fff; }
.ma-close { border: none; background: rgba(255,255,255,.15); color: #fff; border-radius: 6px; width: 28px; height: 28px; cursor: pointer; font-size: 1rem; }
.ma-body { padding: 1.2rem; }
.ma-info { background: #fff5f5; border: 1px solid #fecdd3; border-radius: 8px; padding: .65rem .9rem; font-size: .78rem; color: #9f1239; margin-bottom: 1rem; }
.ma-label { font-size: .65rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .05em; display: block; margin-bottom: .3rem; }
.ma-textarea {
    width: 100%; padding: .55rem .8rem; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-family: inherit; font-size: .82rem; resize: vertical; min-height: 90px;
    outline: none; transition: border-color .15s; box-sizing: border-box;
}
.ma-textarea:focus { border-color: #be123c; }
.ma-footer { display: flex; gap: .65rem; justify-content: flex-end; margin-top: 1rem; }
.ma-btn-cancel { padding: .45rem 1.1rem; border: 1.5px solid #e2e8f0; border-radius: 8px; background: #fff; color: #64748b; font-weight: 700; font-size: .82rem; cursor: pointer; }
.ma-btn-confirm { padding: .45rem 1.3rem; background: linear-gradient(135deg,#7f1d1d,#be123c); color: #fff; border: none; border-radius: 8px; font-weight: 800; font-size: .82rem; cursor: pointer; transition: opacity .15s; }
.ma-btn-confirm:hover { opacity: .9; }
.ma-btn-confirm:disabled { opacity: .5; cursor: not-allowed; }
.ma-char-count { font-size: .62rem; color: #94a3b8; text-align: right; margin-top: .2rem; }
</style>

<div class="ant-page">

    {{-- HEADER --}}
    <div class="ant-header">
        <h1 class="ant-title">
            <span>💰</span> Informe de Anticipos
        </h1>
        <a href="{{ url()->previous() }}"
           style="padding:.42rem 1.1rem;background:#fff;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:.8rem;font-weight:700;">
            ← Volver
        </a>
    </div>

    <div class="ant-tabs">
        <a href="{{ route('admin.anticipos.informe') }}" class="ant-tab activa">💰 Anticipos</a>
        <a href="{{ route('admin.anticipos.saldos') }}" class="ant-tab">🧾 Saldos a favor</a>
    </div>

    {{-- FILTROS --}}
    <form method="GET" action="{{ route('admin.anticipos.informe') }}" class="ant-filters">
        <div class="ant-flt-grp">
            <span class="ant-flt-lbl">Desde</span>
            <input type="date" name="desde" class="ant-flt-inp" value="{{ $desde }}">
        </div>
        <div class="ant-flt-grp">
            <span class="ant-flt-lbl">Hasta</span>
            <input type="date" name="hasta" class="ant-flt-inp" value="{{ $hasta }}">
        </div>
        <div class="ant-flt-grp">
            <span class="ant-flt-lbl">Estado</span>
            <select name="estado" class="ant-flt-sel">
                <option value="">— Todos —</option>
                <option value="disponible" {{ $estado === 'disponible' ? 'selected' : '' }}>✅ Disponible</option>
                <option value="parcial"    {{ $estado === 'parcial'    ? 'selected' : '' }}>🟡 Parcial</option>
                <option value="aplicado"   {{ $estado === 'aplicado'   ? 'selected' : '' }}>📋 Aplicado</option>
                <option value="devuelto"   {{ $estado === 'devuelto'   ? 'selected' : '' }}>↩️ Devuelto</option>
                <option value="anulado"    {{ $estado === 'anulado'    ? 'selected' : '' }}>🚫 Anulados</option>
            </select>
        </div>
        <button type="submit" class="ant-flt-btn">🔍 Filtrar</button>
    </form>

    {{-- TOTALES --}}
    <div class="ant-totales">
        <div class="ant-card ant-card-recibido">
            <span class="ant-card-label">💵 Total recibido</span>
            <span class="ant-card-val">${{ number_format($totales['recibido'], 0, ',', '.') }}</span>
            <span style="font-size:.68rem;opacity:.75;">{{ $anticipos->count() }} anticipo(s)</span>
        </div>
        <div class="ant-card ant-card-aplicado">
            <span class="ant-card-label">✅ Total aplicado</span>
            <span class="ant-card-val">${{ number_format($totales['aplicado'], 0, ',', '.') }}</span>
            <span style="font-size:.68rem;opacity:.75;">Ya vinculado a facturas</span>
        </div>
        {{-- Clickeable: lleva a la lista de los anticipos que forman ese saldo,
             para poder cuadrarlo renglón por renglón. --}}
        <a href="{{ route('admin.anticipos.informe') }}?ver=disponibles"
           class="ant-card ant-card-disponible" style="text-decoration:none;cursor:pointer;"
           title="Ver los anticipos que aún tienen saldo sin aplicar">
            <span class="ant-card-label">⏳ Disponible</span>
            {{-- El saldo va completo, sin el filtro de fechas: es lo que hay para
                 aplicar hoy, no un movimiento del periodo. --}}
            <span class="ant-card-val">${{ number_format($disponibleTotal ?? $totales['disponible'], 0, ',', '.') }}</span>
            <span style="font-size:.68rem;opacity:.75;">Saldo sin usar · clic para ver el detalle</span>
            @if(! ($soloDisponibles ?? false) && ($disponibleTotal ?? 0) != $totales['disponible'])
                <span style="font-size:.68rem;opacity:.75;">De lo recibido en este rango: ${{ number_format($totales['disponible'], 0, ',', '.') }}</span>
            @endif
        </a>
        <div class="ant-card ant-card-devuelto">
            <span class="ant-card-label">↩️ Devuelto</span>
            <span class="ant-card-val">${{ number_format($totales['devuelto'], 0, ',', '.') }}</span>
        </div>
        @if($estado === 'anulado')
        <div class="ant-card ant-card-anulado">
            <span class="ant-card-label">🚫 Anulado</span>
            <span class="ant-card-val">${{ number_format($totales['anulado'], 0, ',', '.') }}</span>
        </div>
        @endif
    </div>

    {{-- TABLA --}}
    <div class="ant-table-wrap">
        @if($anticipos->isEmpty())
            <div class="ant-empty">
                <span class="ant-empty-icon">💰</span>
                No hay anticipos registrados en el período seleccionado.
            </div>
        @else
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha pago</th>
                    <th>Cliente / Empresa</th>
                    <th>Forma</th>
                    <th>Empresa</th>
                    <th style="text-align:right">Valor</th>
                    <th style="text-align:right">Aplicado</th>
                    <th style="text-align:right">Disponible</th>
                    <th>Estado</th>
                    <th>Factura</th>
                    <th>Registrado por</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            @foreach($anticipos as $ant)
            @php $esAnulado = $ant->trashed(); @endphp
            <tr class="{{ $esAnulado ? 'fila-anulada' : '' }}">
                {{-- ID --}}
                <td style="font-size:.68rem;color:#94a3b8;font-weight:700;">#{{ $ant->id }}</td>

                {{-- Fecha --}}
                <td class="ant-mono" style="font-size:.75rem;">
                    {{ $ant->fecha_pago->format('d/m/Y') }}
                </td>

                {{-- Cliente o empresa — CORREGIDO: muestra nombre, no cédula --}}
                <td>
                    @if($ant->empresa && !$ant->contrato_id)
                        <div style="font-weight:700;font-size:.78rem;">🏢 {{ $ant->empresa->empresa }}</div>
                        <div style="font-size:.65rem;color:#94a3b8;">Empresa</div>
                    @elseif($ant->contrato?->cliente)
                        <div style="font-weight:700;font-size:.78rem;">
                            {{ nombre_oracion($ant->contrato->cliente->nombre ?? $ant->contrato->cliente->nombre_completo ?? 'Sin nombre') }}
                        </div>
                        <div style="font-size:.65rem;color:#94a3b8;">CC {{ $ant->cedula }}</div>
                    @elseif($ant->cedula)
                        {{-- fallback: al menos mostrar la cédula una vez --}}
                        <div style="font-weight:700;font-size:.78rem;">👤 CC {{ $ant->cedula }}</div>
                        <div style="font-size:.65rem;color:#94a3b8;">Individual</div>
                    @else
                        <span style="color:#94a3b8;font-size:.75rem;">—</span>
                    @endif
                </td>

                {{-- Forma de pago --}}
                <td>
                    <span class="ant-forma">
                        {{ match($ant->forma_pago) {
                            'efectivo'      => '💵',
                            'nequi'         => '📱',
                            'consignacion'  => '🏦',
                            'transferencia' => '↔️',
                            default         => '💳',
                        } }}
                        {{ \App\Models\Anticipo::FORMAS_PAGO[$ant->forma_pago] ?? ucfirst($ant->forma_pago) }}
                    </span>
                </td>

                {{-- Empresa a la que pertenece (la del anticipo, o la del cliente).
                     La empresa 1 es el cajón de los que no tienen ninguna. --}}
                @php
                    $empId  = $ant->empresa_id ?: ($ant->contrato?->cliente?->cod_empresa ?? null);
                    $empNom = ($empId && (int) $empId !== 1)
                        ? ($empresasPorId[$empId] ?? null)
                        : null;
                @endphp
                <td style="font-size:.7rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="{{ $empNom ?? 'Sin empresa' }}">
                    @if($empNom)
                        <span style="color:#334155;font-weight:700;">{{ $empNom }}</span>
                    @else
                        <span style="color:#94a3b8;">Individual</span>
                    @endif
                </td>

                {{-- Valor total --}}
                <td class="ant-mono" style="text-align:right;color:#1e40af;{{ $esAnulado ? 'text-decoration:line-through;opacity:.6;' : '' }}">
                    ${{ number_format($ant->valor, 0, ',', '.') }}
                </td>

                {{-- Aplicado --}}
                <td class="ant-mono" style="text-align:right;color:#15803d;">
                    @if($ant->valor_aplicado > 0)
                        ${{ number_format($ant->valor_aplicado, 0, ',', '.') }}
                    @else
                        <span style="color:#94a3b8;">—</span>
                    @endif
                </td>

                {{-- Disponible --}}
                <td class="ant-mono" style="text-align:right;color:#d97706;font-weight:900;">
                    @if(!$esAnulado && $ant->valor_disponible > 0)
                        ${{ number_format($ant->valor_disponible, 0, ',', '.') }}
                    @else
                        <span style="color:#94a3b8;">$0</span>
                    @endif
                </td>

                {{-- Estado --}}
                <td>
                    <span class="ant-badge badge-{{ $esAnulado ? 'anulado' : $ant->estado }}">
                        {{ $ant->etiqueta_estado }}
                    </span>
                    @if($esAnulado && $ant->motivo_anulacion)
                        <div style="font-size:.6rem;color:#94a3b8;margin-top:.2rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $ant->motivo_anulacion }}">
                            {{ Str::limit($ant->motivo_anulacion, 30) }}
                        </div>
                    @endif
                </td>

                {{-- Factura vinculada --}}
                <td>
                    @if($ant->factura)
                        <a href="{{ route('admin.facturacion.recibo', $ant->factura->id) }}"
                           target="_blank" class="ant-factura-link">
                            #{{ $ant->factura->numero_factura }}
                            <span style="font-weight:500;font-size:.62rem;opacity:.75;">
                                {{ $ant->factura->mes }}/{{ $ant->factura->anio }}
                            </span>
                        </a>
                    @else
                        <span style="color:#94a3b8;font-size:.7rem;">Sin factura</span>
                    @endif
                </td>

                {{-- Usuario --}}
                <td style="font-size:.7rem;color:#64748b;">
                    {{ $ant->usuario?->nombre ?? 'Sistema' }}
                </td>

                {{-- Acciones --}}
                <td>
                    <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:nowrap;">
                        {{-- Ver Recibo --}}
                        <a href="{{ route('admin.anticipos.recibo', $ant->id) }}"
                           target="_blank"
                           class="btn-act btn-recibo"
                           title="Ver recibo del anticipo">
                            🧾 Recibo
                        </a>

                        {{-- Anular (solo si puede anularse) --}}
                        @if(!$esAnulado && $ant->puedeAnularse() && auth()->user()->can('anticipos.anular'))
                            <button type="button"
                                class="btn-act btn-anular"
                                onclick="abrirModalAnular({{ $ant->id }}, '${{ number_format($ant->valor, 0, ',', '.') }}')"
                                title="Anular este anticipo">
                                🚫 Anular
                            </button>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>

    {{-- Nota informativa --}}
    <div style="margin-top:1.2rem;padding:.65rem 1rem;background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;font-size:.75rem;color:#78350f;font-weight:600;line-height:1.7;">
        📌 <strong>Cómo funciona:</strong>
        Los anticipos se registran en el mes que se reciben y aparecen como ingreso en el cuadre diario de ese día.
        Cuando se factura, el valor aplicado queda como <em>Anticipo aplicado</em> en la factura —
        sin sumarlo de nuevo como ingreso nuevo — evitando doble conteo en el cuadre diario del mes de facturación.
        <br>🚫 <strong>Anulación:</strong> Solo se pueden anular anticipos disponibles sin aplicar. Si la factura asociada fue anulada, el anticipo queda libre automáticamente.
    </div>

</div>

{{-- ── MODAL ANULAR ─────────────────────────────────────────────────── --}}
<div id="modal-anular-overlay" role="dialog" aria-modal="true">
    <div id="modal-anular-box">
        <div class="ma-hdr">
            <span class="ma-title">🚫 Anular Anticipo</span>
            <button class="ma-close" onclick="cerrarModalAnular()" title="Cerrar">✕</button>
        </div>
        <div class="ma-body">
            <div class="ma-info" id="ma-info-texto">
                ¿Estás seguro que deseas anular este anticipo? Esta acción no se puede deshacer fácilmente.
            </div>
            <label class="ma-label" for="ma-motivo">Motivo de anulación <span style="color:#be123c;">*</span></label>
            <textarea id="ma-motivo" class="ma-textarea" placeholder="Describe el motivo de la anulación (mínimo 5 caracteres)..." maxlength="500"></textarea>
            <div class="ma-char-count"><span id="ma-char-num">0</span>/500</div>
            <div id="ma-error" style="color:#be123c;font-size:.72rem;font-weight:700;margin-top:.4rem;display:none;"></div>
        </div>
        <div class="ma-footer">
            <button class="ma-btn-cancel" onclick="cerrarModalAnular()">Cancelar</button>
            <button class="ma-btn-confirm" id="ma-btn-confirmar" onclick="confirmarAnulacion()">
                🚫 Confirmar Anulación
            </button>
        </div>
    </div>
</div>

<script>
let maAnticipoId = null;

function abrirModalAnular(id, valorStr) {
    maAnticipoId = id;
    document.getElementById('ma-info-texto').textContent =
        '¿Anular el anticipo #' + id + ' por ' + valorStr + '? Esta acción es irreversible.';
    document.getElementById('ma-motivo').value = '';
    document.getElementById('ma-char-num').textContent = '0';
    document.getElementById('ma-error').style.display = 'none';
    document.getElementById('ma-btn-confirmar').disabled = false;
    document.getElementById('modal-anular-overlay').classList.add('visible');
    setTimeout(() => document.getElementById('ma-motivo').focus(), 100);
}

function cerrarModalAnular() {
    document.getElementById('modal-anular-overlay').classList.remove('visible');
    maAnticipoId = null;
}

document.getElementById('ma-motivo').addEventListener('input', function() {
    document.getElementById('ma-char-num').textContent = this.value.length;
});

document.getElementById('modal-anular-overlay').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalAnular();
});

async function confirmarAnulacion() {
    const motivo = document.getElementById('ma-motivo').value.trim();
    const errDiv = document.getElementById('ma-error');

    if (!motivo || motivo.length < 5) {
        errDiv.textContent = '⚠️ El motivo debe tener al menos 5 caracteres.';
        errDiv.style.display = 'block';
        return;
    }

    errDiv.style.display = 'none';
    const btn = document.getElementById('ma-btn-confirmar');
    btn.disabled = true;
    btn.textContent = '⏳ Anulando...';

    try {
        const resp = await fetch(`/admin/anticipos/${maAnticipoId}/anular`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                             ?? '{{ csrf_token() }}',
            },
            body: JSON.stringify({ motivo_anulacion: motivo }),
        });

        const data = await resp.json();

        if (data.ok) {
            cerrarModalAnular();
            // Recargar la página para reflejar el cambio
            window.location.reload();
        } else {
            errDiv.textContent = '❌ ' + (data.mensaje ?? 'Error al anular.');
            errDiv.style.display = 'block';
            btn.disabled = false;
            btn.textContent = '🚫 Confirmar Anulación';
        }
    } catch (err) {
        errDiv.textContent = '❌ Error de conexión. Intenta de nuevo.';
        errDiv.style.display = 'block';
        btn.disabled = false;
        btn.textContent = '🚫 Confirmar Anulación';
    }
}
</script>

@endsection
