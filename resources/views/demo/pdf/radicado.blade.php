<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Radicado {{ $numero }} (MUESTRA)</title>
<style>
    @page { margin: 22mm 18mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1e293b; }
    .marca { position: fixed; top: 300px; left: -40px; width: 800px; text-align: center; transform: rotate(-32deg);
        font-size: 64px; font-weight: bold; color: #fde7e7; z-index: -1000; letter-spacing: 4px; }
    .franja { position: fixed; left: -18mm; right: -18mm; height: 18px; background: #dc2626; color: #fff; text-align: center;
        font-size: 9.5px; font-weight: bold; line-height: 18px; letter-spacing: 1px; }
    .franja.arriba { top: -22mm; } .franja.abajo { bottom: -20mm; }
    .cab { border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 14px; }
    .cab .ent { font-size: 17px; font-weight: bold; }
    .cab .doc { font-size: 12px; color: #475569; margin-top: 2px; }
    .num { float: right; text-align: right; }
    .num .n { font-size: 14px; font-weight: bold; color: #b91c1c; }
    h2 { font-size: 11.5px; background: #f1f5f9; padding: 5px 7px; margin: 14px 0 6px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    td.k { width: 36%; color: #64748b; }
    .estado { display: inline-block; background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 2px 8px; border-radius: 10px; font-weight: bold; }
    .nota { margin-top: 18px; padding: 8px 10px; border: 1px dashed #dc2626; color: #991b1b; font-size: 9.5px; }
    pre { font-family: DejaVu Sans Mono, monospace; font-size: 8px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px; white-space: pre-wrap; }
</style>
</head>
<body>
@php
    $titulos = [
        'arl' => 'Certificado de afiliación a riesgos laborales',
        'eps' => 'Constancia de radicación de novedad de ingreso',
        'caja' => 'Constancia de afiliación a caja de compensación familiar',
        'pension' => 'Constancia de vinculación al fondo de pensiones',
    ];
@endphp
<div class="franja arriba">MUESTRA · PRUEBA API · DEMO DE BRYNEX · SIN VALIDEZ</div>
<div class="franja abajo">Documento de demostración generado por BryNex. No fue emitido por {{ $entidad['nombre'] }}.</div>
<div class="marca">MUESTRA · PRUEBA API</div>

<div class="cab">
    <div class="num">
        <div style="color:#64748b">N° de radicado</div>
        <div class="n">{{ $numero }}</div>
        <div style="color:#64748b">{{ $ahora->format('d/m/Y H:i') }}</div>
    </div>
    <div class="ent">{{ $entidad['nombre'] }}</div>
    <div class="doc">{{ ($operacion ?? null) === 'anular' ? 'Constancia de anulación de la afiliación' : (($operacion ?? null) === 'retirar' ? 'Constancia de retiro (novedad RET)' : ($titulos[$tipo] ?? 'Constancia de radicación')) }}</div>
    @if ($entidad['nit'])<div class="doc">NIT {{ $entidad['nit'] }}</div>@endif
</div>

<p>Estado del trámite: <span class="estado">{{ ($operacion ?? null) === 'anular' ? 'ANULADA' : (($operacion ?? null) === 'retirar' ? 'RETIRADO' : ($tipo === 'arl' ? 'AFILIADO' : 'RADICADO')) }}</span>
   &nbsp; Canal: API / portal web (simulado)</p>

<h2>Trabajador</h2>
<table>
    <tr><td class="k">Nombre</td><td>{{ $resumen['trabajador'] }}</td></tr>
    <tr><td class="k">Documento</td><td>{{ $resumen['documento'] }}</td></tr>
    @if (! empty($payload['trabajador']['fechaNacimiento']))<tr><td class="k">Fecha de nacimiento</td><td>{{ $payload['trabajador']['fechaNacimiento'] }}</td></tr>@endif
    @if (! empty($payload['trabajador']['direccion']))<tr><td class="k">Dirección</td><td>{{ $payload['trabajador']['direccion'] }}</td></tr>@endif
</table>

<h2>Empleador</h2>
<table>
    <tr><td class="k">Razón social</td><td>{{ $resumen['razon_social'] }}</td></tr>
    <tr><td class="k">NIT</td><td>{{ $resumen['nit_empleador'] }}</td></tr>
</table>

<h2>Datos de la {{ $tipo === 'arl' ? 'afiliación' : 'novedad' }}</h2>
<table>
    @if ($tipo === 'arl')
        <tr><td class="k">Tipo de afiliado</td><td>{{ ($payload['tipoAfiliado'] ?? 'D') === 'I' ? 'Independiente' : 'Dependiente' }}</td></tr>
        <tr><td class="k">Clase de riesgo</td><td>{{ $resumen['nivel_riesgo'] ?: '—' }}</td></tr>
        <tr><td class="k">Cargo</td><td>{{ $resumen['cargo'] ?: '—' }}</td></tr>
        <tr><td class="k">{{ ($operacion ?? null) === 'retirar' ? 'Fin de cobertura' : (($operacion ?? null) === 'anular' ? 'Anulada el' : 'Inicio de cobertura') }}</td><td><strong>{{ $fechaCobertura->format('d/m/Y') }}</strong></td></tr>
    @else
        <tr><td class="k">Novedad</td><td>ING — {{ $payload['descripcion'] ?? 'Ingreso' }}</td></tr>
    @endif
    <tr><td class="k">Modalidad</td><td>{{ $resumen['modalidad'] ?: '—' }}</td></tr>
    <tr><td class="k">Fecha de ingreso</td><td>{{ $resumen['fecha_ingreso'] ?: '—' }}</td></tr>
    <tr><td class="k">IBC</td><td>${{ number_format((float) $resumen['ibc'], 0, ',', '.') }}</td></tr>
</table>

<h2>Lo que BryNex envió al API (prueba)</h2>
<pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>

<div class="nota">
    <strong>MUESTRA — PRUEBA API.</strong> Este documento lo generó la demo de BryNex para enseñar cómo queda un trámite
    radicado por API. No se envió nada a {{ $entidad['nombre'] }}, el número de radicado es inventado y el documento no
    tiene ninguna validez. En BryNex real, este soporte lo entrega la entidad y queda adjunto al radicado.
</div>
</body>
</html>
