{{--
    Certificación laboral «A quien interese» (CartaLaboralService::pdf). Membrete
    centrado, el logo de fondo como marca de agua y CERTIFICA: con sus «Que…».
    El texto llega armado en $c; el Word sale del mismo $c.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 3.9cm 2.5cm 2.6cm 2.5cm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10.5pt; line-height: 1.5; color: #111; }
    #marca { position: fixed; top: 4.2cm; left: 0; right: 0; text-align: center; }
    #marca img { width: 11.5cm; }
    #membrete { position: fixed; top: -3.2cm; left: 0; right: 0; height: 2.9cm; text-align: center; }
    #membrete img { max-height: 1.25cm; max-width: 5cm; margin-bottom: 3pt; }
    #membrete .nombre { font-size: 17pt; font-weight: bold; color: #1f3b64; letter-spacing: .5pt; }
    #membrete .nit { font-size: 10pt; font-weight: bold; color: #222; margin-top: 1pt; }
    #membrete .raya { margin-top: 5pt; border-top: 2px solid #1f3b64; border-bottom: .8px solid #1f3b64; height: 2px; }
    #pie { position: fixed; bottom: -2cm; left: 0; right: 0; height: 1.5cm; border-top: 1px solid #94a3b8;
           text-align: center; font-size: 8.5pt; color: #444; padding-top: 4pt; line-height: 1.35; }
    p { margin: 0 0 10pt 0; text-align: justify; }
    .preambulo { text-align: center; margin: 10pt 0 6pt; }
    .titulo { text-align: center; font-weight: bold; font-size: 15pt; letter-spacing: 2pt; color: #1f3b64; margin: 4pt 0 18pt; }
    .cierre { margin-top: 4pt; }
    .despedida { margin-top: 18pt; }
    .cierre-firma { page-break-inside: avoid; }
    .firma { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    .firma td { vertical-align: bottom; padding: 0; }
    .firma .img-firma { height: 1.8cm; }
    .firma .img-sello-firma { max-height: 2.8cm; max-width: 6cm; margin-bottom: -.35cm; }
    .firma .espacio { height: 1.8cm; }
    .firma p { margin: 0; text-align: left; line-height: 1.3; }
    .firma .sello { text-align: center; vertical-align: middle; }
    .firma .sello img { max-height: 2.6cm; max-width: 5.5cm; }
</style>
</head>
<body>

@if($img['marca'])
<div id="marca"><img src="{{ $img['marca'] }}"></div>
@endif

<div id="membrete">
    @if($img['logo'])<img src="{{ $img['logo'] }}"><br>@endif
    <div class="nombre">{{ $empresa['nombre'] }}</div>
    <div class="nit">NIT. {{ $empresa['nit_formateado'] }}</div>
    <div class="raya"></div>
</div>

<div id="pie">
    @foreach(app(\App\Services\CartaLaboralService::class)->lineasPie($empresa) as $linea)
        <div>{{ $linea }}</div>
    @endforeach
</div>

<p class="preambulo">{!! $negrilla($c['preambulo']) !!}</p>

<div class="titulo">{{ $c['titulo'] }}</div>

@foreach($c['parrafos'] as $parrafo)
<p>{!! $negrilla($parrafo) !!}</p>
@endforeach

<p class="cierre">{{ $c['cierre'] }}</p>

<div class="cierre-firma">
<p class="despedida">{{ $c['despedida'] }}</p>

<table class="firma">
    <tr>
        <td style="width:58%">
            @if($img['firma'])
                <img class="{{ $img['firma_es_sello'] ? 'img-sello-firma' : 'img-firma' }}" src="{{ $img['firma'] }}">
            @else
                <div class="espacio"></div>
            @endif
            @foreach(app(\App\Services\CartaLaboralService::class)->lineasFirma($c['firmante']) as [$linea, $enNegrilla])
                <p>@if($enNegrilla)<strong>{{ $linea }}</strong>@else{{ $linea }}@endif</p>
            @endforeach
        </td>
        <td class="sello">
            @if($img['sello'])<img src="{{ $img['sello'] }}">@endif
        </td>
    </tr>
</table>
</div>

</body>
</html>
