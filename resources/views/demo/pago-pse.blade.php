<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pago PSE de prueba</title>
<style>
    :root { --fondo: #f1f5f9; --caja: #fff; --texto: #0f172a; --suave: #64748b; --borde: #e2e8f0; }
    @media (prefers-color-scheme: dark) { :root { --fondo: #0b1220; --caja: #111827; --texto: #e5e7eb; --suave: #9ca3af; --borde: #1f2937; } }
    body { margin: 0; background: var(--fondo); color: var(--texto); font-family: system-ui, -apple-system, Segoe UI, sans-serif;
        min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; }
    .caja { background: var(--caja); border: 1px solid var(--borde); border-radius: 14px; padding: 1.4rem; width: 100%; max-width: 420px; }
    .aviso { background: #fef3c7; color: #78350f; border-radius: 8px; padding: .5rem .7rem; font-size: .8rem; margin-bottom: 1rem; }
    .valor { font-size: 1.8rem; font-weight: 800; margin: .3rem 0 1rem; }
    .fila { display: flex; justify-content: space-between; font-size: .85rem; padding: .35rem 0; border-bottom: 1px solid var(--borde); }
    .fila span { color: var(--suave); }
    select, button { width: 100%; padding: .65rem; border-radius: 8px; font-size: .95rem; margin-top: .8rem; }
    select { border: 1px solid var(--borde); background: var(--caja); color: var(--texto); }
    button { border: none; background: #16a34a; color: #fff; font-weight: 700; cursor: pointer; }
    .ok { background: #dcfce7; color: #166534; border-radius: 10px; padding: .8rem; font-weight: 700; text-align: center; }
</style>
</head>
<body>
<div class="caja">
    <div class="aviso">🧪 <strong>Pasarela de prueba.</strong> En BryNex real este botón abre el PSE del operador. Aquí no se mueve plata.</div>
    <div style="color:var(--suave);font-size:.85rem">Planilla {{ $numero }}</div>
    <div class="valor">${{ number_format((float) $planilla['total'], 0, ',', '.') }}</div>

    @if (! empty($planilla['pagada']))
        <div class="ok">✅ Pago aprobado (simulado)</div>
        <div class="fila"><span>CUS</span><strong>{{ $planilla['cus'] }}</strong></div>
        <div class="fila"><span>Fecha</span><strong>{{ $planilla['pagada_en'] }}</strong></div>
        <p style="font-size:.82rem;color:var(--suave)">Ya puedes cerrar esta pestaña y volver a BryNex.</p>
    @else
        <form method="POST" action="{{ route('demo.pago-pse.aprobar', $numero) }}">
            @csrf
            <select aria-label="Banco"><option>Banco de prueba</option><option>Otro banco de prueba</option></select>
            <button type="submit">Pagar (simulado)</button>
        </form>
    @endif
</div>
</body>
</html>
