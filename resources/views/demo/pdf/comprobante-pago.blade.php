<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comprobante de pago (MUESTRA)</title>
<style>
    @page { margin: 22mm 18mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
    .marca { position: fixed; top: 300px; left: -40px; width: 800px; text-align: center; transform: rotate(-32deg);
        font-size: 64px; font-weight: bold; color: #fde7e7; z-index: -1000; }
    .franja { background: #dc2626; color: #fff; text-align: center; font-weight: bold; padding: 4px; letter-spacing: 1px; margin-bottom: 16px; }
    h1 { font-size: 16px; margin: 0 0 12px; }
    td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; }
    td.k { width: 40%; color: #64748b; }
    table { width: 100%; border-collapse: collapse; }
</style>
</head>
<body>
<div class="marca">MUESTRA · PRUEBA API</div>
<div class="franja">MUESTRA · PRUEBA API · DEMO DE BRYNEX · SIN VALIDEZ</div>
<h1>Comprobante de pago de planilla (PSE de prueba)</h1>
<table>
    <tr><td class="k">Planilla</td><td>{{ $plano->numero_planilla ?: '—' }}</td></tr>
    <tr><td class="k">Cotizante</td><td>{{ $plano->cedula ?? '—' }}</td></tr>
    <tr><td class="k">Banco</td><td>BANCO DE PRUEBA</td></tr>
    <tr><td class="k">CUS</td><td>DEMO{{ strtoupper(substr(md5((string) $plano->id), 0, 10)) }}</td></tr>
    <tr><td class="k">Estado</td><td><strong>APROBADA (simulada)</strong></td></tr>
    <tr><td class="k">Generado</td><td>{{ $ahora->format('d/m/Y H:i') }}</td></tr>
</table>
<p style="margin-top:18px;color:#991b1b;font-size:9.5px">Documento de demostración. No hubo pago real ni intervino ningún banco.</p>
</body>
</html>
