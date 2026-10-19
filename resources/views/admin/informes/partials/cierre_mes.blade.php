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
    $tipoEtq = ['cobro' => ['cobro', '#dbeafe', '#1e40af'], 'negocio' => ['negocio', '#e0f2fe', '#075985'], 'razon_social' => ['razón social', '#dcfce7', '#166534'], 'personal' => ['personal', '#ede9fe', '#5b21b6']];
    $origenEtq = [
        'verificado'    => ['verificado', '#dcfce7', '#166534', 'el saldo real anotado al cierre del mes anterior'],
        'manual'        => ['saldo inicial', '#dcfce7', '#166534', 'saldo inicial anotado a mano este mes'],
        'arrastre'      => ['viene del mes anterior', '#e0f2fe', '#075985', 'cuenta de razón social: sigue con su saldo hasta que se paguen las incapacidades'],
        'personal'      => ['0 cada mes', '#ede9fe', '#5b21b6', 'cuenta personal: solo se lleva lo del negocio en el mes'],
        'sin_verificar' => ['0 · sin verificar', '#fef3c7', '#92400e', 'no se anotó el saldo real del cierre anterior: el mes arranca en 0'],
    ];
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
            Queda en la empresa: <b>{{ $pesos($c['a']['utilidad'] - $c['a']['retiros']) }}</b>
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
    <div class="cm-sec">🏦 4 · Dónde está la plata al {{ $corteTx }} <span>— cada cuenta arranca con lo verificado al cierre anterior y suma solo lo del mes</span></div>
    @php $editable = $esSuper && !$c['en_curso']; @endphp
    <form method="POST" action="{{ route('admin.informes.financiero.cierre.saldos') }}" class="cm-card">
        @csrf
        <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
        @foreach([['bancos', 'Cuenta', $c['bancos']], ['efectivo', 'Efectivo · quién lo tiene', $c['efectivo']]] as [$grupo, $titulo, $filas])
        <table class="cm-tbl" @if($grupo === 'efectivo') style="border-top:1px solid #e2e8f0" @endif>
            <thead><tr><th>{{ $titulo }}</th><th class="n">Arrancó</th><th class="n">Entró</th><th class="n">Salió</th><th class="n">Según BryNex</th><th class="n">{{ $grupo === 'bancos' ? 'Saldo real' : 'Contado' }}</th><th class="n">Diferencia</th></tr></thead>
            <tbody>
            @forelse($filas as $f)
                @php
                    $o = $grupo === 'bancos' ? ($origenEtq[$f['origen']] ?? $origenEtq['sin_verificar']) : ($f['verificado'] ? $origenEtq['verificado'] : $origenEtq['sin_verificar']);
                    $etq = $grupo === 'bancos' ? ($tipoEtq[$f['tipo']] ?? $tipoEtq['negocio']) : null;
                @endphp
                <tr>
                    <td><b>{{ $f['nombre'] }}</b>
                        @if($etq)<span class="cm-etq" style="background:{{ $etq[1] }};color:{{ $etq[2] }}">{{ $etq[0] }}</span>@endif
                        @if($grupo === 'bancos' && $f['tipo'] === 'personal' && $f['saldo_cuenta'] < 0)<div style="font-size:.64rem;color:#7c3aed">en negativo: va arriba como pendiente a la cuenta</div>@endif
                    </td>
                    <td class="n">{{ $pesos($f['inicial']) }}<div><span class="cm-etq" title="{{ $o[3] }}" style="background:{{ $o[1] }};color:{{ $o[2] }}">{{ $o[0] }}</span></div></td>
                    <td class="n" style="color:#15803d">{{ $f['entro'] ? $pesos($f['entro']) : '—' }}</td>
                    <td class="n" style="color:#b91c1c">{{ $f['salio'] ? $pesos($f['salio']) : '—' }}</td>
                    <td class="n" style="font-weight:700;{{ $f['saldo'] < 0 ? 'color:#dc2626' : '' }}">{{ $pesos($f['saldo']) }}</td>
                    <td class="n">
                        @if($editable)
                        <input class="cm-in" type="number" step="1" name="{{ $grupo }}[{{ $f['id'] }}]" value="{{ $f['real'] }}" placeholder="—">
                        @else {{ $f['real'] === null ? '—' : $pesos($f['real']) }} @endif
                    </td>
                    <td class="n" style="{{ $f['diferencia'] ? 'color:#dc2626;font-weight:700' : 'color:#94a3b8' }}">{{ $f['diferencia'] === null ? '—' : $pesos($f['diferencia']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="font-size:.74rem;color:#94a3b8">Nadie manejó efectivo este mes.</td></tr>
            @endforelse
            <tr style="background:#fafbfc"><td colspan="4"><b>Total {{ $grupo === 'bancos' ? 'bancos' : 'efectivo' }}</b> <span style="font-size:.66rem;color:#94a3b8">(lo real donde se anotó)</span></td>
                <td class="n" colspan="2"><b>{{ $pesos($grupo === 'bancos' ? $c['total_bancos'] : $c['total_efectivo']) }}</b></td><td></td></tr>
            </tbody>
        </table>
        @endforeach
        @if($editable)
        <details id="cm-reales" style="padding:.6rem .9rem;border-top:1px solid #f1f5f9" @if(!$c['hay_reales']) open @endif>
            <summary style="font-size:.74rem;color:#1e40af;cursor:pointer;font-weight:700">Guardar los saldos reales del cierre</summary>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;margin-top:.5rem;flex-wrap:wrap">
                <span style="font-size:.7rem;color:#64748b">Saldo del extracto de cada cuenta y lo contado de cada persona al {{ $corteTx }}. Con esto arranca {{ $sigNom }}; lo que dejes vacío arranca en 0 «sin verificar» (las de razón social siguen con su saldo).
                    @if($c['saldos_at']) Últimos guardados: {{ $c['saldos_at']->format('d/m/Y H:i') }}.@endif</span>
                <button class="cm-btn" style="background:#1e40af;color:#fff">Guardar saldos reales</button>
            </div>
        </details>
        @endif
    </form>

    {{-- ── 5 · ¿Cuadra? ────────────────────────────────────────────────── --}}
    <div class="cm-sec">✅ 5 · ¿Cuadra? <span>— lo real contra BryNex, solo de este mes</span></div>
    <div class="cm-card" style="border-width:2px">
        @if($c['verificadas'] === 0)
        <div class="cm-res" style="background:#fff7ed;color:#9a3412">
            {{ $c['en_curso'] ? 'El mes está en curso: se revisa al cerrar, anotando los saldos reales.' : 'Anota los saldos reales de las cuentas y el efectivo contado para saber si el mes cuadra.' }}
        </div>
        @elseif($c['diferencia'] == 0)
        <div class="cm-res" style="background:#ecfdf5;color:#065f46">
            ✅ Cuadra: en las {{ $c['verificadas'] }} cuentas y cajas verificadas lo real es igual a lo registrado.
            @if($c['por_verificar']) <span style="font-weight:500">Faltan {{ $c['por_verificar'] }} por verificar.</span>@endif
        </div>
        @else
        <div class="cm-res" style="background:#fef2f2;color:#991b1b">
            ❌ Diferencia del mes {{ $pesos($c['diferencia']) }}: {{ $c['diferencia'] > 0 ? 'hay más plata de la que se registró' : 'falta plata, o salió algo que no se registró' }}.
            <span style="font-weight:500">Mira la columna «Diferencia»: es lo que no se registró este mes en esa cuenta o con esa persona — consignaciones en otra cuenta, cobros del banco, gastos o retiros sin anotar, o algo en tránsito al cierre.
            @if($c['por_verificar']) Faltan {{ $c['por_verificar'] }} por verificar.@endif</span>
        </div>
        @endif
    </div>
</div>
