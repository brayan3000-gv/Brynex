<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuenta de cobro {{ $aliado->nombre }}</title>
    @php
        $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('img/logo-brynex-claro.png')));
    @endphp
    <style>
        @page { margin: 0; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #111827;
            font-size: 13px;
            line-height: 1.45;
            margin: 0;
        }
        /* Franjas de color en las esquinas, como el membrete de la plantilla */
        .franja { position: absolute; }
        .franja-1 { top: 0; right: 0; width: 330px; height: 26px; background: #1d4ed8; }
        .franja-2 { top: 26px; right: 0; width: 210px; height: 10px; background: #60a5fa; }
        .franja-3 { bottom: 0; left: 0; width: 330px; height: 26px; background: #1d4ed8; }
        .franja-4 { bottom: 26px; left: 0; width: 210px; height: 10px; background: #60a5fa; }
        .pagina { padding: 70px 80px 0 80px; }
        .logo { width: 210px; }
        .fecha { margin-top: 50px; font-weight: bold; }
        .centro { text-align: center; }
        .titulo { margin-top: 45px; font-size: 15px; font-weight: bold; letter-spacing: 0.5px; }
        .aliado { margin-top: 22px; font-size: 14px; }
        .bloque { margin-top: 32px; }
        .bloque-titulo { font-weight: bold; }
        .conceptos { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .conceptos td { padding: 3px 0; vertical-align: top; }
        .conceptos .valor { text-align: right; white-space: nowrap; padding-left: 18px; }
        .conceptos .total td { border-top: 1px solid #9ca3af; padding-top: 6px; font-weight: bold; }
        .firma { margin-top: 70px; }
        .pie {
            position: absolute; bottom: 55px; left: 80px;
            font-size: 11.5px; color: #374151;
        }
    </style>
</head>
<body>
    <div class="franja franja-1"></div>
    <div class="franja franja-2"></div>
    <div class="franja franja-3"></div>
    <div class="franja franja-4"></div>

    <div class="pagina">
        <img src="{{ $logo }}" class="logo" alt="BryNex">

        <div class="fecha">Santiago de Cali, {{ $fechaTexto }}</div>

        <div class="centro titulo">CUENTA DE COBRO</div>
        <div class="centro aliado">{{ mb_strtoupper($aliado->nombre) }}@if($aliado->nit)<br>NIT {{ $aliado->nit }}@endif</div>

        <div class="centro bloque">
            <div class="bloque-titulo">DEBE A:</div>
            {{ $acreedor }}<br>
            CC: {{ $cedula }}
        </div>

        <div class="centro bloque">
            <div class="bloque-titulo">LA SUMA DE:</div>
            {{-- «Cuatro millones de pesos», pero «Cuatro millones cien mil pesos» --}}
            {{ mb_strtoupper(mb_substr(mb_strtolower($totalLetras), 0, 1)).mb_substr(mb_strtolower($totalLetras), 1) }}{{ preg_match('/MILL(ÓN|ONES)$/u', $totalLetras) ? ' de' : '' }} pesos.<br>
            (${{ number_format($total, 0, ',', '.') }}) M/Cte.
        </div>

        <div class="bloque">
            <div class="centro bloque-titulo">POR CONCEPTO DE:</div>
            <table class="conceptos">
                @foreach($conceptos as $c)
                    <tr>
                        <td>{{ $c['texto'] }}</td>
                        <td class="valor">${{ number_format($c['valor'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>Total</td>
                    <td class="valor">${{ number_format($total, 0, ',', '.') }}</td>
                </tr>
            </table>
        </div>

        <div class="firma">
            Atentamente,<br><br><br>
            <strong>Brayan García</strong><br>
            CC {{ $cedula }}
        </div>
    </div>

    <div class="pie">
        300 156 3615 &nbsp;·&nbsp; brynex.co
    </div>
</body>
</html>
