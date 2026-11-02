@extends('layouts.app')
@section('modulo', 'BryNex Consumo')
@section('contenido')

<div style="max-width:1400px;margin:0 auto;">

    {{-- Header del Dashboard --}}
    <div style="display:flex;justify-content:between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.5rem;font-weight:800;color:#0d2550;margin:0;">📊 Monitoreo de Consumo y Cobros a Aliados</h1>
            <p style="color:#64748b;font-size:0.83rem;margin:0.2rem 0 0 0;">Control de tramos de administración y consumos de WhatsApp API.</p>
        </div>
        
        {{-- Selector de Período --}}
        <form method="GET" action="{{ route('brynex.consumo.index') }}" style="display:flex;align-items:center;gap:0.5rem;background:#fff;padding:0.4rem 0.8rem;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
            <select name="mes" style="font-size:0.82rem;padding:0.3rem 0.5rem;border-radius:6px;border:1px solid #cbd5e1;outline:none;color:#334155;font-weight:500;">
                @foreach([1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'] as $mNum => $mNom)
                    <option value="{{ $mNum }}" {{ $mes == $mNum ? 'selected' : '' }}>{{ $mNom }}</option>
                @endforeach
            </select>
            <select name="anio" style="font-size:0.82rem;padding:0.3rem 0.5rem;border-radius:6px;border:1px solid #cbd5e1;outline:none;color:#334155;font-weight:500;">
                @for($y = now()->year - 2; $y <= now()->year + 1; $y++)
                    <option value="{{ $y }}" {{ $anio == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
            <button type="submit" style="background:#1e3a8a;color:#fff;border:none;padding:0.35rem 0.75rem;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#172554'" onmouseout="this.style.background='#1e3a8a'">
                Filtrar
            </button>
        </form>
    </div>

    {{-- KPIs agregados --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:2rem;">
        
        {{-- KPI Facturado / Estimado en el mes --}}
        <div style="background:linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);border-radius:16px;padding:1.5rem;color:#fff;box-shadow:0 10px 25px rgba(30,58,138,0.15);position:relative;overflow:hidden;">
            <div style="font-size:1.8rem;position:absolute;right:1.25rem;top:1.25rem;opacity:0.15;">💰</div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:#93c5fd;font-weight:700;margin-bottom:0.4rem;">Cobro del Período (Estimado/Real)</div>
            <div style="font-size:1.8rem;font-weight:800;letter-spacing:-0.02em;line-height:1.1;">
                $ {{ number_format($totalGeneralFacturado, 0, ',', '.') }}
            </div>
            <div style="font-size:0.72rem;color:rgba(255,255,255,0.6);margin-top:0.6rem;">
                Suma de todos los aliados para {{ [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'][$mes] }} {{ $anio }}.
            </div>
        </div>

        {{-- KPI Pendiente Histórico total --}}
        <div style="background:linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%);border-radius:16px;padding:1.5rem;color:#fff;box-shadow:0 10px 25px rgba(185,28,28,0.15);position:relative;overflow:hidden;">
            <div style="font-size:1.8rem;position:absolute;right:1.25rem;top:1.25rem;opacity:0.15;">🚨</div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:#fca5a5;font-weight:700;margin-bottom:0.4rem;">Cartera Total por Uso</div>
            <div style="font-size:1.8rem;font-weight:800;letter-spacing:-0.02em;line-height:1.1;">
                $ {{ number_format($totalGeneralPendiente, 0, ',', '.') }}
            </div>
            <div style="font-size:0.72rem;color:rgba(255,255,255,0.6);margin-top:0.6rem;">
                Pendiente total por cobrar incluyendo períodos históricos cerrados.
            </div>
        </div>

        {{-- Contabilidad enlace rápido --}}
        <div style="background:#fff;border-radius:16px;padding:1.5rem;box-shadow:0 4px 12px rgba(0,0,0,0.03);border:1px solid #e2e8f0;display:flex;flex-direction:column;justify-content:between;">
            <div>
                <div style="font-weight:700;font-size:0.9rem;color:#1e293b;margin-bottom:0.25rem;">📊 Resumen Financiero Histórico</div>
                <p style="color:#64748b;font-size:0.75rem;margin:0 0 1rem 0;line-height:1.4;">Accede al desglose de ingresos totales recibidos por Brynex, recaudos por banco, gráficos mensuales y KPIs consolidados.</p>
            </div>
            <a href="{{ route('brynex.consumo.contabilidad') }}" style="display:inline-block;width:100%;text-align:center;background:#f1f5f9;color:#334155;text-decoration:none;font-weight:700;font-size:0.8rem;padding:0.5rem;border-radius:8px;transition:all 0.15s;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                Ver Informe Financiero de Brynex →
            </a>
        </div>
    </div>

    {{-- Tabla principal de Aliados --}}
    <div style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.04);border:1px solid #e2e8f0;overflow:hidden;margin-bottom:2rem;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
            <h3 style="font-size:0.9rem;font-weight:800;color:#0d2550;margin:0;">Consumos por Aliado</h3>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;text-align:left;font-size:0.82rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-weight:700;text-transform:uppercase;font-size:0.7rem;letter-spacing:0.04em;">
                        <th style="padding:1rem 1.5rem;width:250px;">Aliado</th>
                        <th style="padding:1rem 0.5rem;text-align:center;" title="Administración + afiliaciones del mes − traslados: el Total Activos del consolidado mensual">Clientes activos</th>
                        <th style="padding:1rem 0.5rem;text-align:center;">Admon (Contratos)</th>
                        <th style="padding:1rem 0.5rem;text-align:center;">Afiliaciones</th>
                        <th style="padding:1rem 0.5rem;text-align:center;">WA Plantillas</th>
                        <th style="padding:1rem 0.5rem;text-align:center;">WA Conversaciones</th>
                        <th style="padding:1rem 1rem;text-align:right;">Total Período</th>
                        <th style="padding:1rem 1rem;text-align:center;">Estado</th>
                        <th style="padding:1rem 1rem;text-align:right;">Saldo Anterior</th>
                        <th style="padding:1rem 1.5rem;text-align:center;width:300px;">Acciones</th>
                    </tr>
                </thead>
                <tbody style="color:#334155;">
                    @forelse($resumenes as $res)
                        @php
                            $admon = $res['modulos']->firstWhere('modulo_id', 1);
                            $afil = $res['modulos']->firstWhere('modulo_id', 2);
                            $waPlan = $res['modulos']->firstWhere('modulo_id', 3);
                            $waConv = $res['modulos']->firstWhere('modulo_id', 4);
                        @endphp
                        <tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                            <td style="padding:1rem 1.5rem;">
                                <div style="font-weight:700;color:#0f172a;font-size:0.85rem;">{{ $res['aliado']->nombre }}</div>
                                <div style="color:#94a3b8;font-size:0.72rem;">NIT: {{ $res['aliado']->nit }}</div>
                            </td>
                            <td style="padding:1rem 0.5rem;text-align:center;font-weight:800;color:#0f172a;">
                                {{ number_format($res['conteos']['clientes_activos'], 0, ',', '.') }}
                            </td>
                            @foreach([[$admon, 'admon', '#1e3a8a'], [$afil, 'afiliaciones', '#1e3a8a'], [$waPlan, 'wa_plantillas', '#0d9488'], [$waConv, 'wa_conversaciones', '#0d9488']] as [$linea, $clave, $color])
                                <td style="padding:1rem 0.5rem;text-align:center;">
                                    @if($linea)
                                        <div style="font-weight:700;color:{{ $color }};">{{ number_format($linea['cant_unidades'], 0, ',', '.') }}</div>
                                        <div style="font-size:0.68rem;color:#64748b;">${{ number_format($linea['subtotal'], 0, ',', '.') }}</div>
                                    @else
                                        {{-- Módulo sin contratar: se muestra la cantidad, sin cobro --}}
                                        <div style="font-weight:600;color:#94a3b8;">{{ number_format($res['conteos'][$clave], 0, ',', '.') }}</div>
                                        <div style="font-size:0.68rem;color:#cbd5e1;">no se cobra</div>
                                    @endif
                                </td>
                            @endforeach
                            <td style="padding:1rem 1rem;text-align:right;white-space:nowrap;font-weight:800;font-size:0.85rem;color:#0f172a;">
                                $ {{ number_format($res['total'], 0, ',', '.') }}
                            </td>
                            <td style="padding:1rem 1rem;text-align:center;">
                                @if($res['estado'] === 'abierto')
                                    <span style="background:#eff6ff;color:#1d4ed8;padding:0.25rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;border:1px solid #bfdbfe;">
                                        Estimado
                                    </span>
                                @elseif($res['estado'] === 'pendiente')
                                    <span style="background:#fef2f2;color:#b91c1c;padding:0.25rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;border:1px solid #fca5a5;">
                                        Por Pagar
                                    </span>
                                @elseif($res['estado'] === 'parcial')
                                    <span style="background:#fffbeb;color:#d97706;padding:0.25rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;border:1px solid #fde68a;">
                                        Pago Parcial
                                    </span>
                                @elseif($res['estado'] === 'pagado')
                                    <span style="background:#f0fdf4;color:#16a34a;padding:0.25rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;border:1px solid #bbf7d0;">
                                        Pagado
                                    </span>
                                @endif
                            </td>
                            <td style="padding:1rem 1rem;text-align:right;white-space:nowrap;font-weight:500;color:#ef4444;">
                                @if($res['saldo_historico'] > 0)
                                    $ {{ number_format($res['saldo_historico'], 0, ',', '.') }}
                                @else
                                    <span style="color:#94a3b8;">$ 0</span>
                                @endif
                            </td>
                            <td style="padding:1rem 1.5rem;text-align:center;display:flex;gap:0.35rem;justify-content:center;">
                                <a href="{{ route('brynex.consumo.show', [$res['aliado']->id, $mes, $anio]) }}" style="background:#3b82f6;color:#fff;text-decoration:none;padding:0.35rem 0.65rem;border-radius:6px;font-weight:600;font-size:0.75rem;transition:all 0.15s;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                                    Detalle
                                </a>
                                <a href="{{ route('brynex.consumo.modulos', $res['aliado']->id) }}" style="background:#f1f5f9;color:#475569;text-decoration:none;padding:0.35rem 0.65rem;border-radius:6px;font-weight:600;font-size:0.75rem;border:1px solid #cbd5e1;transition:all 0.15s;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                                    Tarifas
                                </a>
                                <button type="button" onclick="abrirCuentaCobro({{ $res['aliado']->id }})" style="background:#0f766e;color:#fff;border:none;padding:0.35rem 0.65rem;border-radius:6px;font-weight:600;font-size:0.75rem;cursor:pointer;white-space:nowrap;">
                                    Cuenta de cobro
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" style="padding:3rem;text-align:center;color:#64748b;">
                                No hay aliados activos registrados en el sistema.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Cuenta de cobro: renglones prellenados con lo calculado; se pueden corregir o agregar otros --}}
<div id="modalCuentaCobro" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:1000;align-items:center;justify-content:center;padding:16px;">
    <form id="formCuentaCobro" method="POST" target="_blank" style="background:#fff;border-radius:14px;width:100%;max-width:760px;max-height:90vh;overflow-y:auto;padding:1.5rem;box-shadow:0 20px 50px rgba(0,0,0,0.25);">
        @csrf
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h3 style="margin:0;font-size:1rem;font-weight:800;color:#0d2550;">Cuenta de cobro · <span id="ccAliado"></span></h3>
            <button type="button" onclick="cerrarCuentaCobro()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;">×</button>
        </div>
        <label style="font-size:0.75rem;font-weight:700;color:#475569;">Fecha de la cuenta</label>
        <input type="date" name="fecha" id="ccFecha" required style="display:block;margin:0.25rem 0 1rem;padding:0.35rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;">

        <div style="font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.35rem;">Por concepto de</div>
        <div id="ccConceptos"></div>
        <button type="button" onclick="agregarConcepto('', '')" style="margin-top:0.4rem;background:#f1f5f9;border:1px solid #cbd5e1;color:#334155;padding:0.3rem 0.65rem;border-radius:6px;font-size:0.75rem;font-weight:600;cursor:pointer;">+ Agregar concepto</button>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1.25rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
            <div style="font-weight:800;color:#0f172a;">Total: <span id="ccTotal">$ 0</span></div>
            <button type="submit" style="background:#1e3a8a;color:#fff;border:none;padding:0.5rem 1rem;border-radius:8px;font-weight:700;font-size:0.82rem;cursor:pointer;">Descargar PDF</button>
        </div>
    </form>
</div>

@php
    $cuentasCobro = collect($resumenes)->mapWithKeys(fn ($r) => [$r['aliado']->id => [
        'nombre'    => $r['aliado']->nombre,
        'url'       => route('brynex.consumo.cuenta_cobro', [$r['aliado']->id, $mes, $anio]),
        'conceptos' => $r['conceptos_cobro'],
    ]]);
    $ccFechaDefecto = \Carbon\Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString();
@endphp
<script>
    const cuentasCobro = @json($cuentasCobro);
    const ccFechaDefecto = @json($ccFechaDefecto);
    let ccIndice = 0;

    function abrirCuentaCobro(id) {
        const cc = cuentasCobro[id];
        document.getElementById('ccAliado').textContent = cc.nombre;
        document.getElementById('formCuentaCobro').action = cc.url;
        document.getElementById('ccFecha').value = ccFechaDefecto;
        document.getElementById('ccConceptos').innerHTML = '';
        ccIndice = 0;
        cc.conceptos.forEach(c => agregarConcepto(c.texto, Math.round(c.valor)));
        if (!cc.conceptos.length) agregarConcepto('', '');
        recalcularCuentaCobro();
        document.getElementById('modalCuentaCobro').style.display = 'flex';
    }

    function cerrarCuentaCobro() {
        document.getElementById('modalCuentaCobro').style.display = 'none';
    }

    function agregarConcepto(texto, valor) {
        const i = ccIndice++;
        const fila = document.createElement('div');
        fila.style.cssText = 'display:flex;gap:0.4rem;margin-bottom:0.4rem;align-items:center;';
        fila.innerHTML = `
            <input type="text" name="conceptos[${i}][texto]" placeholder="Concepto" style="flex:1;min-width:0;padding:0.35rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.8rem;">
            <input type="number" name="conceptos[${i}][valor]" placeholder="Valor" step="1" oninput="recalcularCuentaCobro()" style="width:120px;padding:0.35rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.8rem;text-align:right;">
            <button type="button" onclick="this.parentElement.remove();recalcularCuentaCobro()" title="Quitar" style="background:none;border:none;color:#ef4444;font-size:1.1rem;cursor:pointer;">×</button>`;
        fila.querySelector('input[type=text]').value = texto;
        fila.querySelector('input[type=number]').value = valor;
        document.getElementById('ccConceptos').appendChild(fila);
        recalcularCuentaCobro();
    }

    function recalcularCuentaCobro() {
        let total = 0;
        document.querySelectorAll('#ccConceptos input[type=number]').forEach(el => total += Number(el.value || 0));
        document.getElementById('ccTotal').textContent = '$ ' + new Intl.NumberFormat('es-CO').format(total);
    }

    document.getElementById('modalCuentaCobro').addEventListener('click', e => {
        if (e.target.id === 'modalCuentaCobro') cerrarCuentaCobro();
    });
</script>

@endsection
