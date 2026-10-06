@extends('layouts.app')
@section('modulo','Prospectos aliados')

@section('contenido')
@php
    $tipos = \App\Services\ProspectoAliadoService::TIPOS;
    $colorTipo = ['asesor' => ['#ede9fe', '#5b21b6'], 'empresa' => ['#dbeafe', '#1e40af'], 'empleador' => ['#dcfce7', '#166534']];
@endphp
<div style="background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

    @include('admin.partials.table-header', [
        'titulo'    => '🤝 Prospectos que quieren trabajar con nosotros',
        'subtitulo' => 'Asesores y empresas que escribieron por WhatsApp: qué dijeron ser, cuántas personas manejan, qué les sugirió la IA y cómo va cada conversación.',
    ])

    <form method="GET" action="{{ route('admin.whatsapp.prospectos_aliados') }}" style="background:#f8fafc;padding:1rem 1.25rem;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:1.25rem;display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Tipo</label>
            <select name="tipo" style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;min-width:160px;">
                <option value="">Todos</option>
                @foreach($tipos as $v => $e)
                    <option value="{{ $v }}" {{ $tipo === $v ? 'selected' : '' }}>{{ $e }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Desde</label>
            <input type="date" name="desde" value="{{ $desde }}" style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;">
        </div>
        <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:0.6rem 1.5rem;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;">🔍 Ver</button>
        <a href="{{ route('admin.whatsapp.chat.index') }}" style="margin-left:auto;font-size:0.85rem;font-weight:600;color:#2563eb;text-decoration:none;">← Inbox</a>
    </form>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;">
        <div style="background:#f1f5f9;padding:1rem;border-radius:10px;border-left:4px solid #7c3aed;">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;">Prospectos</div>
            <div style="font-size:1.3rem;font-weight:700;color:#0f172a;">{{ $resumen['total'] }}</div>
            <div style="font-size:0.7rem;color:#94a3b8;">{{ $resumen['asesores'] }} asesores · {{ $resumen['empresas'] }} empresas</div>
        </div>
        <div style="background:#f1f5f9;padding:1rem;border-radius:10px;border-left:4px solid #2563eb;">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;">Personas que dijeron manejar</div>
            <div style="font-size:1.3rem;font-weight:700;color:#0f172a;">{{ number_format($resumen['personas'], 0, ',', '.') }}</div>
            <div style="font-size:0.7rem;color:#94a3b8;">Sumando lo que cada uno contó</div>
        </div>
        <div style="background:{{ $resumen['sin_humano'] ? '#fff7ed' : '#f1f5f9' }};padding:1rem;border-radius:10px;border-left:4px solid {{ $resumen['sin_humano'] ? '#ea580c' : '#16a34a' }};">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;">Sin respuesta humana</div>
            <div style="font-size:1.3rem;font-weight:700;color:{{ $resumen['sin_humano'] ? '#c2410c' : '#166534' }};">{{ $resumen['sin_humano'] }}</div>
            <div style="font-size:0.7rem;color:#94a3b8;">Solo les ha escrito la IA</div>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Fecha</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Contacto</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Tipo</th>
                    <th style="padding:0.7rem 0.9rem;text-align:right;color:#475569;font-weight:600;">Personas</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Sugerencia de la IA</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Lo atiende</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Respuesta humana</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Estado</th>
                    <th style="padding:0.7rem 0.9rem;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($prospectos as $p)
                    @php [$bg, $fg] = $colorTipo[$p->perfil_aliado] ?? ['#f1f5f9', '#475569']; @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.65rem 0.9rem;white-space:nowrap;color:#475569;">{{ $p->perfil_aliado_at?->format('d/m/Y') }}<div style="font-size:0.7rem;color:#94a3b8;">{{ $p->perfil_aliado_at?->format('H:i') }}</div></td>
                        <td style="padding:0.65rem 0.9rem;">
                            <a href="{{ route('admin.whatsapp.chat.show', $p->id) }}" style="font-weight:600;color:#2563eb;text-decoration:none;">{{ $p->nombreMostrar() }}</a>
                            <div style="font-size:0.75rem;color:#64748b;">{{ $p->wa_contact_id }}</div>
                        </td>
                        <td style="padding:0.65rem 0.9rem;"><span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:999px;font-size:0.75rem;font-weight:600;background:{{ $bg }};color:{{ $fg }};">{{ $tipos[$p->perfil_aliado] ?? $p->perfil_aliado }}</span></td>
                        <td style="padding:0.65rem 0.9rem;text-align:right;font-weight:700;color:#0f172a;font-variant-numeric:tabular-nums;">{{ $p->personas_declaradas ?? '—' }}</td>
                        <td style="padding:0.65rem 0.9rem;color:#334155;">{{ $p->perfil_sugerencia ?: '—' }}</td>
                        <td style="padding:0.65rem 0.9rem;color:#334155;">{{ $p->asignado?->nombre ?? ($p->perfil_avisado_a ? $p->perfil_avisado_a.' (avisada)' : '—') }}</td>
                        <td style="padding:0.65rem 0.9rem;white-space:nowrap;">
                            @if($p->sin_respuesta_humana)
                                <span style="color:#c2410c;font-weight:600;">Ninguna todavía</span>
                            @elseif($p->primer_humano_min !== null)
                                <span style="color:{{ $p->primer_humano_min <= 30 ? '#166534' : ($p->primer_humano_min <= 120 ? '#b45309' : '#c2410c') }};font-weight:600;">
                                    @if($p->primer_humano_min < 60) {{ $p->primer_humano_min }} min
                                    @else {{ floor($p->primer_humano_min / 60) }} h {{ $p->primer_humano_min % 60 }} min @endif
                                </span>
                            @endif
                        </td>
                        <td style="padding:0.65rem 0.9rem;white-space:nowrap;font-size:0.8rem;">
                            @if($p->estado === 'cerrada') <span style="color:#64748b;">Cerrada</span>
                            @elseif($p->pendiente_atencion) <span style="color:#d97706;font-weight:600;">⚠️ Pendiente</span>
                            @elseif($p->bot_activo) <span style="color:#2563eb;">🤖 IA</span>
                            @else <span style="color:#10b981;">● Persona</span>
                            @endif
                            @if($p->esperando_a_nosotros) <div style="color:#b45309;font-size:0.72rem;">⏳ Espera respuesta</div> @endif
                        </td>
                        <td style="padding:0.65rem 0.9rem;"><a href="{{ route('admin.whatsapp.chat.show', $p->id) }}" style="font-size:0.8rem;font-weight:600;color:#2563eb;text-decoration:none;">Abrir chat →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="padding:2rem;text-align:center;color:#64748b;">Todavía no hay prospectos marcados. La IA los marca sola cuando alguien dice que es asesor o tiene empresa; también se pueden marcar con <code>whatsapp:perfil-aliado</code>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p style="font-size:0.75rem;color:#94a3b8;margin-top:1rem;">«Respuesta humana» es cuánto tardó la primera respuesta de una persona (no de la IA) desde el primer mensaje del prospecto.</p>
</div>
@endsection
