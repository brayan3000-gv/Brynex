@extends('layouts.app')
@section('modulo', 'Afiliaciones')

@push('styles')
<style>
.buz-wrap { padding:.2rem 0 1rem }
.buz-head { background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);padding:.8rem 1.2rem;border-radius:12px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem }
.buz-head h1 { font-size:1.05rem;margin:0 }
.buz-head a { color:#bfdbfe;font-size:.8rem;text-decoration:none }
.buz-tabs { display:flex;gap:.4rem;flex-wrap:wrap;margin:.8rem 0 }
.buz-tabs a { font-size:.76rem;padding:.3rem .7rem;border-radius:999px;border:1px solid #cbd5e1;color:#334155;text-decoration:none;background:#fff }
.buz-tabs a.activo { background:#1e3a5f;color:#fff;border-color:#1e3a5f }
.buz-card { background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.8rem 1rem;margin-bottom:.9rem }
.buz-card h2 { font-size:.9rem;margin:0 0 .6rem;color:#0f172a }
.buz-tabla { width:100%;border-collapse:collapse;font-size:.76rem }
.buz-tabla th { text-align:left;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;padding:.35rem .4rem;white-space:nowrap }
.buz-tabla td { border-bottom:1px solid #f1f5f9;padding:.45rem .4rem;vertical-align:top }
.buz-tabla-scroll { overflow-x:auto }
.buz-texto { color:#475569;max-width:420px }
.buz-pill { display:inline-block;font-size:.68rem;padding:.05rem .45rem;border-radius:999px;background:#f1f5f9;color:#334155;white-space:nowrap }
.buz-pill.por_revisar { background:#fef3c7;color:#92400e } .buz-pill.aplicado { background:#dcfce7;color:#166534 }
.buz-pill.informativo { background:#e0e7ff;color:#3730a3 } .buz-pill.vencido { background:#fee2e2;color:#991b1b }
.buz-btn { font-size:.7rem;padding:.2rem .5rem;border-radius:6px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;color:#334155 }
.buz-vacio { color:#94a3b8;font-size:.78rem;padding:.6rem 0 }
</style>
@endpush

@section('contenido')
<div class="buz-wrap">
    <div class="buz-head">
        <h1>📬 Buzón de afiliaciones{{ $gestionados ? ' · todos los aliados gestionados' : '' }}</h1>
        <div style="display:flex;gap:.9rem;align-items:center">
            @if (auth()->user()?->es_brynex)
                <a href="{{ route('admin.afiliaciones.buzon', ['estado' => $estado] + ($gestionados ? [] : ['gestionados' => 1])) }}"
                   title="{{ $gestionados ? 'Ver solo el aliado activo' : 'Ver los correos de todos los aliados que gestiona BryNex' }}">🏢 {{ $gestionados ? 'Solo el aliado activo' : 'Todos los aliados' }}</a>
            @endif
            <a href="{{ route('admin.afiliaciones.index', $gestionados ? ['gestionados' => 1] : []) }}">← Volver a Afiliaciones</a>
        </div>
    </div>

    @if (session('success'))
        <div class="flash success" style="margin-top:.6rem">✅ {{ session('success') }}</div>
    @endif

    <div class="buz-card" style="margin-top:.8rem">
        <h2>⏳ Correos enviados esperando respuesta ({{ $esperando->count() }})</h2>
        @if ($esperando->isEmpty())
            <div class="buz-vacio">No hay correos esperando respuesta.</div>
        @else
            <div class="buz-tabla-scroll"><table class="buz-tabla">
                <thead><tr><th>Trabajador</th><th>Para</th><th>Enviado</th><th>Plazo</th><th>Estado</th><th>Respuesta</th><th></th></tr></thead>
                <tbody>
                @foreach ($esperando as $c)
                    @php $vencido = $c->estado === 'enviado' && $c->vence_at && $c->vence_at->isPast(); @endphp
                    <tr>
                        <td>{{ nombre_oracion(trim(($c->contrato?->cliente?->primer_nombre ?? '').' '.($c->contrato?->cliente?->primer_apellido ?? '')) ?: 'Contrato '.$c->contrato_id) }}<br><span style="color:#94a3b8">CC {{ $c->contrato?->cedula }}</span>@if ($gestionados) <span class="buz-pill">{{ $nombresAliado[$c->aliado_id] ?? 'Aliado '.$c->aliado_id }}</span>@endif</td>
                        <td>{{ $c->para }}</td>
                        <td style="white-space:nowrap">{{ $c->enviado_at?->format('d/m/Y H:i') }}</td>
                        <td style="white-space:nowrap">{{ $c->vence_at?->format('d/m H:i') }}</td>
                        <td><span class="buz-pill {{ $vencido ? 'vencido' : '' }}">{{ $vencido ? 'vencido' : $c->estado }}</span></td>
                        <td class="buz-texto">{{ \Illuminate\Support\Str::limit($c->respuesta_resumen, 160) }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.afiliaciones.buzon.cerrar-enviado', $c->id) }}" onsubmit="return confirm('¿Cerrar el seguimiento de este correo? El agente dejará de esperar respuesta.')">
                                @csrf <button class="buz-btn">Cerrar</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <div class="buz-tabs">
        @foreach (['por_revisar' => 'Por revisar', 'aplicado' => 'Aplicados', 'informativo' => 'Informativos', 'revisado' => 'Revisados', 'ignorado' => 'Ignorados', 'todos' => 'Todos'] as $clave => $texto)
            <a href="{{ route('admin.afiliaciones.buzon', ['estado' => $clave] + ($gestionados ? ['gestionados' => 1] : [])) }}" class="{{ $estado === $clave ? 'activo' : '' }}">
                {{ $texto }}{{ $clave !== 'todos' && ($conteos[$clave] ?? 0) ? ' ('.$conteos[$clave].')' : '' }}
            </a>
        @endforeach
    </div>

    <div class="buz-card">
        <h2>📥 Correos recibidos de entidades</h2>
        @if ($recibidos->isEmpty())
            <div class="buz-vacio">No hay correos en esta vista.</div>
        @else
            <div class="buz-tabla-scroll"><table class="buz-tabla">
                <thead><tr><th>Recibido</th><th>De</th><th>Asunto y texto</th><th>Trabajador</th><th>Qué hizo el agente</th><th>Adjuntos</th><th></th></tr></thead>
                <tbody>
                @foreach ($recibidos as $r)
                    <tr>
                        <td style="white-space:nowrap">{{ $r->recibido_at?->format('d/m/Y H:i') }}<br><span class="buz-pill {{ $r->estado }}">{{ str_replace('_', ' ', $r->estado) }}</span></td>
                        <td>{{ $r->de_nombre ?: $r->de }}<br><span style="color:#94a3b8">{{ $r->de }}</span></td>
                        <td class="buz-texto"><strong>{{ $r->asunto }}</strong><br>{{ \Illuminate\Support\Str::limit($r->texto, 220) }}</td>
                        <td>
                            @if ($r->contrato)
                                {{ nombre_oracion(trim(($r->contrato->cliente?->primer_nombre ?? '').' '.($r->contrato->cliente?->primer_apellido ?? ''))) }}<br><span style="color:#94a3b8">CC {{ $r->contrato->cedula }}</span>@if ($gestionados) <span class="buz-pill">{{ $nombresAliado[$r->aliado_id] ?? 'Aliado '.$r->aliado_id }}</span>@endif
                            @else
                                <span style="color:#94a3b8">—</span>
                            @endif
                        </td>
                        <td class="buz-texto">{{ $r->accion }}</td>
                        <td>
                            @foreach ($r->adjuntos ?? [] as $i => $a)
                                <a href="{{ route('admin.afiliaciones.buzon.adjunto', [$r->id, $i]) }}" target="_blank" style="display:block;white-space:nowrap">📎 {{ \Illuminate\Support\Str::limit($a['nombre'], 32) }}</a>
                            @endforeach
                        </td>
                        <td style="white-space:nowrap">
                            @if (in_array($r->estado, ['por_revisar', 'informativo'], true))
                                <form method="POST" action="{{ route('admin.afiliaciones.buzon.marcar', $r->id) }}" style="display:inline">@csrf<input type="hidden" name="estado" value="revisado"><button class="buz-btn">✓ Revisado</button></form>
                                <form method="POST" action="{{ route('admin.afiliaciones.buzon.marcar', $r->id) }}" style="display:inline">@csrf<input type="hidden" name="estado" value="ignorado"><button class="buz-btn">Ignorar</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>
@endsection
