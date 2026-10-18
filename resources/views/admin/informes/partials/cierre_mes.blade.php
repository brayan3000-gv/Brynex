{{--
    Cierre del mes (ver App\Services\Caja\CierreMes): cómo le fue al mes, qué
    plata pasa al siguiente, qué falta por cobrar, dónde está la plata y si
    cuadra. Saldos, no movimientos: lo que entró y salió de cada cuenta en el
    mes queda abajo, en «Movimientos del mes por cuenta».
--}}
@php
    $c = $cierre;
    $esSuper = auth()->user()->hasRole('superadmin');
    $mesNom  = $mesesEs[$mes] ?? $mes;
    $sigNom  = $mesesEs[($mes % 12) + 1] ?? '';
    $antNom  = $mesesEs[$mes === 1 ? 12 : $mes - 1] ?? '';
    $corteTx = \Carbon\Carbon::parse($c['corte'])->format('d/m/Y');
    $tipoEtq = ['cobro' => ['cobro', '#dbeafe', '#1e40af'], 'razon_social' => ['razón social', '#dcfce7', '#166534'], 'personal' => ['personal', '#ede9fe', '#5b21b6']];
    $pesos   = fn ($v) => ($v < 0 ? '−' : '') . '$' . number_format(abs((int) $v), 0, ',', '.');
@endphp
<style>
.cm-wrap{margin-bottom:1.5rem}
.cm-hero{background:linear-gradient(90deg,#0a1628,#0d2550 55%,#1e40af);border-radius:14px;padding:1rem 1.3rem;color:#fff;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.cm-hero h2{margin:0;font-size:1.05rem;font-weight:800}
.cm-hero p{margin:.15rem 0 0;color:#93c5fd;font-size:.76rem}
.cm-chip{margin-left:auto;font-size:.7rem;font-weight:700;padding:.3rem .7rem;border-radius:999px}
.cm-sec{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin:1.1rem 0 .5rem}
.cm-sec span{text-transform:none;letter-spacing:0;font-weight:600;color:#64748b}
.cm-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.cm-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow-x:auto}
.cm-head{padding:.6rem .9rem;display:flex;align-items:center;gap:.5rem;border-bottom:1px solid #f1f5f9;font-size:.84rem;font-weight:700}
.cm-head span{margin-left:auto;font-size:.68rem;color:#64748b;font-weight:500}
.cm-fila{display:flex;align-items:center;gap:.6rem;padding:.45rem .9rem;border-bottom:1px solid #f8fafc;font-size:.78rem}
.cm-fila .s{font-size:.66rem;color:#94a3b8}
.cm-fila .v{margin-left:auto;font-weight:700;font-family:monospace;white-space:nowrap}
.cm-total{display:flex;align-items:center;padding:.6rem .9rem;background:#f8fafc;font-weight:800;font-size:.82rem}
.cm-total .v{margin-left:auto;font-family:monospace}
.cm-util{background:linear-gradient(135deg,#065f46,#059669);color:#fff;border-radius:14px;padding:.85rem 1.1rem;display:flex;align-items:center;gap:1rem;margin-top:.9rem;flex-wrap:wrap}
.cm-util.neg{background:linear-gradient(135deg,#7f1d1d,#dc2626)}
.cm-util .l{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;opacity:.8;font-weight:700}
.cm-util .n{font-size:1.5rem;font-weight:800;font-family:monospace}
.cm-tbl{width:100%;border-collapse:collapse;font-size:.76rem}
.cm-tbl th{text-align:left;font-size:.6rem;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;padding:.5rem .7rem;border-bottom:1px solid #e2e8f0;background:#fafbfc}
.cm-tbl td{padding:.45rem .7rem;border-bottom:1px solid #f1f5f9;vertical-align:top}
.cm-tbl .n{text-align:right;font-family:monospace;white-space:nowrap}
.cm-etq{font-size:.6rem;font-weight:700;border-radius:4px;padding:.03rem .3rem}
.cm-eq{display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;padding:.9rem;text-align:center}
.cm-eq .l{font-size:.6rem;color:#64748b;text-transform:uppercase;font-weight:700}
.cm-eq .n{font-size:1rem;font-weight:800;font-family:monospace}
.cm-eq .op{font-size:1.1rem;color:#94a3b8;font-weight:800}
.cm-res{padding:.65rem .9rem;font-size:.8rem;font-weight:700}
.cm-btn{border:0;border-radius:8px;padding:.45rem .85rem;font-size:.74rem;font-weight:700;cursor:pointer}
.cm-in{width:130px;border:1px dashed #93c5fd;border-radius:6px;padding:.25rem .4rem;font-size:.74rem;text-align:right;background:#f8fbff;font-family:monospace}
@media(max-width:800px){.cm-grid{grid-template-columns:1fr}}
</style>

<div class="cm-wrap">
    <div class="cm-hero">
        <div>
            <h2>📘 Cierre del mes · {{ $mesNom }} {{ $anio }}</h2>
            <p>
                @if($c['en_curso']) en curso · cifras al {{ $corteTx }} · la foto se guarda sola el día 1
                @elseif($c['foto_at']) foto del {{ $c['foto_at']->format('d/m/Y H:i') }} · lo que pasó a {{ $sigNom }} quedó fijo
                @else el mes terminó y todavía no tiene foto
                @endif
                @if($c['es_apertura']) · <b style="color:#fde68a">primer cierre (apertura)</b>@endif
            </p>
        </div>
        <span class="cm-chip" style="{{ $c['en_curso'] ? 'background:#fef3c7;color:#92400e' : ($c['foto_at'] ? 'background:#d1fae5;color:#065f46' : 'background:#fee2e2;color:#991b1b') }}">
            {{ $c['en_curso'] ? '🕐 En curso' : ($c['foto_at'] ? '✅ Cerrado' : '⚠️ Sin foto') }}
        </span>
        @if($esSuper && !$c['en_curso'])
        <form method="POST" action="{{ route('admin.informes.financiero.cierre.foto') }}" style="margin:0"
              onsubmit="return {{ $c['foto_at'] ? "confirm('¿Rehacer la foto? Lo que pasó al mes siguiente se recalcula con las cifras de hoy.')" : 'true' }}">
            @csrf
            <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            @if($c['foto_at'])<input type="hidden" name="rehacer" value="1">@endif
            <button class="cm-btn" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)">
                📸 {{ $c['foto_at'] ? 'Rehacer foto' : 'Guardar foto' }}
            </button>
        </form>
        @endif
    </div>

    @if($c['cambios'])
    <div style="margin-top:.8rem;background:#fff7ed;border:2px solid #fb923c;border-radius:12px;padding:.7rem 1rem;font-size:.78rem;color:#9a3412">
        <b>⚠️ {{ $mesNom }} cambió después del cierre.</b>
        @foreach($c['cambios'] as [$n, $d]) {{ $n }} {{ $d > 0 ? '+' : '' }}{{ $pesos($d) }}@if(!$loop->last) · @endif @endforeach.
        Se sigue mostrando la foto; si el cambio es una corrección, rehaz la foto.
    </div>
    @endif

    @if(!$c['en_curso'] && !$c['hay_reales'])
    <div style="margin-top:.8rem;background:#fff7ed;border:2px solid #fb923c;border-radius:12px;padding:.7rem 1rem;display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
        <div style="font-size:.8rem;color:#9a3412"><b>⚠️ FALTA ANOTAR EL CIERRE DE {{ mb_strtoupper($mesNom) }}.</b>
            Mientras no se anote cuánto hay de verdad en cada cuenta y en efectivo al {{ $corteTx }}, se usan los saldos según BryNex.</div>
        @if($esSuper)
        <button type="button" class="cm-btn" style="background:#ea580c;color:#fff;margin-left:auto" onclick="document.getElementById('cm-reales').open=true;document.getElementById('cm-reales').scrollIntoView({behavior:'smooth'})">Anotar saldos reales</button>
        @endif
    </div>
    @endif

    {{-- ── 1 · Cómo le fue al mes ─────────────────────────────────────── --}}
    <div class="cm-sec">📈 1 · Cómo le fue al mes <span>— lo que es de la empresa; no pasa al mes siguiente</span></div>
    <div class="cm-grid">
        <div class="cm-card">
            <div class="cm-head" style="background:#f0fdf4">Ingresos <span>por mes en que se pagó</span></div>
            @foreach($c['a']['ingresos'] as [$n, $v])
                @if($v != 0)<div class="cm-fila"><div>{{ $n }}</div><div class="v">{{ $pesos($v) }}</div></div>@endif
            @endforeach
            <div class="cm-total">Total ingresos<div class="v">{{ $pesos($c['a']['total_ingresos']) }}</div></div>
        </div>
        <div class="cm-card">
            <div class="cm-head" style="background:#fef2f2">Egresos <span>por mes al que corresponde</span></div>
            @foreach($c['a']['egresos'] as [$n, $v])
                <div class="cm-fila"><div>{{ $n }}</div><div class="v" style="{{ $v == 0 ? 'color:#94a3b8' : '' }}">{{ $pesos($v) }}</div></div>
            @endforeach
            <div class="cm-total">Total egresos<div class="v">{{ $pesos($c['a']['total_egresos']) }}</div></div>
        </div>
    </div>
    <div class="cm-util {{ $c['a']['utilidad'] < 0 ? 'neg' : '' }}">
        <div><div class="l">Utilidad {{ $c['en_curso'] ? 'a la fecha' : 'de ' . $mesNom }}</div><div class="n">{{ $pesos($c['a']['utilidad']) }}</div></div>
        <div style="margin-left:auto;font-size:.74rem;opacity:.9;text-align:right;line-height:1.5">
            Retiros del dueño en el mes: {{ $pesos($c['a']['retiros']) }}<br>
            Utilidad acumulada al terminar: <b>{{ $pesos($c['util_despues']) }}</b>
        </div>
    </div>

    {{-- ── 2 · Pasa al mes siguiente · 3 · Por cobrar ──────────────────── --}}
    <div class="cm-sec">↪️ 2 · Plata que pasa a {{ $sigNom }} <span>— entró pero no es de la empresa todavía</span></div>
    @if($c['llego'] !== null)
    <div style="background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;border-radius:10px;padding:.5rem .85rem;font-size:.76rem;margin-bottom:.6rem">
        ↩️ Llegó de {{ $antNom }}: <b style="font-family:monospace">{{ $pesos($c['llego']) }}</b>
        <span style="color:#6366f1">— @foreach($c['llego_detalle'] as $it)@if($it[3] != 0){{ $it[1] }} {{ $pesos($it[3]) }}@if(!$loop->last) · @endif @endif @endforeach</span>
    </div>
    @endif
    <div class="cm-grid">
        <div class="cm-card">
            <div class="cm-head" style="background:#eef2ff">{{ $c['en_curso'] ? 'Pasaría a ' . $sigNom . ' si cerrara hoy' : 'Pasa a ' . $sigNom }} <span>al {{ $corteTx }}</span></div>
            @foreach($c['b'] as [$k, $n, $s, $v])
                @if($v != 0)
                <div class="cm-fila"><div>{{ $n }}<div class="s">{{ $s }}</div></div><div class="v" style="{{ $v < 0 ? 'color:#dc2626' : '' }}">{{ $pesos($v) }}</div></div>
                @endif
            @endforeach
            <div class="cm-total">Total que pasa<div class="v">{{ $pesos($c['total_b']) }}</div></div>
        </div>
        <div class="cm-card">
            <div class="cm-head" style="background:#faf5ff">📋 3 · Por cobrar <span>ya contó como ingreso</span></div>
            @foreach($c['c'] as [$k, $n, $s, $v])
                <div class="cm-fila"><div>{{ $n }}<div class="s">{{ $s }}; cuando paguen solo baja esta cifra</div></div><div class="v">{{ $pesos($v) }}</div></div>
            @endforeach
            <div class="cm-total">Total por cobrar<div class="v">{{ $pesos($c['total_c']) }}</div></div>
        </div>
    </div>

    {{-- ── 4 · Dónde está la plata ─────────────────────────────────────── --}}
    <div class="cm-sec">🏦 4 · Dónde está la plata al {{ $corteTx }} <span>— saldos, no lo que se movió en el mes</span></div>
    <form method="POST" action="{{ route('admin.informes.financiero.cierre.saldos') }}" class="cm-card">
        @csrf
        <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
        <table class="cm-tbl">
            <thead><tr><th>Cuenta</th><th class="n">Saldo según BryNex</th><th class="n">Saldo real</th><th class="n">Diferencia</th><th class="n">En el mes</th></tr></thead>
            <tbody>
            @foreach($c['bancos'] as $b)
                @php $etq = $tipoEtq[$b['tipo']] ?? $tipoEtq['cobro']; @endphp
                <tr>
                    <td><b>{{ $b['nombre'] }}</b> <span class="cm-etq" style="background:{{ $etq[1] }};color:{{ $etq[2] }}">{{ $etq[0] }}</span>
                        @if($b['tipo'] === 'personal' && $b['saldo_cuenta'] < 0)<div style="font-size:.64rem;color:#7c3aed">en negativo: va arriba como pendiente a la cuenta</div>@endif
                    </td>
                    <td class="n" style="{{ $b['saldo'] < 0 ? 'color:#dc2626' : '' }}">{{ $pesos($b['saldo']) }}</td>
                    <td class="n">
                        @if($esSuper && !$c['en_curso'])
                        <input class="cm-in" type="number" step="1" name="bancos[{{ $b['id'] }}]" value="{{ $b['real'] }}" placeholder="—">
                        @else {{ $b['real'] === null ? '—' : $pesos($b['real']) }} @endif
                    </td>
                    <td class="n" style="{{ $b['real'] !== null && $b['real'] != $b['saldo'] ? 'color:#dc2626;font-weight:700' : 'color:#94a3b8' }}">{{ $b['real'] === null ? '—' : $pesos($b['real'] - $b['saldo']) }}</td>
                    <td class="n" style="font-size:.66rem;color:#94a3b8">@if($b['entro'])entró {{ $pesos($b['entro']) }}<br>@endif @if($b['salio'])salió {{ $pesos($b['salio']) }}@endif @if(!$b['entro'] && !$b['salio'])sin movimiento @endif</td>
                </tr>
            @endforeach
            <tr style="background:#fafbfc"><td><b>Total bancos</b></td><td class="n" colspan="2"><b>{{ $pesos($c['total_bancos']) }}</b></td><td colspan="2"></td></tr>
            </tbody>
        </table>
        <table class="cm-tbl" style="border-top:1px solid #e2e8f0">
            <thead><tr><th>Efectivo · quién lo tiene</th><th class="n">Según BryNex</th><th class="n">Contado</th><th class="n">Diferencia</th><th></th></tr></thead>
            <tbody>
            @if($c['efectivo_contado'])
                @foreach($c['efectivo'] as $e)
                <tr>
                    <td><b>{{ $e['nombre'] }}</b></td>
                    <td class="n">{{ $pesos($e['saldo']) }}</td>
                    <td class="n">
                        @if($esSuper && !$c['en_curso'])
                        <input class="cm-in" type="number" step="1" name="efectivo[{{ $e['id'] }}]" value="{{ $e['real'] }}" placeholder="—">
                        @else {{ $e['real'] === null ? '—' : $pesos($e['real']) }} @endif
                    </td>
                    <td class="n" style="{{ $e['real'] !== null && $e['real'] != $e['saldo'] ? 'color:#dc2626;font-weight:700' : 'color:#94a3b8' }}">{{ $e['real'] === null ? '—' : $pesos($e['real'] - $e['saldo']) }}</td>
                    <td></td>
                </tr>
                @endforeach
            @else
                <tr><td colspan="5" style="font-size:.74rem;color:#9a3412;background:#fff7ed">
                    Ninguna caja tiene saldo inicial contado: se usa el efectivo que calcula el informe,
                    <b>{{ $pesos($c['efectivo_viejo']) }}</b>, que suma lo cobrado sin restar lo que cada persona entregó.
                    Con «Saldo inicial contado» en el cuadre de cada persona esto se reemplaza por lo contado.
                </td></tr>
            @endif
            <tr style="background:#fafbfc"><td><b>Total efectivo</b></td><td class="n" colspan="2"><b>{{ $pesos($c['total_efectivo']) }}</b></td><td colspan="2"></td></tr>
            </tbody>
        </table>
        @if($esSuper && !$c['en_curso'])
        <details id="cm-reales" style="padding:.6rem .9rem;border-top:1px solid #f1f5f9">
            <summary style="font-size:.74rem;color:#1e40af;cursor:pointer;font-weight:700">¿Ya anotaste los saldos reales? Guárdalos aquí</summary>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;margin-top:.5rem;flex-wrap:wrap">
                <span style="font-size:.7rem;color:#64748b">Escribe el saldo del extracto de cada cuenta y lo contado de cada persona al {{ $corteTx }}. Lo que dejes vacío se toma como el saldo según BryNex.
                    @if($c['saldos_at']) Últimos guardados: {{ $c['saldos_at']->format('d/m/Y H:i') }}.@endif</span>
                <button class="cm-btn" style="background:#1e40af;color:#fff">Guardar saldos reales</button>
            </div>
        </details>
        @endif
    </form>

    {{-- ── 5 · ¿Cuadra? ────────────────────────────────────────────────── --}}
    <div class="cm-sec">✅ 5 · ¿Cuadra?</div>
    <div class="cm-card" style="border-width:2px">
        <div class="cm-eq">
            <div><div class="l">Dónde está la plata</div><div class="n">{{ $pesos($c['total_d']) }}</div></div>
            <div class="op">=</div>
            <div><div class="l">Utilidad acumulada</div><div class="n">{{ $pesos($c['util_despues']) }}</div></div>
            <div class="op">+</div>
            <div><div class="l">Pasa al mes</div><div class="n">{{ $pesos($c['total_b']) }}</div></div>
            <div class="op">−</div>
            <div><div class="l">Por cobrar</div><div class="n">{{ $pesos($c['total_c']) }}</div></div>
        </div>
        @if($c['es_apertura'])
        <div class="cm-res" style="background:#eef2ff;color:#3730a3">
            Primer cierre: la utilidad acumulada al empezar ({{ $pesos($c['util_antes']) }}) es la que hace cuadrar este mes; queda como ajuste de apertura.
            Desde el mes siguiente, cualquier diferencia es algo nuevo que hay que buscar.
        </div>
        @elseif(abs($c['diferencia']) < 1)
        <div class="cm-res" style="background:#ecfdf5;color:#065f46">✅ Cuadra: diferencia $0.</div>
        @else
        <div class="cm-res" style="background:#fef2f2;color:#991b1b">
            ❌ Diferencia {{ $pesos($c['diferencia']) }}: {{ $c['diferencia'] > 0 ? 'hay más plata de la que se explica' : 'falta plata' }}.
            <span style="font-weight:500">Revisa consignaciones sin confirmar, efectivo sin entregar, gastos sin registrar o retiros del dueño sin anotar.</span>
        </div>
        @endif
        @if($c['faltan_fotos'])
        <div class="cm-res" style="background:#fff7ed;color:#9a3412;font-weight:500">Faltan las fotos de {{ implode(', ', $c['faltan_fotos']) }}: su utilidad no está sumada a la acumulada.</div>
        @endif
    </div>
</div>
