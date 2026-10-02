@php
    $pesos = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    $esEmpresa = $prospecto->esEmpresa();
    $completo = $resultado['completo'];
    $proporcional = $resultado['proporcional'];
    $dias = $resultado['dias'];
    $hayProporcional = $dias < 30 && ($proporcional['total'] ?? 0) > 0;
    $nTrab = max(1, count($resultado['trabajadores']));
    $afiliacion = $resultado['costo_afiliacion'] * ($esEmpresa ? $nTrab : 1);
    $logo = $aliado && $aliado->logo && file_exists(public_path('storage/'.$aliado->logo)) ? public_path('storage/'.$aliado->logo) : null;
    $riesgos = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotización - {{ $prospecto->nombre_mostrar }}</title>
    <style>
        @page { margin: 28px 36px 60px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 12px; color: #1e293b; }
        .cab { width: 100%; border-bottom: 2px solid #1e40af; padding-bottom: 10px; margin-bottom: 16px; }
        .cab td { vertical-align: middle; }
        .logo { max-height: 60px; max-width: 180px; }
        .marca { font-size: 20px; font-weight: bold; color: #1e40af; }
        .titulo { font-size: 17px; font-weight: bold; color: #0f172a; text-align: right; }
        .meta { font-size: 11px; color: #64748b; text-align: right; margin-top: 3px; }
        h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #1e40af; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e2e8f0; }
        table { width: 100%; border-collapse: collapse; }
        .datos td { padding: 4px 6px; font-size: 12px; }
        .datos td.k { color: #64748b; width: 32%; }
        .tabla th, .tabla td { border: 1px solid #e2e8f0; padding: 6px 8px; }
        .tabla th { background: #f1f5f9; font-size: 11px; text-transform: uppercase; color: #475569; text-align: left; }
        .der { text-align: right; }
        .pct { color: #94a3b8; font-size: 10px; }
        .tot td { font-weight: bold; background: #eff6ff; }
        .totales { margin-top: 14px; width: 100%; }
        .totales td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
        .totales .v { text-align: right; font-weight: bold; font-size: 14px; white-space: nowrap; }
        .totales .nota { color: #64748b; font-size: 10px; }
        .totales .mes td { background: #0f172a; color: #fff; border: none; }
        .totales .mes .v { color: #34d399; font-size: 17px; }
        .aviso { margin-top: 18px; font-size: 10px; color: #64748b; text-align: justify; line-height: 1.45; }
        .pie { position: fixed; bottom: -40px; left: 0; right: 0; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    </style>
</head>
<body>

<table class="cab">
    <tr>
        <td style="width:45%;">
            @if($logo)
                <img src="{{ $logo }}" alt="Logo" class="logo">
            @else
                <div class="marca">{{ $aliado->nombre ?? 'BryNex' }}</div>
            @endif
        </td>
        <td>
            <div class="titulo">Cotización de seguridad social</div>
            <div class="meta">
                N.º {{ str_pad($prospecto->id, 5, '0', STR_PAD_LEFT) }} · Fecha: {{ now()->format('d/m/Y') }} · Vigencia: 15 días
            </div>
        </td>
    </tr>
</table>

<h3>{{ $esEmpresa ? 'Empresa' : 'Cliente' }}</h3>
<table class="datos">
    @if($esEmpresa)
        <tr><td class="k">Razón social</td><td><strong>{{ $prospecto->empresa_nombre }}</strong></td></tr>
        @if($prospecto->empresa_nit)<tr><td class="k">NIT</td><td>{{ $prospecto->empresa_nit }}</td></tr>@endif
        <tr><td class="k">Persona de contacto</td><td>{{ nombre_oracion($prospecto->nombre_completo ?: '') ?: 'Por definir' }}</td></tr>
    @else
        <tr><td class="k">Nombre</td><td><strong>{{ nombre_oracion($prospecto->nombre_completo ?: '') ?: 'Por definir' }}</strong></td></tr>
        @if($prospecto->cedula)<tr><td class="k">Identificación</td><td>{{ $prospecto->tipo_doc ?: 'CC' }} {{ $prospecto->cedula }}</td></tr>@endif
    @endif
    <tr><td class="k">Celular</td><td>{{ $prospecto->celular ?: 'No registrado' }}</td></tr>
    @if($prospecto->fecha_ingreso)
        <tr><td class="k">Fecha estimada de ingreso</td><td>{{ $prospecto->fecha_ingreso->format('d/m/Y') }}</td></tr>
    @endif
</table>

@if($esEmpresa)
    <h3>Trabajadores cotizados</h3>
    <table class="tabla">
        <thead>
            <tr>
                <th>Cargo</th>
                <th>Plan</th>
                <th class="der">Salario</th>
                <th>Riesgo</th>
                @if($hayProporcional)<th class="der">Primer mes ({{ $dias }} días)</th>@endif
                <th class="der">Mensual</th>
            </tr>
        </thead>
        <tbody>
            @foreach($resultado['trabajadores'] as $t)
            <tr>
                <td>{{ $t['cargo'] }}@if($t['nombre'])<br><span class="pct">{{ $t['nombre'] }}</span>@endif</td>
                <td>{{ $t['plan'] }}</td>
                <td class="der">{{ $pesos($t['salario']) }}</td>
                <td>{{ $riesgos[$t['n_arl']] ?? $t['n_arl'] }}</td>
                @if($hayProporcional)<td class="der">{{ $pesos($t['proporcional']['total'] ?? 0) }}</td>@endif
                <td class="der">{{ $pesos($t['completo']['total'] ?? 0) }}</td>
            </tr>
            @endforeach
            <tr class="tot">
                <td colspan="{{ $hayProporcional ? 4 : 4 }}">Total {{ $nTrab }} {{ $nTrab === 1 ? 'trabajador' : 'trabajadores' }}</td>
                @if($hayProporcional)<td class="der">{{ $pesos($proporcional['total'] ?? 0) }}</td>@endif
                <td class="der">{{ $pesos($completo['total'] ?? 0) }}</td>
            </tr>
        </tbody>
    </table>
@else
    <h3>Plan cotizado</h3>
    <table class="datos">
        <tr><td class="k">Modalidad</td><td>{{ $prospecto->modalidad->observacion ?? $prospecto->modalidad->tipo_modalidad ?? 'No especificada' }}</td></tr>
        <tr><td class="k">Plan</td><td>{{ $prospecto->plan->nombre ?? 'No especificado' }}</td></tr>
        <tr><td class="k">Ingreso base de cotización</td><td>{{ $pesos($prospecto->salario_base) }}</td></tr>
        @if($prospecto->n_arl)<tr><td class="k">Nivel de riesgo ARL</td><td>{{ $riesgos[(int) $prospecto->n_arl] ?? $prospecto->n_arl }}</td></tr>@endif
    </table>

    <h3>Aportes mensuales</h3>
    <table class="tabla">
        <thead>
            <tr>
                <th>Concepto</th>
                @if($hayProporcional)<th class="der">Primer mes ({{ $dias }} días)</th>@endif
                <th class="der">Mes completo</th>
            </tr>
        </thead>
        <tbody>
            @foreach(['eps' => ['Salud (EPS)', 'pctEps'], 'pen' => ['Pensión (AFP)', 'pctPen'], 'arl' => ['Riesgos laborales (ARL)', 'pctArl'], 'caja' => ['Caja de compensación', 'pctCaja']] as $k => [$nombre, $pct])
                @if(($completo[$k] ?? 0) > 0 || ($proporcional[$k] ?? 0) > 0)
                <tr>
                    <td>{{ $nombre }} @if(!empty($completo[$pct]))<span class="pct">({{ $completo[$pct] }}%)</span>@endif</td>
                    @if($hayProporcional)<td class="der">{{ $pesos($proporcional[$k] ?? 0) }}</td>@endif
                    <td class="der">{{ $pesos($completo[$k] ?? 0) }}</td>
                </tr>
                @endif
            @endforeach
            @if(($completo['admon'] ?? 0) > 0)
            <tr>
                <td>Administración{{ ($completo['iva'] ?? 0) > 0 ? ' (incluye IVA)' : '' }}</td>
                @if($hayProporcional)<td class="der">{{ $pesos(($completo['admon'] ?? 0) + ($completo['iva'] ?? 0)) }}</td>@endif
                <td class="der">{{ $pesos(($completo['admon'] ?? 0) + ($completo['iva'] ?? 0)) }}</td>
            </tr>
            @endif
            @if(($completo['seguro'] ?? 0) > 0)
            <tr>
                <td>Seguro</td>
                @if($hayProporcional)<td class="der">{{ $pesos($completo['seguro']) }}</td>@endif
                <td class="der">{{ $pesos($completo['seguro']) }}</td>
            </tr>
            @endif
        </tbody>
    </table>
@endif

<table class="totales">
    @if($afiliacion > 0)
    <tr>
        <td>Afiliación <span class="nota">(pago único{{ $esEmpresa ? ', '.$nTrab.' × '.$pesos($resultado['costo_afiliacion']) : '' }})</span></td>
        <td class="v">{{ $pesos($afiliacion) }}</td>
    </tr>
    @endif
    @if($hayProporcional)
    <tr>
        <td>Primer mes proporcional <span class="nota">(por {{ $dias }} días)</span></td>
        <td class="v">{{ $pesos($proporcional['total'] ?? 0) }}</td>
    </tr>
    @endif
    @if(($completo['total'] ?? 0) > 0)
    <tr class="mes">
        <td>Valor mensual <span class="nota" style="color:#94a3b8;">(meses siguientes, 30 días)</span></td>
        <td class="v">{{ $pesos($completo['total'] ?? 0) }}</td>
    </tr>
    @endif
</table>

<p class="aviso">
    <strong>Nota:</strong> los valores se calculan con la normatividad vigente y el salario mínimo legal del año en curso.
    Si cambian el salario mínimo o las tarifas de ley, la cotización se ajusta en la misma proporción.
    Los aportes a seguridad social se pagan a las entidades por medio de la planilla PILA; la administración corresponde al servicio de {{ $aliado->nombre ?? 'BryNex' }}.
</p>

<div class="pie">{{ $aliado->nombre ?? 'BryNex' }}@if($aliado && $aliado->celular) · WhatsApp {{ $aliado->celular }}@endif</div>

</body>
</html>
