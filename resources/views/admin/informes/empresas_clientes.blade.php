@extends('layouts.app')
@section('modulo','Empresas Clientes')
@section('contenido')
@php
    $maxMov = max(1, $totales->max(fn ($t) => max($t['ingresos'], $t['retiros'])));
    $signo = fn ($n) => $n > 0 ? '+'.number_format($n, 0, ',', '.') : number_format($n, 0, ',', '.');
@endphp

<style>
.ec-wrap { max-width: 1400px; margin: 0 auto; padding: 0 1rem 2rem; }
.ec-hero {
    background: linear-gradient(90deg, #0a1628, #0d2550 55%, #1e40af);
    border-radius: 14px; padding: 1.4rem 1.75rem; color: #fff;
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;
}
.ec-hero h1 { font-size: 1.3rem; font-weight: 800; margin: 0; }
.ec-hero p { margin: .2rem 0 0; color: #93c5fd; font-size: .82rem; }
.ec-hero a.volver { color: #93c5fd; font-size: .8rem; text-decoration: none; }
.ec-btn { background: #2563eb; color: #fff; border-radius: 8px; padding: .5rem 1rem; font-size: .8rem; font-weight: 700; text-decoration: none; white-space: nowrap; }
.ec-btn:hover { background: #3b82f6; }

.ec-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .9rem; margin-bottom: 1.25rem; }
.ec-kpi { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: .9rem 1.1rem; }
.ec-kpi .n { font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
.ec-kpi .l { font-size: .72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .04em; margin-top: .2rem; }

.ec-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.25rem; overflow: hidden; }
.ec-card-head { padding: .9rem 1.2rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
.ec-card-head h2 { font-size: .95rem; font-weight: 800; color: #0d2550; margin: 0; flex: 1; }
.ec-card-head small { color: #64748b; font-size: .75rem; }

/* Semestre: barras de ingresos y retiros por mes */
.ec-meses { display: grid; grid-template-columns: repeat(6, 1fr); gap: .75rem; padding: 1.1rem 1.2rem 1.2rem; }
.ec-mes { border: 1px solid #e2e8f0; border-radius: 10px; padding: .75rem .8rem; }
.ec-mes .t { font-size: .74rem; font-weight: 800; color: #0d2550; text-transform: uppercase; }
.ec-barra { margin-top: .45rem; font-size: .72rem; color: #475569; white-space: nowrap; }
.ec-barra .pista { display: block; height: 7px; background: #f1f5f9; border-radius: 4px; margin-top: .2rem; overflow: hidden; }
.ec-barra span.b { display: block; height: 100%; border-radius: 4px; min-width: 2px; }
.ec-barra .ing { background: #2563eb; }
.ec-barra .ret { background: #f59e0b; }
.ec-mes .bal { margin-top: .55rem; font-size: .9rem; font-weight: 800; }

.sube { color: #059669; } .baja { color: #dc2626; } .igual { color: #64748b; }
.ec-badge { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .7rem; font-weight: 700; white-space: nowrap; }
.ec-badge.sube { background: #d1fae5; } .ec-badge.baja { background: #fee2e2; } .ec-badge.igual { background: #f1f5f9; }

.ec-filtros { display: flex; gap: .4rem; flex-wrap: wrap; }
.ec-chip { border: 1px solid #cbd5e1; background: #fff; color: #475569; border-radius: 999px; padding: .3rem .8rem; font-size: .75rem; font-weight: 700; cursor: pointer; font-family: inherit; }
.ec-chip.activo { background: #2563eb; border-color: #2563eb; color: #fff; }
.ec-buscar { border: 1px solid #cbd5e1; border-radius: 8px; padding: .4rem .7rem; font-size: .8rem; min-width: 220px; font-family: inherit; }
.ec-buscar:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.15); }

.ec-tabla-wrap { overflow-x: auto; }
.ec-tabla { width: 100%; border-collapse: collapse; font-size: .8rem; }
.ec-tabla th { background: #eff6ff; color: #0d2550; font-size: .66rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; padding: .6rem .55rem; border-bottom: 2px solid #bfdbfe; white-space: nowrap; position: sticky; top: 0; }
.ec-tabla td { padding: .55rem .55rem; border-bottom: 1px solid #f1f5f9; color: #334155; }
.ec-tabla tbody tr:nth-child(even) td { background: #fafcff; }
.ec-tabla tbody tr:hover td { background: #eff6ff; }
.ec-tabla .num { text-align: center; white-space: nowrap; }
.ec-tabla .n-row { color: #94a3b8; font-weight: 700; font-size: .72rem; text-align: center; width: 1%; }
.ec-tabla tr.sin-activos td { color: #94a3b8; }
.ec-tabla .mov { font-size: .72rem; white-space: nowrap; text-align: center; }
.ec-tabla .mov .i { color: #2563eb; font-weight: 700; }
.ec-tabla .mov .r { color: #d97706; font-weight: 700; }
.ec-tabla .mov .cero { color: #cbd5e1; }
.ec-nota { font-size: .74rem; color: #64748b; padding: .8rem 1.2rem; border-top: 1px solid #e2e8f0; line-height: 1.5; }

@media (max-width: 900px) { .ec-meses { grid-template-columns: repeat(3, 1fr); } }
</style>

<div class="ec-wrap">
    <div class="ec-hero">
        <div style="flex:1;">
            <a href="{{ route('admin.informes.hub') }}" class="volver">← Informes</a>
            <h1>🏭 Empresas Clientes</h1>
            <p>Contratos activos hoy y cómo se movió cada empresa en los últimos 6 meses</p>
        </div>
        <a href="{{ route('admin.informes.empresas_clientes', ['excel' => 1]) }}" class="ec-btn">📥 Descargar Excel</a>
    </div>

    {{-- KPIs --}}
    <div class="ec-kpis">
        <div class="ec-kpi"><div class="n" style="color:#2563eb;">{{ number_format($resumen['con_activos'], 0, ',', '.') }}</div><div class="l">Empresas con activos</div></div>
        <div class="ec-kpi"><div class="n" style="color:#0d2550;">{{ number_format($resumen['contratos'], 0, ',', '.') }}</div><div class="l">Contratos activos</div></div>
        <div class="ec-kpi"><div class="n sube">{{ $resumen['crecieron'] }}</div><div class="l">Crecieron en 6 meses</div></div>
        <div class="ec-kpi"><div class="n baja">{{ $resumen['bajaron'] }}</div><div class="l">Bajaron en 6 meses</div></div>
        <div class="ec-kpi"><div class="n" style="color:#64748b;">{{ number_format($resumen['empresas'], 0, ',', '.') }}</div><div class="l">Empresas registradas</div></div>
    </div>

    {{-- Semestre del aliado --}}
    <div class="ec-card">
        <div class="ec-card-head">
            <h2>📈 Últimos 6 meses</h2>
            @php $balTotal = $totales->sum('balance'); @endphp
            <small>Ingresos {{ number_format($totales->sum('ingresos'), 0, ',', '.') }} · Retiros {{ number_format($totales->sum('retiros'), 0, ',', '.') }} ·</small>
            <span class="ec-badge {{ $balTotal > 0 ? 'sube' : ($balTotal < 0 ? 'baja' : 'igual') }}">
                {{ $balTotal > 0 ? '▲ Creció' : ($balTotal < 0 ? '▼ Bajó' : '● Estable') }} {{ $signo($balTotal) }}
            </span>
        </div>
        <div class="ec-meses">
            @foreach($totales as $t)
            <div class="ec-mes">
                <div class="t">{{ $t['label'] }}</div>
                <div class="ec-barra"><strong>{{ $t['ingresos'] }}</strong> ingresos<span class="pista"><span class="b ing" style="width:{{ round($t['ingresos'] / $maxMov * 100) }}%;"></span></span></div>
                <div class="ec-barra"><strong>{{ $t['retiros'] }}</strong> retiros<span class="pista"><span class="b ret" style="width:{{ round($t['retiros'] / $maxMov * 100) }}%;"></span></span></div>
                <div class="bal {{ $t['balance'] > 0 ? 'sube' : ($t['balance'] < 0 ? 'baja' : 'igual') }}">
                    {{ $t['balance'] > 0 ? '▲' : ($t['balance'] < 0 ? '▼' : '●') }} {{ $signo($t['balance']) }}
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Empresas --}}
    <div class="ec-card">
        <div class="ec-card-head">
            <h2>🏢 Empresas</h2>
            <div class="ec-filtros">
                <button type="button" class="ec-chip activo" data-filtro="activos">Con activos</button>
                <button type="button" class="ec-chip" data-filtro="crecieron">Crecieron</button>
                <button type="button" class="ec-chip" data-filtro="bajaron">Bajaron</button>
                <button type="button" class="ec-chip" data-filtro="todas">Todas</button>
            </div>
            <input type="search" class="ec-buscar" id="ec-buscar" placeholder="🔍 Buscar empresa o NIT...">
            <span class="ec-badge igual" id="ec-contador"></span>
        </div>
        <div class="ec-tabla-wrap">
            <table class="ec-tabla">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th style="text-align:left;">Empresa</th>
                        <th class="num">Clientes</th>
                        <th class="num">Activos</th>
                        @foreach($meses as $m)
                            <th class="num" title="Ingresos / retiros del mes">{{ $m['label'] }}</th>
                        @endforeach
                        <th class="num">Ingresos</th>
                        <th class="num">Retiros</th>
                        <th class="num">Balance</th>
                        <th class="num">Tendencia</th>
                    </tr>
                </thead>
                <tbody id="ec-filas">
                    @foreach($data as $e)
                    <tr class="{{ $e->contratos > 0 ? '' : 'sin-activos' }}"
                        data-activos="{{ $e->contratos }}" data-balance="{{ $e->balance }}"
                        data-texto="{{ mb_strtolower(\Illuminate\Support\Str::ascii(($e->empresa ?? '').' '.($e->nit ?? ''))) }}">
                        <td class="n-row"></td>
                        <td>
                            <div style="font-weight:700;color:{{ $e->contratos > 0 ? '#0d2550' : '#94a3b8' }};">{{ $e->empresa ?? '—' }}</div>
                            <div style="font-size:.7rem;color:#94a3b8;">{{ $e->nit ? 'NIT '.$e->nit : '' }}</div>
                        </td>
                        <td class="num" style="font-weight:700;color:#0891b2;">{{ $e->clientes }}</td>
                        <td class="num" style="font-weight:800;font-size:.95rem;color:{{ $e->contratos > 0 ? '#2563eb' : '#cbd5e1' }};">{{ $e->contratos }}</td>
                        @foreach($e->meses as $mv)
                            <td class="mov">
                                @if($mv['ingresos'] + $mv['retiros'] === 0)
                                    <span class="cero">·</span>
                                @else
                                    <span class="i">+{{ $mv['ingresos'] }}</span> <span class="r">−{{ $mv['retiros'] }}</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="num" style="color:#2563eb;font-weight:700;">{{ $e->ingresos }}</td>
                        <td class="num" style="color:#d97706;font-weight:700;">{{ $e->retiros }}</td>
                        <td class="num" style="font-weight:800;">
                            <span class="{{ $e->balance > 0 ? 'sube' : ($e->balance < 0 ? 'baja' : 'igual') }}">{{ $signo($e->balance) }}</span>
                        </td>
                        <td class="num">
                            @if($e->balance > 0)
                                <span class="ec-badge sube">▲ Creció</span>
                            @elseif($e->balance < 0)
                                <span class="ec-badge baja">▼ Bajó</span>
                            @else
                                <span class="ec-badge igual">● Estable</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    <tr id="ec-vacio" style="display:none;"><td colspan="{{ 8 + $meses->count() }}" style="padding:2rem;text-align:center;color:#94a3b8;">Sin empresas para este filtro</td></tr>
                </tbody>
            </table>
        </div>
        <div class="ec-nota">
            <strong>Activos</strong>: contratos vigentes hoy de los clientes de la empresa (la de la ficha del cliente).
            <strong>Ingresos</strong> por fecha de ingreso del contrato y <strong>retiros</strong> por fecha de retiro, mes a mes; el mes actual va corrido.
            <strong>Balance</strong> = ingresos − retiros del semestre: creció si es positivo, bajó si es negativo.
            @if($resumen['sin_empresa_ingresos'] + $resumen['sin_empresa_retiros'] > 0)
                Los clientes sin empresa en la ficha suman {{ $resumen['sin_empresa_ingresos'] }} ingresos y {{ $resumen['sin_empresa_retiros'] }} retiros: cuentan en los totales de arriba pero no en ninguna fila.
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    const filas = Array.from(document.querySelectorAll('#ec-filas tr[data-activos]'));
    const buscar = document.getElementById('ec-buscar');
    const contador = document.getElementById('ec-contador');
    const vacio = document.getElementById('ec-vacio');
    let filtro = 'activos';

    const norm = t => (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    function aplicar() {
        const q = norm(buscar.value.trim());
        let n = 0;
        filas.forEach(tr => {
            const activos = +tr.dataset.activos, balance = +tr.dataset.balance;
            const pasa = (filtro === 'todas'
                    || (filtro === 'activos' && activos > 0)
                    || (filtro === 'crecieron' && balance > 0)
                    || (filtro === 'bajaron' && balance < 0))
                && (!q || tr.dataset.texto.includes(q));
            tr.style.display = pasa ? '' : 'none';
            if (pasa) tr.querySelector('.n-row').textContent = ++n;
        });
        contador.textContent = `${n} ${n === 1 ? 'empresa' : 'empresas'}`;
        vacio.style.display = n ? 'none' : '';
    }

    document.querySelectorAll('.ec-chip').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('.ec-chip').forEach(x => x.classList.remove('activo'));
        b.classList.add('activo');
        filtro = b.dataset.filtro;
        aplicar();
    }));
    buscar.addEventListener('input', aplicar);
    aplicar();
})();
</script>
@endsection
