@extends('layouts.app')
@section('modulo', 'Reporte Consolidado Mensual')
@section('contenido')

<div style="max-width:1200px;margin:0 auto; font-family: 'Outfit', 'Inter', sans-serif;">

    {{-- Botón volver y título --}}
    <div style="margin-bottom:2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <a href="{{ route('admin.informes.hub') }}" class="btn-back">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Volver al Centro de Informes
            </a>
            <h1 style="font-size:1.65rem;font-weight:800;color:#0f172a;margin-top:0.5rem; letter-spacing: -0.02em;">Reporte de Ingresos y Retiros</h1>
            <p style="color:#64748b;font-size:0.88rem;margin-top:0.15rem;">Análisis histórico de nuevas administraciones y afiliaciones por mes · Sin repetir clientes de meses anteriores</p>
        </div>
        <div style="background: rgba(13, 148, 136, 0.08); border: 1px solid rgba(13, 148, 136, 0.2); border-radius: 12px; padding: 0.6rem 1rem; text-align: right;">
            <span style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: #0d9488; display: block; letter-spacing: 0.05em;">Mes en Curso</span>
            <span style="font-size: 1rem; font-weight: 800; color: #0f766e;">{{ $kpisActual['label'] }}</span>
        </div>
    </div>

    {{-- Grid de KPIs principales (Mes Actual) --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.25rem;margin-bottom:2.5rem;">

        <!-- Tarjeta Admon Vigentes (Base de Cobro) -->
        <div class="card-kpi" style="padding:1.5rem; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; min-height: 140px;">
            <div style="position: absolute; right: -10px; top: -10px; font-size: 5.5rem; opacity: 0.04; pointer-events: none; font-weight: 900;">📋</div>
            <div>
                <span style="font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Admon Vigentes</span>
                <div style="font-size:2.4rem;font-weight:900;color:#0d9488;margin-top:0.25rem;line-height:1.1; cursor:pointer;" onclick="abrirDetalle({{ $kpisActual['mes'] }}, {{ $kpisActual['anio'] }}, 'admon_vigentes')">
                    {{ number_format($kpisActual['admon_vigentes'], 0, ',', '.') }}
                </div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">Contratos activos en el mes</div>
            </div>
            <div style="margin-top: 1rem; display: flex; align-items: center; gap: 0.5rem; border-top: 1px solid #f1f5f9; padding-top: 0.75rem;">
                @if($kpisActual['variacion'] > 0)
                    <span class="trend-badge trend-up">▲ +{{ $kpisActual['variacion'] }}</span>
                @elseif($kpisActual['variacion'] < 0)
                    <span class="trend-badge trend-down">▼ {{ $kpisActual['variacion'] }}</span>
                @else
                    <span class="trend-badge trend-neutral">■ 0</span>
                @endif
                <span style="font-size:0.75rem;color:#94a3b8;">variación neta vs anterior</span>
            </div>
        </div>

        <!-- Tarjeta Afiliaciones del Mes -->
        <div class="card-kpi" style="padding:1.5rem; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; min-height: 140px;">
            <div style="position: absolute; right: -10px; top: -10px; font-size: 5.5rem; opacity: 0.04; pointer-events: none; font-weight: 900;">👥</div>
            <div>
                <span style="font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Afiliaciones del Mes</span>
                <div style="font-size:2.4rem;font-weight:900;color:#6366f1;margin-top:0.25rem;line-height:1.1; cursor:pointer;" onclick="abrirDetalle({{ $kpisActual['mes'] }}, {{ $kpisActual['anio'] }}, 'afiliaciones')">
                    {{ number_format($kpisActual['afil_por_fecha'], 0, ',', '.') }}
                </div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">Nuevas afiliaciones por fecha de ingreso</div>
            </div>
            <div style="margin-top: 1rem; display: flex; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 0.75rem;">
                <div style="font-size:0.75rem;color:#475569;" title="Cédulas sin un contrato retirado este mes ni el anterior">
                    Nuevas: <strong style="color:#4f46e5;">{{ number_format($kpisActual['afil_nuevas'], 0, ',', '.') }}</strong>
                </div>
                <div style="font-size:0.75rem;color:#475569;" title="Tenían otro contrato retirado este mes o el anterior, de cualquier razón social">
                    Reingresos: <strong style="color:#0891b2;">{{ number_format($kpisActual['afil_reingresos'], 0, ',', '.') }}</strong>
                </div>
            </div>
        </div>

        <!-- Tarjeta Retiros del Mes (Con sub-info Reales/Informativas) -->
        <div class="card-kpi" style="padding:1.5rem; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; min-height: 140px;">
            <div style="position: absolute; right: -10px; top: -10px; font-size: 5.5rem; opacity: 0.04; pointer-events: none; font-weight: 900;">🚪</div>
            <div>
                <span style="font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Retiros del Mes</span>
                <div style="font-size:2.4rem;font-weight:900;color:#ef4444;margin-top:0.25rem;line-height:1.1;">
                    {{ number_format($kpisActual['total_retiros'], 0, ',', '.') }}
                </div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">Total contratos retirados este período</div>
            </div>
            <div style="margin-top: 1rem; display: flex; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 0.75rem;">
                <div style="font-size:0.75rem;color:#475569; cursor:pointer;" onclick="abrirDetalle({{ $kpisActual['mes'] }}, {{ $kpisActual['anio'] }}, 'retiros_reales')">
                    Reales: <strong style="color:#b45309;">{{ $kpisActual['retiros_reales'] }}</strong>
                </div>
                <div style="font-size:0.75rem;color:#475569; cursor:pointer;" onclick="abrirDetalle({{ $kpisActual['mes'] }}, {{ $kpisActual['anio'] }}, 'retiros_informativos')">
                    Informativos: <strong style="color:#78350f;">{{ $kpisActual['retiros_inform'] }}</strong>
                </div>
            </div>
            <div style="margin-top: 0.5rem; display: flex; justify-content: space-between; border-top: 1px dashed #f1f5f9; padding-top: 0.5rem;">
                <div style="font-size:0.75rem;color:#475569;" title="Se les abrió contrato nuevo en el mismo mes del retiro, o la cédula tiene contrato vigente hoy">
                    Renovados: <strong style="color:#0891b2;">{{ $kpisActual['retiros_renovados'] }}</strong>
                </div>
                <div style="font-size:0.75rem;color:#475569;" title="Se fueron y no volvieron">
                    Definitivos: <strong style="color:#dc2626;">{{ $kpisActual['retiros_definitivos'] }}</strong>
                </div>
            </div>
        </div>

    </div>

    {{-- Nota explicativa --}}
    <div style="background: linear-gradient(135deg, rgba(13,148,136,0.06), rgba(99,102,241,0.06)); border: 1px solid rgba(13,148,136,0.2); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 2rem; display: flex; gap: 0.75rem; align-items: flex-start;">
        <span style="font-size: 1.2rem; flex-shrink: 0;">💡</span>
        <div>
            <p style="margin:0; font-size: 0.82rem; color: #0f766e; font-weight: 700;">¿Cómo se interpretan los datos del mes?</p>
            <p style="margin:0.25rem 0 0; font-size: 0.78rem; color: #475569; line-height: 1.5;">
                <strong>Admon Vigentes</strong>: Contratos activos continuos durante el mes (incluye contratos con retiro y facturación regular). 
                <strong>Afiliaciones del Mes</strong>: Contratos nuevos ingresados en el mes.
                <strong>Total Activos</strong>: Suma de Admon Vigentes + Afiliaciones del Mes.
                <strong>Variación</strong>: Diferencia de Total Activos de este mes comparado con el mes anterior.
            </p>
        </div>
    </div>

    {{-- Sección Gráfico --}}
    <div style="margin-bottom:2.5rem;">
        <div class="chart-container">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin-bottom:1rem; display: flex; align-items: center; gap: 0.5rem;">
                <span style="display: inline-block; width: 8px; height: 8px; background-color: #0d9488; border-radius: 50%;"></span>
                Tendencia de los Últimos 6 Meses
            </h3>
            <div style="position: relative; height: 320px; width: 100%;">
                <canvas id="consolidadoChart"></canvas>
            </div>
        </div>
    </div>

    {{-- Tabla de Datos Consolidados Unificada --}}
    <div style="background: #fff; border-radius: 16px; border: 1px solid #f1f5f9; padding: 1.5rem; box-shadow: 0 4px 30px rgba(0, 0, 0, 0.02); margin-bottom: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">📋 Resumen Consolidado Mensual</h3>
            <span style="font-size: 0.68rem; color: #0d9488; background: rgba(13,148,136,0.08); border: 1px solid rgba(13,148,136,0.2); border-radius: 6px; padding: 0.25rem 0.5rem; font-weight: 700;">Datos por período mensual</span>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-premium">
                <thead>
                    <tr>
                        <th>Mes / Año</th>
                        <th style="text-align: right;">Admon (Vigentes)</th>
                        <th style="text-align: right;">Afil. Fecha Ingreso</th>
                        <th style="text-align: right;">Retiros Reales</th>
                        <th style="text-align: right;">Retiros Informativos</th>
                        <th style="text-align: right; background-color: rgba(13, 148, 136, 0.04); color: #0d9488; font-weight: 800;">Total Activos</th>
                        <th style="text-align: center;">Variación</th>
                        <th style="text-align: right;">WA (Plan. / Rec.)</th>
                        <th style="text-align: right;">🤖 Asistente IA</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mesesFinal as $mes)
                    <tr>
                        <td style="font-weight: 600;">{{ $mes['label'] }}</td>
                        <td style="text-align: right; font-weight: 700; color: #0d9488;" class="clickable-cell" onclick="abrirDetalle({{ $mes['mes'] }}, {{ $mes['anio'] }}, 'admon_vigentes')">
                            {{ number_format($mes['admon_vigentes'], 0, ',', '.') }}
                        </td>
                        <td style="text-align: right; font-weight: 600; color: #6366f1;" class="clickable-cell" onclick="abrirDetalle({{ $mes['mes'] }}, {{ $mes['anio'] }}, 'afiliaciones')">
                            {{ number_format($mes['afil_por_fecha'], 0, ',', '.') }}
                            <div style="font-size: 0.68rem; font-weight: 500; color: #94a3b8; margin-top: 0.15rem; white-space: nowrap;">
                                {{ number_format($mes['afil_nuevas'], 0, ',', '.') }} nuevas ·
                                <span style="color: #0891b2;">{{ number_format($mes['afil_reingresos'], 0, ',', '.') }} reing.</span>
                            </div>
                        </td>
                        <td style="text-align: right; color: #d97706;" class="clickable-cell" onclick="abrirDetalle({{ $mes['mes'] }}, {{ $mes['anio'] }}, 'retiros_reales')">
                            {{ number_format($mes['retiros_reales'], 0, ',', '.') }}
                            <div style="font-size: 0.68rem; font-weight: 500; color: #94a3b8; margin-top: 0.15rem; white-space: nowrap;">
                                <span style="color: #0891b2;">{{ number_format($mes['retiros_reales_renov'], 0, ',', '.') }}</span> renovados
                            </div>
                        </td>
                        <td style="text-align: right; color: #78350f;" class="clickable-cell" onclick="abrirDetalle({{ $mes['mes'] }}, {{ $mes['anio'] }}, 'retiros_informativos')">
                            {{ number_format($mes['retiros_inform'], 0, ',', '.') }}
                            <div style="font-size: 0.68rem; font-weight: 500; color: #94a3b8; margin-top: 0.15rem; white-space: nowrap;">
                                <span style="color: #0891b2;">{{ number_format($mes['retiros_inform_renov'], 0, ',', '.') }}</span> renovados
                            </div>
                        </td>
                        <td style="text-align: right; font-weight: 800; color: #0d9488; background-color: rgba(13, 148, 136, 0.04); font-size: 0.95rem;">
                            {{ number_format($mes['total_activos'], 0, ',', '.') }}
                        </td>
                        <td style="text-align: center;">
                            @if($mes['variacion'] > 0)
                                <span class="trend-badge trend-up">▲ +{{ $mes['variacion'] }}</span>
                            @elseif($mes['variacion'] < 0)
                                <span class="trend-badge trend-down">▼ {{ $mes['variacion'] }}</span>
                            @else
                                <span class="trend-badge trend-neutral">■ 0</span>
                            @endif
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #0f766e;" class="clickable-cell" onclick="abrirModalWa({{ $mes['mes'] }}, {{ $mes['anio'] }})">
                            <span style="color: #059669;">{{ number_format($mes['wa_enviados'], 0, ',', '.') }}</span>
                            <span style="color: #64748b; font-weight: 500;">/</span>
                            <span style="color: #7c3aed;">{{ number_format($mes['respuestas_clientes'], 0, ',', '.') }}</span>
                        </td>
                        <td style="text-align: right;">
                            <span style="font-weight: 700; color: #0f766e;">${{ number_format($mes['ia_valor_mensual'], 0, ',', '.') }}</span>
                            <span style="color: #94a3b8; font-weight: 500; font-size: 0.78rem;">({{ number_format($mes['ia_consultas'], 0, ',', '.') }} consultas)</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p style="font-size: 0.72rem; color: #94a3b8; margin-top: 1rem; line-height: 1.5;">
            * <strong>Admon (Vigentes)</strong>: Contratos activos continuos durante el mes (incluye contratos con retiro y facturación regular). 
            * <strong>Afil. Fecha Ingreso</strong>: Nuevos contratos ingresados en el mes, discriminando <strong>nuevas</strong> y <strong>reingresos</strong>: es reingreso la cédula que tenía otro contrato retirado ese mes o el anterior, de cualquier razón social (no cuentan los contratos creados por error y retirados). Al abrir el detalle se ve cuál es cuál.
            * <strong>renovados</strong> (bajo Retiros Reales e Informativos): De esos retiros, cuántos volvieron — se les abrió otro contrato con fecha de ingreso en el mismo mes del retiro, o esa cédula tiene hoy un contrato vigente con el aliado. Es una parte del número de arriba, no un retiro adicional.
            * <strong>WA (Plan. / Rec.)</strong>: Relación de WhatsApps del mes (Enviados en plantilla masiva / Recibidos iniciados por el cliente tras 24h de inactividad). Los enviados cuentan solo los mensajes que sí salieron: no incluyen los destinatarios cuyo envío falló ni los omitidos por envío repetido.
            * <strong>Total Activos</strong>: Suma de Admon (Vigentes) y Afil. Fecha Ingreso.
            * <strong>Variación</strong>: Diferencia del Total Activos de este mes comparado con el mes anterior.
            * <strong>🤖 Asistente IA</strong>: Valor calculado con la tarifa configurada en BryNex para el módulo (editable en Configuración IA → activación por aliado) según las consultas reales del mes.
        </p>
    </div>

</div>

<style>
    .card-kpi {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(10px);
        border-radius: 16px;
        border: 1px solid rgba(255, 255, 255, 0.4);
        box-shadow: 0 4px 30px rgba(0, 0, 0, 0.03);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .card-kpi:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 45px rgba(13, 148, 136, 0.08);
        border-color: rgba(13, 148, 136, 0.25);
    }
    .btn-back {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #64748b;
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        transition: all 0.2s;
    }
    .btn-back:hover {
        color: #0d9488;
        transform: translateX(-2px);
    }
    .trend-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.7rem;
        font-weight: 800;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        line-height: 1;
    }
    .trend-up   { background-color: #d1fae5; color: #065f46; }
    .trend-down { background-color: #fee2e2; color: #991b1b; }
    .trend-neutral { background-color: #f3f4f6; color: #475569; }
    .table-premium {
        width: 100%;
        border-collapse: collapse;
        border-radius: 12px;
        overflow: hidden;
    }
    .table-premium th {
        background-color: #f8fafc;
        color: #64748b;
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 0.75rem 1rem;
        text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    .table-premium td {
        padding: 0.85rem 1rem;
        color: #334155;
        font-size: 0.82rem;
        border-bottom: 1px solid #f1f5f9;
        background-color: #fff;
        transition: background-color 0.15s;
    }
    .table-premium tr:last-child td { border-bottom: none; }
    .table-premium tr:hover td { background-color: #f8fafc; }
    .chart-container {
        background: #fff;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 4px 30px rgba(0, 0, 0, 0.02);
        border: 1px solid #f1f5f9;
        transition: box-shadow 0.3s;
    }
    .chart-container:hover {
        box-shadow: 0 8px 35px rgba(0, 0, 0, 0.04);
    }
</style>

{{-- Chart.js CDN --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Invertimos para orden cronológico: mes más antiguo a la izquierda
        const datos = @json($mesesFinal).reverse();

        const labels            = datos.map(d => d.label);
        const dataAdmonVigentes = datos.map(d => d.admon_vigentes);
        const dataAfilFecha     = datos.map(d => d.afil_por_fecha);
        const dataTotalActivos  = datos.map(d => d.total_activos);

        const ctx = document.getElementById('consolidadoChart').getContext('2d');

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Admon Vigentes',
                        data: dataAdmonVigentes,
                        backgroundColor: 'rgba(13, 148, 136, 0.85)',
                        borderColor: '#0d9488',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.55,
                        categoryPercentage: 0.6,
                    },
                    {
                        label: 'Afiliaciones del Mes',
                        data: dataAfilFecha,
                        backgroundColor: 'rgba(99, 102, 241, 0.85)',
                        borderColor: '#6366f1',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.55,
                        categoryPercentage: 0.6,
                    },
                    {
                        label: 'Total Activos',
                        data: dataTotalActivos,
                        type: 'line',
                        borderColor: '#0d9488',
                        borderWidth: 3,
                        pointBackgroundColor: '#0d9488',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        tension: 0.35,
                        fill: false,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            font: { size: 11, weight: 'bold', family: "'Outfit', 'Inter', sans-serif" },
                            color: '#475569'
                        }
                    },
                    tooltip: {
                        padding: 12,
                        cornerRadius: 10,
                        backgroundColor: '#0f172a',
                        titleFont: { family: "'Outfit', 'Inter', sans-serif", size: 13, weight: 'bold' },
                        bodyFont: { family: "'Outfit', 'Inter', sans-serif", size: 12 },
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) label += ': ';
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('es-CO').format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Cantidad de Contratos',
                            font: { size: 10, family: "'Outfit', 'Inter', sans-serif" },
                            color: '#64748b'
                        },
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            color: '#64748b',
                            font: { size: 10, family: "'Outfit', 'Inter', sans-serif" },
                            callback: function(value) { if (Number.isInteger(value)) return value; }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11, weight: '600', family: "'Outfit', 'Inter', sans-serif" }
                        }
                    }
                }
            }
        });
    });
</script>

{{-- Modal de Detalle Consolidado Mensual --}}
<div id="modalDetalle" class="modal-overlay" style="display: none;">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 id="modalTitulo" class="modal-title">Detalle de Personas</h3>
                <p id="modalSubtitulo" class="modal-subtitle">Cargando...</p>
            </div>
            <button class="modal-close-btn" onclick="cerrarModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            {{-- Buscador y Contador unificados --}}
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <span style="font-size: 0.82rem; color: #64748b; font-weight: 600;">
                    Usa los campos de cada columna para filtrar los datos o haz clic en los títulos para ordenar.
                </span>
                <span id="modalCounter" class="modal-counter">0 registros</span>
            </div>

            {{-- Spinner de carga --}}
            <div id="modalLoading" class="modal-loader-container">
                <div class="modal-loader"></div>
                <p>Cargando información...</p>
            </div>

            {{-- Contenedor de la tabla con filtros y ordenamientos --}}
            <div id="modalTableContainer" style="display: none; max-height: 480px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff;">
                <table class="modal-table">
                    <thead>
                        <tr>
                            <th onclick="ordenarPor('cedula')">Cédula <span id="sort-icon-cedula" class="sort-indicator">↕</span></th>
                            <th onclick="ordenarPor('nombre_completo')">Nombre Completo <span id="sort-icon-nombre_completo" class="sort-indicator">↕</span></th>
                            <th onclick="ordenarPor('modalidad')">Plan y Modalidad <span id="sort-icon-modalidad" class="sort-indicator">↕</span></th>
                            <th onclick="ordenarPor('fecha_ingreso')">Fecha Ingreso <span id="sort-icon-fecha_ingreso" class="sort-indicator">↕</span></th>
                            <th onclick="ordenarPor('fecha_retiro')">Fecha Retiro <span id="sort-icon-fecha_retiro" class="sort-indicator">↕</span></th>
                            <th onclick="ordenarPor('facturas')">Factura(s) <span id="sort-icon-facturas" class="sort-indicator">↕</span></th>
                            <th onclick="ordenarPor('fecha_factura')">Fecha Factura <span id="sort-icon-fecha_factura" class="sort-indicator">↕</span></th>
                        </tr>
                        <tr style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                            <td style="padding: 0.4rem 0.5rem;"><input type="text" id="filtro-cedula" placeholder="🔍 Cédula..." class="modal-filter-input" oninput="aplicarFiltrosModal()"></td>
                            <td style="padding: 0.4rem 0.5rem;"><input type="text" id="filtro-nombre" placeholder="🔍 Nombre..." class="modal-filter-input" oninput="aplicarFiltrosModal()"></td>
                            <td style="padding: 0.4rem 0.5rem;"><input type="text" id="filtro-modalidad" placeholder="🔍 Plan/Mod..." class="modal-filter-input" oninput="aplicarFiltrosModal()"></td>
                            <td style="padding: 0.4rem 0.5rem;"><input type="text" id="filtro-ingreso" placeholder="🔍 Ingreso..." class="modal-filter-input" oninput="aplicarFiltrosModal()"></td>
                            <td style="padding: 0.4rem 0.5rem;"><input type="text" id="filtro-retiro" placeholder="🔍 Retiro..." class="modal-filter-input" oninput="aplicarFiltrosModal()"></td>
                            <td style="padding: 0.4rem 0.5rem;"><input type="text" id="filtro-facturas" placeholder="🔍 Factura..." class="modal-filter-input" oninput="aplicarFiltrosModal()"></td>
                            <td style="padding: 0.4rem 0.5rem;"><input type="text" id="filtro-fecha-factura" placeholder="🔍 Fecha Fact..." class="modal-filter-input" oninput="aplicarFiltrosModal()"></td>
                        </tr>
                    </thead>
                    <tbody id="modalTableBody">
                        {{-- Filas dinámicas --}}
                    </tbody>
                </table>
            </div>
            
            {{-- Mensaje de no resultados --}}
            <div id="modalNoResults" class="modal-no-results" style="display: none;">
                No se encontraron personas con los criterios especificados.
            </div>
        </div>
    </div>
</div>

{{-- Modal de WhatsApp Consolidado Mensual --}}
<div id="modalWhatsapp" class="modal-overlay" style="display: none;">
    <div class="modal-card" style="max-width: 900px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">Detalle de WhatsApps de Planilla</h3>
                <p id="modalWaSubtitulo" class="modal-subtitle">Cargando...</p>
            </div>
            <button class="modal-close-btn" onclick="cerrarModalWa()">&times;</button>
        </div>
        
        <div class="modal-body" style="overflow-y: auto; padding: 1.5rem 1.5rem 2rem;">
            {{-- Spinner de carga --}}
            <div id="modalWaLoading" class="modal-loader-container" style="padding: 2.5rem 0;">
                <div class="modal-loader"></div>
                <p style="margin-top: 0.75rem; color: #64748b; font-size: 0.85rem;">Cargando información...</p>
            </div>

            <div id="modalWaContent" style="display: none; flex-direction: column; gap: 1.5rem;">
                
                {{-- Tarjetas de contadores superiores --}}
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 0.25rem;">
                    <div style="background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.15); padding: 0.75rem 1rem; border-radius: 12px; display: flex; flex-direction: column; gap: 0.25rem;">
                        <span style="font-size: 0.65rem; font-weight: 700; color: #3b82f6; text-transform: uppercase; letter-spacing: 0.05em;">Lotes de Planillas</span>
                        <div style="display: flex; align-items: baseline; gap: 0.4rem;">
                            <span id="statPlanillasLotes" style="font-size: 1.3rem; font-weight: 800; color: #1e3a8a;">0</span>
                            <span style="font-size: 0.72rem; color: #64748b;">lotes</span>
                        </div>
                        <span id="statPlanillasDetalle" style="font-size: 0.7rem; color: #64748b; margin-top: 0.1rem;">0 enviados</span>
                    </div>
                    <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.15); padding: 0.75rem 1rem; border-radius: 12px; display: flex; flex-direction: column; gap: 0.25rem;">
                        <span style="font-size: 0.65rem; font-weight: 700; color: #10b981; text-transform: uppercase; letter-spacing: 0.05em;">Lotes de Cobros</span>
                        <div style="display: flex; align-items: baseline; gap: 0.4rem;">
                            <span id="statCobrosLotes" style="font-size: 1.3rem; font-weight: 800; color: #065f46;">0</span>
                            <span style="font-size: 0.72rem; color: #64748b;">lotes</span>
                        </div>
                        <span id="statCobrosDetalle" style="font-size: 0.7rem; color: #64748b; margin-top: 0.1rem;">0 enviados</span>
                    </div>
                    <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.15); padding: 0.75rem 1rem; border-radius: 12px; display: flex; flex-direction: column; gap: 0.25rem;">
                        <span style="font-size: 0.65rem; font-weight: 700; color: #f59e0b; text-transform: uppercase; letter-spacing: 0.05em;">Envíos Individuales</span>
                        <div style="display: flex; align-items: baseline; gap: 0.4rem;">
                            <span id="statIndividuales" style="font-size: 1.3rem; font-weight: 800; color: #78350f;">0</span>
                            <span style="font-size: 0.72rem; color: #64748b;">envíos</span>
                        </div>
                        <span style="font-size: 0.7rem; color: #64748b; margin-top: 0.1rem;">Planillas (manuales / reenvíos)</span>
                    </div>
                </div>

                {{-- Sección 1: Envíos Masivos (Lotes) --}}
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem;">
                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 800; color: #0f766e; display: flex; align-items: center; gap: 0.5rem;">
                            <span>📋</span> Lotes de Envíos Masivos
                        </h4>
                        <span id="modalWaLotesCounter" class="modal-counter">0 lotes</span>
                    </div>
                    
                    <div id="modalWaLotesTableContainer" style="max-height: 220px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;">
                        <table class="modal-table">
                            <thead>
                                <tr style="position: sticky; top: 0; background: #f8fafc; z-index: 10;">
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem;">Fecha</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem; text-align: center;">Lote ID</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem;">Plantilla</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem;">Módulo</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem;">Tipo</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem; text-align: right;">Destinatarios</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem; text-align: right;">Enviados</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem; text-align: right;">Fallidos</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem; text-align: center;">Estado</th>
                                </tr>
                            </thead>
                            <tbody id="modalWaLotesTableBody">
                                {{-- Filas dinámicas --}}
                            </tbody>
                        </table>
                    </div>
                    <div id="modalWaLotesNoResults" class="modal-no-results" style="display: none; padding: 1.5rem; text-align: center; color: #64748b; font-size: 0.8rem;">
                        No se registraron lotes de envíos masivos este mes.
                    </div>
                </div>

                {{-- Sección 2: Respuestas / Conversaciones del Cliente --}}
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem;">
                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 800; color: #7c3aed; display: flex; align-items: center; gap: 0.5rem;">
                            <span>💬</span> Conversaciones Iniciadas por Clientes
                        </h4>
                        <span id="modalWaConvCounter" class="modal-counter" style="background: rgba(124, 58, 237, 0.08); border-color: rgba(124, 58, 237, 0.2); color: #7c3aed;">0 conversaciones</span>
                    </div>

                    <div id="modalWaConvTableContainer" style="max-height: 220px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;">
                        <table class="modal-table">
                            <thead>
                                <tr style="position: sticky; top: 0; background: #f8fafc; z-index: 10;">
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem;">Cliente</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem;">Número WhatsApp</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem;">Fecha Primera Respuesta</th>
                                    <th style="padding: 0.5rem 0.75rem; font-size: 0.65rem; text-align: center;">Estado Chat</th>
                                </tr>
                            </thead>
                            <tbody id="modalWaConvTableBody">
                                {{-- Filas dinámicas --}}
                            </tbody>
                        </table>
                    </div>
                    <div id="modalWaConvNoResults" class="modal-no-results" style="display: none; padding: 1.5rem; text-align: center; color: #64748b; font-size: 0.8rem;">
                        No se registraron conversaciones iniciadas por clientes este mes.
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
    /* Estilos del modal y filtros */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(8px);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .modal-overlay.active {
        opacity: 1;
    }
    .modal-card {
        background: #ffffff;
        border-radius: 20px;
        width: 92%;
        max-width: 900px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(226, 232, 240, 0.8);
        overflow: hidden;
        transform: scale(0.96);
        transition: transform 0.25s ease;
        display: flex;
        flex-direction: column;
        max-height: 85vh;
    }
    .modal-overlay.active .modal-card {
        transform: scale(1);
    }
    .modal-header {
        padding: 1.25rem 1.5rem;
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-title {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.02em;
    }
    .modal-subtitle {
        margin: 0.15rem 0 0;
        font-size: 0.8rem;
        color: #64748b;
        font-weight: 500;
    }
    .modal-close-btn {
        background: none;
        border: none;
        font-size: 1.75rem;
        color: #94a3b8;
        cursor: pointer;
        transition: color 0.2s, transform 0.2s;
        line-height: 1;
        padding: 0.25rem;
    }
    .modal-close-btn:hover {
        color: #ef4444;
        transform: scale(1.1);
    }
    .modal-body {
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        overflow: hidden;
    }
    .modal-filter-input {
        width: 100%;
        padding: 0.35rem 0.5rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.75rem;
        font-family: inherit;
        outline: none;
        background-color: #ffffff;
        box-sizing: border-box;
    }
    .modal-filter-input:focus {
        border-color: #0d9488;
        box-shadow: 0 0 0 2px rgba(13, 148, 136, 0.12);
    }
    .modal-counter {
        font-size: 0.72rem;
        font-weight: 800;
        color: #0d9488;
        background: rgba(13, 148, 136, 0.08);
        border: 1px solid rgba(13, 148, 136, 0.2);
        padding: 0.3rem 0.65rem;
        border-radius: 8px;
        white-space: nowrap;
        letter-spacing: 0.02em;
    }
    .modal-loader-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 3rem 0;
        color: #64748b;
        font-size: 0.88rem;
        gap: 0.75rem;
    }
    .modal-loader {
        width: 32px;
        height: 32px;
        border: 3px solid #f1f5f9;
        border-top: 3px solid #0d9488;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    .modal-table {
        width: 100%;
        border-collapse: collapse;
    }
    .modal-table th {
        position: sticky;
        top: 0;
        background: #f8fafc;
        color: #475569;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 0.75rem 1rem;
        text-align: left;
        border-bottom: 2px solid #e2e8f0;
        z-index: 10;
        cursor: pointer;
        user-select: none;
        transition: background-color 0.15s, color 0.15s;
    }
    .modal-table th:hover {
        background-color: #f1f5f9;
        color: #0d9488;
    }
    .modal-table td {
        padding: 0.75rem 1rem;
        font-size: 0.8rem;
        color: #334155;
        border-bottom: 1px solid #f1f5f9;
    }
    .modal-table tr:hover td {
        background-color: #f8fafc;
    }
    .modal-no-results {
        text-align: center;
        padding: 3rem 0;
        color: #64748b;
        font-size: 0.85rem;
        font-weight: 500;
    }
    .sort-indicator {
        font-size: 0.72rem;
        margin-left: 0.25rem;
        color: #94a3b8;
        display: inline-block;
        transition: color 0.15s;
    }
    .sort-indicator.active {
        color: #0d9488;
        font-weight: bold;
    }
    
    /* Puntero e interactividad para las celdas cliqueables */
    .clickable-cell {
        cursor: pointer;
        transition: all 0.2s;
    }
    .clickable-cell:hover {
        background-color: rgba(13, 148, 136, 0.08) !important;
        text-decoration: underline;
    }
</style>

<script>
    let personasOriginal = [];
    let personasFiltradas = [];
    let sortColumna = '';
    let sortDireccion = 'asc';

    function abrirDetalle(mes, anio, tipo) {
        const modal = document.getElementById('modalDetalle');
        const loader = document.getElementById('modalLoading');
        const tableContainer = document.getElementById('modalTableContainer');
        const noResults = document.getElementById('modalNoResults');
        
        // Reset filters
        document.getElementById('filtro-cedula').value = '';
        document.getElementById('filtro-nombre').value = '';
        document.getElementById('filtro-modalidad').value = '';
        document.getElementById('filtro-ingreso').value = '';
        document.getElementById('filtro-retiro').value = '';
        document.getElementById('filtro-facturas').value = '';
        document.getElementById('filtro-fecha-factura').value = '';
        
        // Reset sort
        sortColumna = '';
        sortDireccion = 'asc';
        actualizarIndicadoresOrden();
        
        loader.style.display = 'flex';
        tableContainer.style.display = 'none';
        noResults.style.display = 'none';
        document.getElementById('modalCounter').textContent = '0 registros';
        
        // Mostrar overlay
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('active'), 10);
        
        // Fetch data
        const url = `{{ route('admin.informes.consolidado_mensual_detalle') }}?mes=${mes}&anio=${anio}&tipo=${tipo}`;
        
        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error('Error al obtener datos.');
                return res.json();
            })
            .then(data => {
                document.getElementById('modalTitulo').textContent = data.tipo_label;
                document.getElementById('modalSubtitulo').textContent = `${data.mes_label} · Resumen Mensual`;
                
                personasOriginal = data.personas;
                personasFiltradas = [...personasOriginal];
                loader.style.display = 'none';
                
                renderPersonas(personasFiltradas);
            })
            .catch(err => {
                loader.style.display = 'none';
                noResults.textContent = 'Ocurrió un error al cargar la información. Intente nuevamente.';
                noResults.style.display = 'block';
                console.error(err);
            });
    }

    function renderPersonas(personas) {
        const tableBody = document.getElementById('modalTableBody');
        const tableContainer = document.getElementById('modalTableContainer');
        const noResults = document.getElementById('modalNoResults');
        const counter = document.getElementById('modalCounter');
        
        tableBody.innerHTML = '';
        counter.textContent = `${personas.length} ${personas.length === 1 ? 'registro' : 'registros'}`;
        
        if (personas.length === 0) {
            tableContainer.style.display = 'none';
            noResults.textContent = 'No se encontraron registros.';
            noResults.style.display = 'block';
            return;
        }
        
        noResults.style.display = 'none';
        tableContainer.style.display = 'block';
        
        personas.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-weight: 700; color: #475569;">${p.cedula}</td>
                <td style="font-weight: 600; color: #1e293b;">${nombreOracion(p.nombre_completo)}</td>
                <td>
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.78rem;">${p.modalidad || '—'}</div>
                    <div style="color: #0f766e; font-size: 0.72rem; font-weight: 600;">${p.plan || '—'}</div>
                </td>
                <td>
                    ${p.fecha_ingreso}
                    ${p.tipo_afil ? `<div style="font-size: 0.68rem; font-weight: 700; color: ${p.tipo_afil === 'Nueva' ? '#4f46e5' : '#0891b2'};">${p.tipo_afil}</div>` : ''}
                </td>
                <td>${p.fecha_retiro}</td>
                <td>
                    <span style="background: rgba(13, 148, 136, 0.08); color: #0f766e; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.72rem; font-family: monospace;">
                        ${p.facturas}
                    </span>
                </td>
                <td style="color: #64748b; font-weight: 600; font-size: 0.78rem;">${p.fecha_factura || '—'}</td>
            `;
            tableBody.appendChild(tr);
        });
    }

    function aplicarFiltrosModal() {
        const cedula = document.getElementById('filtro-cedula').value.toLowerCase().trim();
        // Sin tildes: "jose" debe encontrar a "José" y al revés.
        const norm = t => (t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const nombre = norm(document.getElementById('filtro-nombre').value.trim());
        const modalidad = document.getElementById('filtro-modalidad').value.toLowerCase().trim();
        const ingreso = document.getElementById('filtro-ingreso').value.toLowerCase().trim();
        const retiro = document.getElementById('filtro-retiro').value.toLowerCase().trim();
        const facturas = document.getElementById('filtro-facturas').value.toLowerCase().trim();
        const fechaFactura = document.getElementById('filtro-fecha-factura').value.toLowerCase().trim();

        personasFiltradas = personasOriginal.filter(p => {
            return p.cedula.toLowerCase().includes(cedula) &&
                   norm(p.nombre_completo).includes(nombre) &&
                   ((p.modalidad || '') + ' ' + (p.plan || '')).toLowerCase().includes(modalidad) &&
                   (p.fecha_ingreso + ' ' + (p.tipo_afil || '')).toLowerCase().includes(ingreso) &&
                   p.fecha_retiro.toLowerCase().includes(retiro) &&
                   p.facturas.toLowerCase().includes(facturas) &&
                   (p.fecha_factura || '').toLowerCase().includes(fechaFactura);
        });

        // Si hay un ordenamiento activo, mantenerlo al filtrar
        if (sortColumna) {
            ejecutarOrdenamiento();
        } else {
            renderPersonas(personasFiltradas);
        }
    }

    function ordenarPor(columna) {
        if (sortColumna === columna) {
            sortDireccion = sortDireccion === 'asc' ? 'desc' : 'asc';
        } else {
            sortColumna = columna;
            sortDireccion = 'asc';
        }

        actualizarIndicadoresOrden();
        ejecutarOrdenamiento();
    }

    function ejecutarOrdenamiento() {
        personasFiltradas.sort((a, b) => {
            let valA = (a[sortColumna] || '').toString().toLowerCase();
            let valB = (b[sortColumna] || '').toString().toLowerCase();

            // Si es fecha, intentar formatear para ordenar cronológicamente
            if (sortColumna === 'fecha_ingreso' || sortColumna === 'fecha_retiro' || sortColumna === 'fecha_factura') {
                valA = parsearFechaParaOrden(valA);
                valB = parsearFechaParaOrden(valB);
            }

            if (valA < valB) return sortDireccion === 'asc' ? -1 : 1;
            if (valA > valB) return sortDireccion === 'asc' ? 1 : -1;
            return 0;
        });

        renderPersonas(personasFiltradas);
    }

    function parsearFechaParaOrden(fechaStr) {
        if (!fechaStr || fechaStr === '—') return '0000-00-00';
        
        // Extrae la fecha en formato DD/MM/AAAA si viene con el texto adicional de retiro
        const match = fechaStr.match(/(\d{2})\/(\d{2})\/(\d{4})/);
        if (match) {
            return `${match[3]}-${match[2]}-${match[1]}`;
        }
        return fechaStr;
    }

    function actualizarIndicadoresOrden() {
        const columnas = ['cedula', 'nombre_completo', 'modalidad', 'fecha_ingreso', 'fecha_retiro', 'facturas', 'fecha_factura'];
        columnas.forEach(col => {
            const el = document.getElementById(`sort-icon-${col}`);
            if (!el) return;
            if (col === sortColumna) {
                el.textContent = sortDireccion === 'asc' ? ' ▲' : ' ▼';
                el.classList.add('active');
            } else {
                el.textContent = ' ↕';
                el.classList.remove('active');
            }
        });
    }

    function cerrarModal() {
        const modal = document.getElementById('modalDetalle');
        modal.classList.remove('active');
        setTimeout(() => modal.style.display = 'none', 300);
    }

    // Cerrar al hacer clic fuera del card
    document.getElementById('modalDetalle').addEventListener('click', function(e) {
        if (e.target === this) {
            cerrarModal();
        }
    });

    function abrirModalWa(mes, anio) {
        const modal = document.getElementById('modalWhatsapp');
        const loader = document.getElementById('modalWaLoading');
        const content = document.getElementById('modalWaContent');
        const lotesTableBody = document.getElementById('modalWaLotesTableBody');
        const convTableBody = document.getElementById('modalWaConvTableBody');
        const lotesTableContainer = document.getElementById('modalWaLotesTableContainer');
        const convTableContainer = document.getElementById('modalWaConvTableContainer');
        const lotesNoResults = document.getElementById('modalWaLotesNoResults');
        const convNoResults = document.getElementById('modalWaConvNoResults');
        
        loader.style.display = 'flex';
        content.style.display = 'none';
        lotesNoResults.style.display = 'none';
        convNoResults.style.display = 'none';
        lotesTableBody.innerHTML = '';
        convTableBody.innerHTML = '';
        document.getElementById('modalWaLotesCounter').textContent = '0 lotes';
        document.getElementById('modalWaConvCounter').textContent = '0 conversaciones';
        
        // Mostrar overlay
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('active'), 10);
        
        // Fetch data
        const url = `{{ route('admin.informes.consolidado_mensual_whatsapp') }}?mes=${mes}&anio=${anio}`;
        
        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error('Error al obtener datos.');
                return res.json();
            })
            .then(data => {
                document.getElementById('modalWaSubtitulo').textContent = `${data.mes_label} · Resumen de WhatsApp`;
                loader.style.display = 'none';
                content.style.display = 'flex';
                
                // Rellenar tarjetas superiores de contadores
                document.getElementById('statPlanillasLotes').textContent = data.lotes_planillas_cant;
                document.getElementById('statPlanillasDetalle').textContent = `${new Intl.NumberFormat('es-CO').format(data.lotes_planillas_env)} enviados`;
                document.getElementById('statCobrosLotes').textContent = data.lotes_cobros_cant;
                document.getElementById('statCobrosDetalle').textContent = `${new Intl.NumberFormat('es-CO').format(data.lotes_cobros_env)} enviados`;
                document.getElementById('statIndividuales').textContent = data.individuales_cant;

                // 1. Renderizar lotes
                if (data.lotes && data.lotes.length > 0) {
                    lotesTableContainer.style.display = 'block';
                    lotesNoResults.style.display = 'none';
                    let htmlLotes = '';
                    data.lotes.forEach(l => {
                        const fecha = new Date(l.fecha_envio + 'T00:00:00');
                        const fechaFormateada = fecha.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' });
                        const tipo = l.tipo_envio === 'empresa' ? '🏢 Empresa' : '👤 Individual';
                        
                        let badgeColor = '#64748b';
                        let badgeBg = '#f1f5f9';
                        if (l.estado === 'completado') {
                            badgeColor = '#10b981';
                            badgeBg = '#ecfdf5';
                        } else if (l.estado === 'procesando') {
                            badgeColor = '#3b82f6';
                            badgeBg = '#eff6ff';
                        } else if (l.estado === 'fallido') {
                            badgeColor = '#ef4444';
                            badgeBg = '#fef2f2';
                        }

                        const moduloText = l.tipo_lote === 'planilla' ? '📥 Planillas' : '💰 Cobros';
                        const moduloColor = l.tipo_lote === 'planilla' ? '#3b82f6' : '#10b981';
                        
                        htmlLotes += `
                            <tr>
                                <td style="padding: 0.6rem 0.75rem;">${fechaFormateada}</td>
                                <td style="text-align: center; font-weight: 700; color: #64748b; padding: 0.6rem 0.75rem;">#${l.lote_id}</td>
                                <td style="padding: 0.6rem 0.75rem; font-weight: 600; color: #334155;">${l.plantilla}</td>
                                <td style="padding: 0.6rem 0.75rem; font-weight: 600; color: ${moduloColor};">${moduloText}</td>
                                <td style="padding: 0.6rem 0.75rem;">${tipo}</td>
                                <td style="text-align: right; padding: 0.6rem 0.75rem; font-weight: 600; color: #0d9488;">${new Intl.NumberFormat('es-CO').format(l.total_destinatarios)}</td>
                                <td style="text-align: right; padding: 0.6rem 0.75rem; color: #10b981; font-weight: 600;">${new Intl.NumberFormat('es-CO').format(l.total_enviados)}</td>
                                <td style="text-align: right; padding: 0.6rem 0.75rem; color: #ef4444; font-weight: 600;">${new Intl.NumberFormat('es-CO').format(l.total_fallidos)}</td>
                                <td style="text-align: center; padding: 0.6rem 0.75rem;">
                                    <span style="display: inline-block; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.68rem; font-weight: 700; color: ${badgeColor}; background: ${badgeBg}; text-transform: uppercase; letter-spacing: 0.02em;">
                                        ${l.estado}
                                    </span>
                                </td>
                            </tr>
                        `;
                    });
                    lotesTableBody.innerHTML = htmlLotes;
                    document.getElementById('modalWaLotesCounter').textContent = `${data.total_lotes} ${data.total_lotes === 1 ? 'lote' : 'lotes'}`;
                } else {
                    lotesTableContainer.style.display = 'none';
                    lotesNoResults.style.display = 'block';
                }
                
                // 2. Renderizar conversaciones
                if (data.conversaciones && data.conversaciones.length > 0) {
                    convTableContainer.style.display = 'block';
                    convNoResults.style.display = 'none';
                    let htmlConv = '';
                    data.conversaciones.forEach(c => {
                        const fecha = c.fecha_primera_respuesta ? new Date(c.fecha_primera_respuesta + 'T00:00:00') : null;
                        const fechaFormateada = fecha ? fecha.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—';
                        const numLink = `https://wa.me/${c.wa_contact_id.replace(/\+/g, '')}`;
                        
                        let badgeColor = '#64748b';
                        let badgeBg = '#f1f5f9';
                        if (c.estado === 'abierta') {
                            badgeColor = '#3b82f6';
                            badgeBg = '#eff6ff';
                        } else if (c.estado === 'asignada') {
                            badgeColor = '#7c3aed';
                            badgeBg = '#f5f3ff';
                        } else if (c.estado === 'cerrada') {
                            badgeColor = '#10b981';
                            badgeBg = '#ecfdf5';
                        }

                        htmlConv += `
                            <tr>
                                <td style="padding: 0.6rem 0.75rem; font-weight: 600; color: #1e293b;">${c.nombre_contacto || 'Cliente de WhatsApp'}</td>
                                <td style="padding: 0.6rem 0.75rem;">
                                    <a href="${numLink}" target="_blank" style="color: #2563eb; font-weight: 600; text-decoration: none; font-family: monospace;">
                                        ${c.wa_contact_id}
                                    </a>
                                </td>
                                <td style="padding: 0.6rem 0.75rem; color: #475569; font-weight: 500;">${fechaFormateada}</td>
                                <td style="text-align: center; padding: 0.6rem 0.75rem;">
                                    <span style="display: inline-block; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.68rem; font-weight: 700; color: ${badgeColor}; background: ${badgeBg}; text-transform: uppercase; letter-spacing: 0.02em;">
                                        ${c.estado}
                                    </span>
                                </td>
                            </tr>
                        `;
                    });
                    convTableBody.innerHTML = htmlConv;
                    document.getElementById('modalWaConvCounter').textContent = `${data.total_conv} ${data.total_conv === 1 ? 'conversación' : 'conversaciones'}`;
                } else {
                    convTableContainer.style.display = 'none';
                    convNoResults.style.display = 'block';
                }
            })
            .catch(err => {
                loader.style.display = 'none';
                lotesNoResults.textContent = 'Ocurrió un error al cargar la información. Intente nuevamente.';
                lotesNoResults.style.display = 'block';
                console.error(err);
            });
    }

    function cerrarModalWa() {
        const modal = document.getElementById('modalWhatsapp');
        modal.classList.remove('active');
        setTimeout(() => modal.style.display = 'none', 300);
    }

    // Cerrar al hacer clic fuera del card
    document.getElementById('modalWhatsapp').addEventListener('click', function(e) {
        if (e.target === this) {
            cerrarModalWa();
        }
    });
</script>

@endsection
