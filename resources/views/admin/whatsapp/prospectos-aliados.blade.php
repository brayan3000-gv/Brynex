@extends('layouts.app')
@section('modulo','Prospectos aliados')

@section('contenido')
@php
    $tipos = \App\Services\ProspectoAliadoService::TIPOS;
    $colorTipo = ['asesor' => ['#ede9fe', '#5b21b6'], 'empresa' => ['#dbeafe', '#1e40af'], 'empleador' => ['#dcfce7', '#166534']];
    // Color de cada estado de seguimiento: [fondo, texto, borde de la tarjeta].
    $colorEstado = [
        'pendiente'     => ['#fff7ed', '#c2410c', '#ea580c'],
        'conversacion'  => ['#eff6ff', '#1d4ed8', '#2563eb'],
        'no_interesado' => ['#f1f5f9', '#475569', '#94a3b8'],
        'software'      => ['#ecfdf5', '#047857', '#10b981'],
        'plan'          => ['#f5f3ff', '#6d28d9', '#7c3aed'],
    ];
    $puedeMarcar = auth()->user()?->can('whatsapp.responder');
    $filtros = array_filter(['tipo' => $tipo, 'desde' => $desde]);
@endphp
<div style="background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

    @include('admin.partials.table-header', [
        'titulo'    => '🤝 Prospectos que quieren trabajar con nosotros',
        'subtitulo' => 'Asesores y empresas que escribieron por WhatsApp: qué dijeron ser, cuántas personas manejan, cómo va cada conversación y en qué quedó.',
    ])

    <form method="GET" action="{{ route('admin.whatsapp.prospectos_aliados') }}" style="background:#f8fafc;padding:1rem 1.25rem;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:1.25rem;display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Tipo</label>
            <select name="tipo" style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;min-width:160px;">
                <option value="">Todos</option>
                @foreach($tipos as $v => $e)
                    <option value="{{ $v }}" {{ $tipo === $v ? 'selected' : '' }}>{{ $e }}</option>
                @endforeach
                <option value="sin_perfil" {{ $tipo === 'sin_perfil' ? 'selected' : '' }}>Sin perfil (no contestó)</option>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Seguimiento</label>
            <select name="estado" style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;min-width:170px;">
                <option value="">Todos</option>
                @foreach($estados as $v => $e)
                    <option value="{{ $v }}" {{ $estado === $v ? 'selected' : '' }}>{{ $e }}</option>
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

    {{-- Una tarjeta por estado: al hacer clic filtra la tabla por ese estado. --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.85rem;margin-bottom:1rem;">
        <a href="{{ route('admin.whatsapp.prospectos_aliados', $filtros) }}" style="text-decoration:none;background:{{ $estado ? '#f8fafc' : '#f1f5f9' }};padding:0.9rem 1rem;border-radius:10px;border-left:4px solid #0f172a;{{ $estado ? '' : 'box-shadow:0 0 0 2px #0f172a inset;' }}">
            <div style="font-size:0.7rem;font-weight:600;color:#64748b;text-transform:uppercase;">Todos</div>
            <div style="font-size:1.3rem;font-weight:700;color:#0f172a;">{{ $resumen['total'] }}</div>
            <div style="font-size:0.7rem;color:#94a3b8;">{{ $resumen['asesores'] }} asesores · {{ $resumen['empresas'] }} empresas · {{ $resumen['sin_perfil'] }} sin perfil</div>
        </a>
        @foreach($estados as $v => $e)
            @php [$bg, $fg, $borde] = $colorEstado[$v]; @endphp
            <a href="{{ route('admin.whatsapp.prospectos_aliados', $filtros + ['estado' => $v]) }}" style="text-decoration:none;background:{{ $bg }};padding:0.9rem 1rem;border-radius:10px;border-left:4px solid {{ $borde }};{{ $estado === $v ? 'box-shadow:0 0 0 2px '.$borde.' inset;' : '' }}">
                <div style="font-size:0.7rem;font-weight:600;color:#64748b;text-transform:uppercase;">{{ $e }}</div>
                <div style="font-size:1.3rem;font-weight:700;color:{{ $fg }};">{{ $resumen['por_estado'][$v] ?? 0 }}</div>
            </a>
        @endforeach
    </div>
    <div style="font-size:0.8rem;color:#64748b;margin-bottom:1.25rem;">
        {{ number_format($resumen['personas'], 0, ',', '.') }} personas sumando lo que cada uno dijo manejar
        @if($resumen['sin_humano']) · <span style="color:#c2410c;font-weight:600;">{{ $resumen['sin_humano'] }} en esta lista solo han hablado con la IA</span> @endif
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Fecha</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Contacto</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Tipo</th>
                    <th style="padding:0.7rem 0.9rem;text-align:right;color:#475569;font-weight:600;">Personas</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Lo atiende</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;">Chat</th>
                    <th style="padding:0.7rem 0.9rem;text-align:left;color:#475569;font-weight:600;min-width:260px;">Seguimiento</th>
                </tr>
            </thead>
            <tbody>
                @forelse($prospectos as $p)
                    @php
                        [$bg, $fg] = $colorTipo[$p->perfil_aliado] ?? ['#f1f5f9', '#475569'];
                        $est = $p->prospecto_estado ?: 'pendiente';
                        $fecha = $p->perfil_aliado_at ?? $p->created_at;
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;vertical-align:top;"
                        x-data="prospectoEstado({{ $p->id }}, @js($est), @js($p->prospecto_nota ?? ''), @js($p->prospecto_estado_at ? 'Marcado el '.$p->prospecto_estado_at->format('d/m/Y H:i') : ''))">
                        <td style="padding:0.65rem 0.9rem;white-space:nowrap;color:#475569;">{{ $fecha?->format('d/m/Y') }}<div style="font-size:0.7rem;color:#94a3b8;">{{ $fecha?->format('H:i') }}</div></td>
                        <td style="padding:0.65rem 0.9rem;">
                            <a href="{{ route('admin.whatsapp.chat.show', $p->id) }}" style="font-weight:600;color:#2563eb;text-decoration:none;">{{ $p->nombreMostrar() }}</a>
                            <div style="font-size:0.75rem;color:#64748b;">{{ $p->wa_contact_id }}</div>
                            @if($p->perfil_sugerencia)<div style="font-size:0.72rem;color:#94a3b8;margin-top:0.15rem;">IA: {{ $p->perfil_sugerencia }}</div>@endif
                        </td>
                        <td style="padding:0.65rem 0.9rem;">
                            @if($p->perfil_aliado)
                                <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:999px;font-size:0.75rem;font-weight:600;background:{{ $bg }};color:{{ $fg }};">{{ $tipos[$p->perfil_aliado] ?? $p->perfil_aliado }}</span>
                            @else
                                <span style="font-size:0.75rem;color:#94a3b8;">Sin perfil</span>
                            @endif
                        </td>
                        <td style="padding:0.65rem 0.9rem;text-align:right;font-weight:700;color:#0f172a;font-variant-numeric:tabular-nums;">{{ $p->personas_declaradas ?? '—' }}</td>
                        <td style="padding:0.65rem 0.9rem;color:#334155;">{{ $p->asignado?->nombre ?? ($p->perfil_avisado_a ? $p->perfil_avisado_a.' (avisada)' : '—') }}</td>
                        <td style="padding:0.65rem 0.9rem;white-space:nowrap;font-size:0.8rem;">
                            @if($p->sin_respuesta_humana)
                                <span style="color:#c2410c;font-weight:600;">Solo la IA</span>
                            @elseif($p->primer_humano_min !== null)
                                <span style="color:#475569;">1.ª respuesta:
                                    @if($p->primer_humano_min < 60) {{ $p->primer_humano_min }} min
                                    @else {{ floor($p->primer_humano_min / 60) }} h {{ $p->primer_humano_min % 60 }} min @endif
                                </span>
                            @endif
                            @if($p->esperando_a_nosotros)
                                <div style="color:#b45309;font-weight:600;">⏳ Espera respuesta</div>
                            @elseif($p->ultimo_cliente_at)
                                <div style="color:#94a3b8;font-size:0.72rem;">Escribió: {{ \Carbon\Carbon::parse($p->ultimo_cliente_at)->format('d/m H:i') }}</div>
                            @endif
                            <a href="{{ route('admin.whatsapp.chat.show', $p->id) }}" style="display:inline-block;margin-top:0.2rem;font-size:0.78rem;font-weight:600;color:#2563eb;text-decoration:none;">Abrir chat →</a>
                        </td>
                        <td style="padding:0.65rem 0.9rem;">
                            @if($puedeMarcar)
                                <select x-model="estado" @change="guardar()"
                                        :style="'padding:0.35rem 0.6rem;border-radius:6px;font-size:0.82rem;font-weight:600;border:1px solid #cbd5e1;width:100%;background:' + colores[estado][0] + ';color:' + colores[estado][1]">
                                    @foreach($estados as $v => $e)
                                        <option value="{{ $v }}">{{ $e }}</option>
                                    @endforeach
                                </select>
                                <input type="text" x-model="nota" @change="guardar()" @keydown.enter.prevent="$event.target.blur()" maxlength="500"
                                       placeholder="Nota (opcional)"
                                       style="margin-top:0.35rem;width:100%;padding:0.35rem 0.6rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.8rem;">
                                <div style="font-size:0.7rem;margin-top:0.2rem;min-height:1em;"
                                     :style="error ? 'color:#dc2626' : 'color:#94a3b8'"
                                     x-text="guardando ? 'Guardando…' : (error || marcado)"></div>
                            @else
                                @php [$ebg, $efg] = $colorEstado[$est]; @endphp
                                <span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:6px;font-size:0.8rem;font-weight:600;background:{{ $ebg }};color:{{ $efg }};">{{ $estados[$est] }}</span>
                                @if($p->prospecto_nota)<div style="font-size:0.78rem;color:#475569;margin-top:0.25rem;">{{ $p->prospecto_nota }}</div>@endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:2rem;text-align:center;color:#64748b;">No hay prospectos con estos filtros. La IA los marca sola cuando alguien dice que es asesor o tiene empresa; también entran los que llegan por un anuncio para asesores.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p style="font-size:0.75rem;color:#94a3b8;margin-top:1rem;">El seguimiento lo marca quien atiende al prospecto y se guarda solo al cambiarlo; no toca el chat ni la IA. «1.ª respuesta» es cuánto tardó la primera respuesta de una persona (no de la IA) desde el primer mensaje del prospecto.</p>
</div>

<script>
function prospectoEstado(id, estado, nota, marcado) {
    return {
        estado, nota, marcado,
        guardando: false,
        error: '',
        colores: @js(collect($colorEstado)->map(fn ($c) => [$c[0], $c[1]])),
        async guardar() {
            this.guardando = true;
            this.error = '';
            try {
                const r = await fetch(@js(route('admin.whatsapp.prospectos_aliados.estado', 0)).replace(/\/0\/estado$/, '/' + id + '/estado'), {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ estado: this.estado, nota: this.nota }),
                });
                const data = await r.json().catch(() => ({}));
                if (!r.ok || !data.ok) throw new Error(data.message || 'No se pudo guardar');
                this.marcado = data.marcado;
            } catch (e) {
                this.error = '⚠️ ' + e.message;
            } finally {
                this.guardando = false;
            }
        },
    };
}
</script>
@endsection
