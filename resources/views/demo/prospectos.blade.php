@extends('layouts.app')
@section('modulo', 'Prospectos de la demo')

@section('contenido')
<div style="max-width:1200px;margin:0 auto;padding:1rem;">
    <h2 style="margin:0 0 .3rem;font-size:1.2rem;">🧪 Quiénes probaron la demo</h2>
    <p style="margin:0 0 1rem;color:#64748b;font-size:.85rem;">
        Los que se registraron en <strong>demo.brynex.co</strong>. «WhatsApp verificado» es el número que de verdad
        le escribió a Info Avapp con su código; su conversación está en la bandeja de BryNex.
    </p>
    <div style="overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
        <thead>
            <tr style="background:#f8fafc;text-align:left;color:#475569;">
                <th style="padding:.5rem .6rem;">Fecha</th>
                <th style="padding:.5rem .6rem;">Nombre</th>
                <th style="padding:.5rem .6rem;">Empresa</th>
                <th style="padding:.5rem .6rem;">Celular escrito</th>
                <th style="padding:.5rem .6rem;">WhatsApp verificado</th>
                <th style="padding:.5rem .6rem;">Nombre en WhatsApp</th>
                <th style="padding:.5rem .6rem;">Envíos</th>
                <th style="padding:.5rem .6rem;">Último uso</th>
                <th style="padding:.5rem .6rem;">Contactar</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($prospectos as $p)
            @php
                $digitos = preg_replace('/\D/', '', $p->celular);
                $numero = $p->telefono_whatsapp ?: (strlen($digitos) === 10 ? '57'.$digitos : $digitos);
            @endphp
            <tr style="border-top:1px solid #f1f5f9;">
                <td style="padding:.45rem .6rem;white-space:nowrap;">{{ $p->created_at?->format('d/m/Y H:i') }}</td>
                <td style="padding:.45rem .6rem;font-weight:600;">{{ $p->nombre }}</td>
                <td style="padding:.45rem .6rem;">{{ $p->empresa ?: '—' }}</td>
                <td style="padding:.45rem .6rem;">{{ $p->celular }}</td>
                <td style="padding:.45rem .6rem;">
                    @if ($p->verificado_at)
                        <span style="color:#15803d;font-weight:700;">✓ {{ $p->telefono_whatsapp }}</span>
                    @else
                        <span style="color:#94a3b8;">sin verificar</span>
                    @endif
                </td>
                <td style="padding:.45rem .6rem;">{{ $p->nombre_whatsapp ?: '—' }}</td>
                <td style="padding:.45rem .6rem;">{{ $p->envios }}</td>
                <td style="padding:.45rem .6rem;white-space:nowrap;">{{ $p->ultimo_uso_at?->format('d/m/Y H:i') }}</td>
                <td style="padding:.45rem .6rem;">
                    @if ($p->acepto_contacto)
                        <a href="https://wa.me/{{ $numero }}" target="_blank" rel="noopener" style="color:#16a34a;font-weight:700;text-decoration:none;">WhatsApp</a>
                    @else
                        <span style="color:#94a3b8;" title="No autorizó que lo contacten">no autorizó</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" style="padding:1.2rem;text-align:center;color:#94a3b8;">Todavía nadie ha entrado a la demo.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
