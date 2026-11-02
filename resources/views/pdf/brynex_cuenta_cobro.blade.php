<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuenta de cobro {{ $aliado->nombre }}</title>
    @php
        $logo  = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('img/logo-brynex-claro.png')));
        // Membrete con franjas diagonales en los colores de BryNex (carta a 200 dpi).
        $fondo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('img/cuenta-cobro-fondo.png')));
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
        /* DomPDF pinta lo posicionado encima del flujo normal: el fondo va detrás
           con z-index y la página, posicionada, encima. */
        .fondo { position: absolute; top: 0; left: 0; width: 8.5in; height: 11in; z-index: -1; }
        .pagina { position: relative; z-index: 1; padding: 70px 80px 0 80px; }
        .logo { width: 210px; }
        .fecha { margin-top: 50px; font-weight: bold; }
        .centro { text-align: center; }
        .titulo { margin-top: 45px; font-size: 16px; font-weight: bold; letter-spacing: 2px; color: #1e3a8a; }
        .titulo-linea { width: 70px; height: 3px; background: #2563eb; margin: 8px auto 0; }
        .aliado { margin-top: 22px; font-size: 14px; }
        .bloque { margin-top: 32px; }
        .bloque-titulo { font-weight: bold; color: #1e3a8a; }
        .conceptos { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .conceptos td { padding: 5px 0; vertical-align: top; }
        .conceptos .valor { text-align: right; white-space: nowrap; padding-left: 18px; }
        .conceptos tr td { border-bottom: 1px solid #e5e7eb; }
        .conceptos .total td { border-bottom: none; border-top: 2px solid #2563eb; padding-top: 6px; font-weight: bold; color: #1e3a8a; }
        .firma { margin-top: 70px; }
        .pie {
            position: absolute; bottom: 60px; left: 80px;
            font-size: 11.5px; color: #374151; line-height: 1.9;
        }
        .punto { display: inline-block; width: 8px; height: 8px; border-radius: 4px; background: #2563eb; margin-right: 7px; }
    </style>
</head>
<body>
    <img src="{{ $fondo }}" class="fondo" alt="">

    <div class="pagina">
        <img src="{{ $logo }}" class="logo" alt="BryNex">

        <div class="fecha">Santiago de Cali, {{ $fechaTexto }}</div>

        <div class="centro titulo">CUENTA DE COBRO</div>
        <div class="titulo-linea"></div>
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
        <span class="punto"></span>300 156 3615<br>
        <span class="punto"></span>brynex.co
    </div>
</body>
</html>
