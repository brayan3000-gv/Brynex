@extends('layouts.app')
@section('titulo', 'WhatsApp')
@section('modulo', 'Chat WhatsApp')

@push('styles')
<style>
/* ── Layout chat tipo WhatsApp ───────────────────────── */
.chat-layout {
    display: flex;
    height: calc(100vh - 120px);
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 8px rgba(0,0,0,.1);
    overflow: hidden;
}

/* ── Panel izquierdo: lista de conversaciones ────────── */
.chat-sidebar {
    width: 310px;
    min-width: 280px;
    border-right: 1px solid #f1f5f9;
    display: flex;
    flex-direction: column;
    background: #fff;
}

.sidebar-header {
    padding: .85rem 1rem;
    border-bottom: 1px solid #f1f5f9;
}

.sidebar-title {
    font-size: .95rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .65rem;
}

.sidebar-badge {
    background: #ef4444;
    color: #fff;
    font-size: .65rem;
    font-weight: 700;
    padding: .12rem .45rem;
    border-radius: 999px;
    min-width: 18px;
    text-align: center;
}

.sidebar-search {
    width: 100%;
    padding: .45rem .75rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: .82rem;
    color: #0f172a;
    outline: none;
    transition: border-color .15s;
    margin-bottom: .5rem;
}
.sidebar-search:focus { border-color: #2563eb; }

.sidebar-tabs {
    display: flex;
    gap: .25rem;
}

.sidebar-tab {
    flex: 1;
    text-align: center;
    padding: .35rem;
    border-radius: 7px;
    font-size: .74rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    color: #64748b;
    transition: background .12s, color .12s;
}
.sidebar-tab.active { background: #eff6ff; color: #2563eb; }
.sidebar-tab:hover { background: #f8fafc; }

/* ── Filtro por tipo de contacto ─────────────────────── */
.sidebar-tipos {
    display: flex;
    flex-wrap: wrap;
    gap: .2rem;
    margin-top: .45rem;
}

.tipo-filtro {
    font-size: .64rem;
    font-weight: 600;
    padding: .18rem .42rem;
    border-radius: 999px;
    text-decoration: none;
    color: #64748b;
    background: #f1f5f9;
    border: 1px solid transparent;
    transition: background .12s, color .12s;
}
.tipo-filtro:hover { background: #e2e8f0; }
.tipo-filtro.active { border-color: currentColor; }
.tipo-filtro.t-cliente.active   { background: #dcfce7; color: #15803d; }
.tipo-filtro.t-excliente.active { background: #fef3c7; color: #b45309; }
.tipo-filtro.t-nuevo.active     { background: #ede9fe; color: #6d28d9; }
.tipo-filtro.t-todos.active     { background: #e0e7ff; color: #4338ca; }

/* ── Chip de tipo en cada conversación ───────────────── */
.tipo-chip {
    display: inline-block;
    font-size: .6rem;
    font-weight: 700;
    line-height: 1;
    padding: .15rem .35rem;
    border-radius: 4px;
    flex-shrink: 0;
    white-space: nowrap;
}
.tipo-chip.t-cliente   { background: #dcfce7; color: #15803d; }
.tipo-chip.t-excliente { background: #fef3c7; color: #b45309; }
.tipo-chip.t-nuevo     { background: #ede9fe; color: #6d28d9; }

.conv-name-row { display: flex; align-items: center; gap: .35rem; min-width: 0; }
.conv-name-row .conv-name { flex: 1; min-width: 0; }

.conv-list { flex: 1; overflow-y: auto; }
.conv-list::-webkit-scrollbar { width: 4px; }
.conv-list::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 999px; }

.conv-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .75rem 1rem;
    cursor: pointer;
    text-decoration: none;
    border-bottom: 1px solid #f8fafc;
    transition: background .1s;
}
.conv-item:hover { background: #f8fafc; }
.conv-item.activa { background: #eff6ff; border-right: 3px solid #2563eb; }

.conv-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: #fff;
    font-weight: 700;
    flex-shrink: 0;
}

.conv-info { flex: 1; min-width: 0; }
.conv-name {
    font-size: .83rem;
    font-weight: 600;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.conv-preview {
    font-size: .74rem;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: .1rem;
}

.conv-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: .25rem;
    flex-shrink: 0;
}
.conv-time { font-size: .68rem; color: #cbd5e1; }

/* ── Chip de espera: lleva X esperando, y cuánto queda de ventana ── */
.conv-espera {
    font-size: .58rem;
    font-weight: 700;
    line-height: 1;
    padding: .18rem .35rem;
    border-radius: 4px;
    white-space: nowrap;
    background: #f1f5f9;
    color: #475569;
}
.conv-espera.urgente { background: #fef3c7; color: #b45309; }
.conv-espera.vencida { background: #fee2e2; color: #b91c1c; }

.conv-unread {
    background: #ef4444;
    color: #fff;
    font-size: .58rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 999px;
    min-width: 15px;
    height: 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

/* ── Panel derecho: bienvenida ───────────────────────── */
.chat-main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fafc;
}

.welcome-box {
    text-align: center;
    color: #94a3b8;
}
.welcome-box .wa-icon { font-size: 4rem; margin-bottom: 1rem; }
.welcome-box h3 { font-size: 1.1rem; font-weight: 600; color: #475569; margin-bottom: .5rem; }
.welcome-box p { font-size: .83rem; }
</style>
@endpush

@section('contenido')
<div style="padding:1rem 1.5rem 0">
    @if(session('ok'))
        <div class="flash success" style="margin-bottom:.75rem">✅ {{ session('ok') }}</div>
    @endif
</div>

<div style="padding:0 1.5rem 1.5rem">
<div class="chat-layout">

    {{-- Panel izquierdo --}}
    <aside class="chat-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-title">
                💬 WhatsApp
                @if($totalNoLeidos > 0)
                    <span class="sidebar-badge">{{ $totalNoLeidos }}</span>
                @endif
            </div>

            <form method="GET">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="tipo" value="{{ $tipo }}">
                <input type="text" name="buscar" class="sidebar-search"
                       value="{{ $buscar }}" placeholder="Buscar conversación...">
            </form>

            <div class="sidebar-tabs">
                <a href="{{ route('admin.whatsapp.chat.index', ['tab' => 'general', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'general' ? 'active' : '' }}">📥 General</a>
                <a href="{{ route('admin.whatsapp.chat.index', ['tab' => 'esperando', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'esperando' ? 'active' : '' }}"
                   title="Escribieron y nadie les ha respondido">⏳ Esperando
                    @if($totalEsperando > 0)<span class="sidebar-badge" style="margin-left:.25rem;background:#d97706">{{ $totalEsperando }}</span>@endif
                </a>
                <a href="{{ route('admin.whatsapp.chat.index', ['tab' => 'mias', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'mias' ? 'active' : '' }}">👤 Míos</a>
                <a href="{{ route('admin.whatsapp.chat.index', ['tab' => 'ia', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'ia' ? 'active' : '' }}">🤖 IA
                    @if($totalIa > 0)<span class="sidebar-badge" style="margin-left:.25rem;">{{ $totalIa }}</span>@endif
                </a>
            </div>

            {{-- Filtro por tipo de contacto: cliente / excliente / nuevo --}}
            <div class="sidebar-tipos">
                <a href="{{ route('admin.whatsapp.chat.index', ['tab' => $tab, 'buscar' => $buscar]) }}"
                   class="tipo-filtro t-todos {{ $tipo ? '' : 'active' }}">Todos</a>
                @foreach(\App\Services\WhatsappTipoContacto::ETIQUETAS as $clave => $etiqueta)
                    <a href="{{ route('admin.whatsapp.chat.index', ['tab' => $tab, 'buscar' => $buscar, 'tipo' => $clave]) }}"
                       class="tipo-filtro t-{{ $clave }} {{ $tipo === $clave ? 'active' : '' }}">
                        {{ $etiqueta }} {{ $conteoTipos[$clave] ?? 0 }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="conv-list">
            @forelse($conversaciones as $conv)
                @php
                    $ultimoMsg = $conv->mensajes->first();
                    $inicial   = mb_strtoupper(mb_substr($conv->nombreMostrar(), 0, 1));
                    $preview   = $conv->previewUltimoMensaje();
                    $hora      = $conv->ultimo_mensaje_at
                        ? ($conv->ultimo_mensaje_at->isToday()
                            ? $conv->ultimo_mensaje_at->format('H:i')
                            : $conv->ultimo_mensaje_at->format('d/m'))
                        : '';
                @endphp
                <a href="{{ route('admin.whatsapp.chat.show', ['id' => $conv->id, 'tab' => $tab, 'buscar' => $buscar, 'tipo' => $tipo]) }}" class="conv-item">
                    <div class="conv-avatar">{{ $inicial }}</div>
                    <div class="conv-info">
                        <div class="conv-name-row">
                            <span class="conv-name">{{ $conv->nombreMostrar() }}</span>
                            @if($conv->tipo_contacto)
                                <span class="tipo-chip t-{{ $conv->tipo_contacto }}"
                                      title="{{ $conv->desde_marketing ? 'Llegó por marketing: ' . ($conv->origen_campana ?: 'campaña o pieza publicada') : '' }}">
                                    {{ $conv->desde_marketing ? '📣 ' : '' }}{{ \App\Services\WhatsappTipoContacto::ETIQUETAS[$conv->tipo_contacto] }}
                                </span>
                            @endif
                        </div>
                        <div class="conv-preview">
                            @if($conv->pendiente_atencion)
                                <span style="color:#d97706;font-weight:600">⚠️ Pendiente por atender</span>
                            @elseif($conv->esperando)
                                <span style="color:#b45309">⏳ «{{ \Illuminate\Support\Str::limit($conv->esperando_dijo, 50) }}»</span>
                            @elseif($conv->atendida_por_ia)
                                <span style="color:#2563eb">🤖 Atendiendo la IA</span>
                            @elseif($conv->estado === 'asignada' && $conv->asignado)
                                <span style="color:#10b981">● {{ $conv->asignado->nombre }}</span>
                            @else
                                {{ $preview ?: 'Sin mensajes' }}
                            @endif
                        </div>
                    </div>
                    <div class="conv-meta">
                        <span class="conv-time">{{ $hora }}</span>
                        @if($conv->total_mensajes_no_leidos > 0)
                            <span class="conv-unread">{{ $conv->total_mensajes_no_leidos }}</span>
                        @endif
                        @if($conv->esperando && $conv->esperando_desde)
                            @php
                                $ventanaMin = $conv->minutosVentanaRestante();
                                $claseEspera = !$conv->ventanaActiva() ? 'vencida' : ($ventanaMin <= 180 ? 'urgente' : '');
                                $textoVentana = !$conv->ventanaActiva() ? 'ventana vencida'
                                    : ($ventanaMin < 60 ? "vence en {$ventanaMin} min" : 'vence en ' . intdiv($ventanaMin, 60) . ' h');
                            @endphp
                            <span class="conv-espera {{ $claseEspera }}"
                                  title="Esperando respuesta desde hace {{ \App\Services\WhatsappEsperandoRespuesta::hace($conv->esperando_desde) }} · {{ $textoVentana }}">
                                ⏳ {{ \App\Services\WhatsappEsperandoRespuesta::hace($conv->esperando_desde) }}{{ !$conv->ventanaActiva() ? ' · ⛔' : ($ventanaMin <= 180 ? ' · ' . $textoVentana : '') }}
                            </span>
                        @endif
                    </div>
                </a>
            @empty
                <div style="text-align:center;padding:2rem 1rem;color:#94a3b8;font-size:.82rem">
                    @if($tipo)
                        No hay conversaciones de tipo «{{ \App\Services\WhatsappTipoContacto::ETIQUETAS[$tipo] ?? $tipo }}» en esta pestaña.
                    @elseif($tab === 'mias')
                        No hay conversaciones asignadas a ti.
                    @elseif($tab === 'esperando')
                        Nadie está esperando respuesta. 🎉
                    @elseif($tab === 'ia')
                        No hay conversaciones que la IA esté atendiendo ahora mismo.
                    @else
                        No hay conversaciones activas.
                    @endif
                </div>
            @endforelse
        </div>
    </aside>

    {{-- Panel derecho --}}
    <main class="chat-main">
        <div class="welcome-box">
            <div class="wa-icon">💬</div>
            <h3>Chat de WhatsApp</h3>
            <p>Selecciona una conversación del panel izquierdo para comenzar.</p>
            @if($totalNoLeidos > 0)
                <p style="margin-top:.5rem;color:#ef4444;font-weight:600">
                    {{ $totalNoLeidos }} mensaje(s) sin leer
                </p>
            @endif
        </div>
    </main>

</div>
</div>
@endsection
