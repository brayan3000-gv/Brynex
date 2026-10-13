{{--
    Carta laboral (CartaLaboralService::pdf). El texto llega armado en $c; aquí
    solo se pinta. El Word sale del mismo $c en CartaLaboralService::word().
    Encabezado y pie van fijos para que se repitan si la carta pasa de página.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 3.1cm 2.4cm 2.4cm 2.4cm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; line-height: 1.38; color: #111; }
    #membrete { position: fixed; top: -2.4cm; left: 0; right: 0; height: 2.1cm; border-bottom: 1.5px solid #1f3b64; }
    #membrete table { width: 100%; border-collapse: collapse; }
    #membrete td { vertical-align: middle; padding: 0; }
    #membrete img { max-height: 1.7cm; max-width: 6cm; }
    .empresa { text-align: right; }
    .empresa .nombre { font-size: 12pt; font-weight: bold; color: #1f3b64; }
    .empresa .nit { font-size: 9.5pt; color: #333; }
    #pie { position: fixed; bottom: -1.8cm; left: 0; right: 0; height: 1.3cm; border-top: 1px solid #94a3b8;
           text-align: center; font-size: 8pt; color: #555; padding-top: 4pt; }
    p { margin: 0 0 7pt 0; text-align: justify; }
    .fecha { margin-bottom: 14pt; text-align: left; }
    .destinatario p { margin: 0; text-align: left; }
    .destinatario { margin-bottom: 14pt; }
    .titulo { text-align: center; font-weight: bold; font-size: 12pt; margin: 14pt 0 26pt; }
    .asunto { margin-bottom: 12pt; text-align: left; }
    ul { margin: 0 0 9pt 0; padding-left: 18pt; }
    li { margin-bottom: 4pt; text-align: justify; }
    .despedida { margin-top: 12pt; }
    .cierre-firma { page-break-inside: avoid; }
    .firma { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    .firma td { vertical-align: bottom; padding: 0; }
    .firma .img-firma { height: 1.7cm; }
    .firma .espacio { height: 1.7cm; }
    .firma p { margin: 0; text-align: left; }
    .firma .sello { text-align: center; vertical-align: middle; }
    .firma .sello img { max-height: 2.6cm; max-width: 5.5cm; }
</style>
</head>
<body>

<div id="membrete">
    <table>
        <tr>
            <td style="width:45%">
                @if($img['logo'])<img src="{{ $img['logo'] }}">@endif
            </td>
            <td class="empresa">
                <div class="nombre">{{ $empresa['nombre'] }}</div>
                <div class="nit">NIT: {{ $empresa['nit_formateado'] }}</div>
            </td>
        </tr>
    </table>
</div>

<div id="pie">
    @foreach(app(\App\Services\CartaLaboralService::class)->lineasPie($empresa) as $linea)
        <div>{{ $linea }}</div>
    @endforeach
</div>

<p class="fecha">{{ $c['ciudad_fecha'] }}</p>

@if($c['destinatario'])
<div class="destinatario">
    @foreach($c['destinatario'] as $linea)
        <p>{!! $negrilla($linea) !!}</p>
    @endforeach
</div>
@endif

@if($c['titulo'])
<div class="titulo">{{ $c['titulo'] }}</div>
@endif

@if($c['asunto'])
<p class="asunto">{!! $negrilla($c['asunto']) !!}</p>
@endif

@if($c['saludo'])
<p>{{ $c['saludo'] }}</p>
@endif

@foreach($c['parrafos'] as $parrafo)
<p>{!! $negrilla($parrafo) !!}</p>
@endforeach

@if($c['condiciones'])
<p>{{ $c['condiciones_intro'] }}</p>
<ul>
    @foreach($c['condiciones'] as [$etiqueta, $texto])
        <li><strong>{{ $etiqueta }}:</strong> {{ $texto }}</li>
    @endforeach
</ul>
@endif

<p>{{ $c['cierre'] }}</p>

<div class="cierre-firma">
<p class="despedida">{{ $c['despedida'] }}</p>

<table class="firma">
    <tr>
        <td style="width:58%">
            @if($img['firma'])
                <img class="img-firma" src="{{ $img['firma'] }}">
            @else
                <div class="espacio"></div>
            @endif
            <p>_______________________________</p>
            <p><strong>{{ $c['firmante']['nombre'] }}</strong></p>
            <p>{{ $c['firmante']['tipo_doc'] }} {{ $c['firmante']['documento_formateado'] }}</p>
            @if($c['firmante']['cargo'])<p>{{ $c['firmante']['cargo'] }}</p>@endif
            <p>{{ $c['firmante']['empresa'] }}</p>
        </td>
        <td class="sello">
            @if($img['sello'])<img src="{{ $img['sello'] }}">@endif
        </td>
    </tr>
</table>
</div>

</body>
</html>
