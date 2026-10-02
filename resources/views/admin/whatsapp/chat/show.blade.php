@extends('layouts.app')
@section('titulo', 'WhatsApp')
@section('modulo', 'Chat — ' . $conversacion->nombreMostrar())

@push('styles')
<style>
.chat-layout { display:flex; height:calc(100vh - 120px); background:#fff; border-radius:12px; box-shadow:0 1px 8px rgba(0,0,0,.1); overflow:hidden; }

/* Sidebar */
.chat-sidebar { width:310px; min-width:280px; border-right:1px solid #f1f5f9; display:flex; flex-direction:column; background:#fff; }
.sidebar-header { padding:.85rem 1rem; border-bottom:1px solid #f1f5f9; }
.sidebar-title { font-size:.95rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:.5rem; margin-bottom:.65rem; }
.sidebar-badge { background:#ef4444; color:#fff; font-size:.65rem; font-weight:700; padding:.12rem .45rem; border-radius:999px; min-width:18px; text-align:center; }
.sidebar-search { width:100%; padding:.45rem .75rem; border:1px solid #e2e8f0; border-radius:8px; font-size:.82rem; color:#0f172a; outline:none; transition:border-color .15s; margin-bottom:.5rem; }
.sidebar-search:focus { border-color:#2563eb; }
.sidebar-tabs { display:flex; gap:.25rem; }
.sidebar-tab { flex:1; text-align:center; padding:.35rem; border-radius:7px; font-size:.74rem; font-weight:600; cursor:pointer; text-decoration:none; color:#64748b; transition:background .12s, color .12s; }
.sidebar-tab.active { background:#eff6ff; color:#2563eb; }
.sidebar-tab:hover { background:#f8fafc; }

.conv-list { flex:1; overflow-y:auto; }
.conv-list::-webkit-scrollbar { width:4px; }
.conv-list::-webkit-scrollbar-thumb { background:#e2e8f0; border-radius:999px; }
.conv-item { display:flex; align-items:center; gap:.75rem; padding:.75rem 1rem; cursor:pointer; text-decoration:none; border-bottom:1px solid #f8fafc; transition:background .1s; }
.conv-item:hover { background:#f8fafc; }
.conv-item.activa { background:#eff6ff; border-right:3px solid #2563eb; }
.conv-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#7c3aed); display:flex; align-items:center; justify-content:center; font-size:1rem; color:#fff; font-weight:700; flex-shrink:0; }
.conv-info { flex:1; min-width:0; }
.conv-name { font-size:.83rem; font-weight:600; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ── Filtro por tipo de contacto ─────────────────────── */
.sidebar-tipos { display:flex; flex-wrap:wrap; gap:.2rem; margin-top:.45rem; }
.tipo-filtro {
    font-size:.64rem; font-weight:600; padding:.18rem .42rem; border-radius:999px;
    text-decoration:none; color:#64748b; background:#f1f5f9;
    border:1px solid transparent; transition:background .12s, color .12s;
}
.tipo-filtro:hover { background:#e2e8f0; }
.tipo-filtro.active { border-color:currentColor; }
.tipo-filtro.t-cliente.active   { background:#dcfce7; color:#15803d; }
.tipo-filtro.t-excliente.active { background:#fef3c7; color:#b45309; }
.tipo-filtro.t-nuevo.active     { background:#ede9fe; color:#6d28d9; }
.tipo-filtro.t-todos.active     { background:#e0e7ff; color:#4338ca; }

/* ── Chip de tipo en cada conversación ───────────────── */
.tipo-chip {
    display:inline-block; font-size:.6rem; font-weight:700; line-height:1;
    padding:.15rem .35rem; border-radius:4px; flex-shrink:0; white-space:nowrap;
}
.tipo-chip.t-cliente   { background:#dcfce7; color:#15803d; }
.tipo-chip.t-excliente { background:#fef3c7; color:#b45309; }
.tipo-chip.t-nuevo     { background:#ede9fe; color:#6d28d9; }
.conv-name-row { display:flex; align-items:center; gap:.35rem; min-width:0; }
.conv-name-row .conv-name { flex:1; min-width:0; }
.conv-preview { font-size:.74rem; color:#94a3b8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:.1rem; }
.conv-meta { display:flex; flex-direction:column; align-items:flex-end; gap:.25rem; flex-shrink:0; }
.conv-time { font-size:.68rem; color:#cbd5e1; }
/* Chip de espera: lleva X esperando, y cuánto queda de ventana */
.conv-espera { font-size:.58rem; font-weight:700; line-height:1; padding:.18rem .35rem; border-radius:4px; white-space:nowrap; background:#f1f5f9; color:#475569; }
.conv-espera.urgente { background:#fef3c7; color:#b45309; }
.conv-espera.vencida { background:#fee2e2; color:#b91c1c; }
.conv-unread { background:#ef4444; color:#fff; font-size:.58rem; font-weight:700; padding:2px 6px; border-radius:999px; min-width:15px; height:15px; display:inline-flex; align-items:center; justify-content:center; text-align:center; }

/* Chat principal */
.chat-main { flex:1; display:flex; flex-direction:column; min-width:0; }
/* flex-wrap para que los botones bajen de línea en vez de aplastar el nombre:
   con `min-width:0` en el bloque de la izquierda, sin wrap se encogía a 0 y el
   contacto desaparecía en cuanto la ventana era angosta. */
.chat-header { padding:.75rem 1rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:.75rem; background:#fff; flex-shrink:0; flex-wrap:wrap; }
.chat-contact-info { flex:1 1 220px; min-width:0; }
.chat-contact-name { font-size:.9rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:.4rem; min-width:0; }
.chat-contact-name > span:first-child { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.chat-contact-sub { font-size:.72rem; color:#94a3b8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.chat-contact-sub .dato-retiro { color:#b45309; font-weight:600; }
.chat-contact-sub .dato-origen { color:#6d28d9; font-weight:600; }
.header-actions { display:flex; gap:.4rem; flex-shrink:0; align-items:center; flex-wrap:wrap; }
.btn-sm { padding:.3rem .65rem; border-radius:7px; font-size:.75rem; font-weight:600; cursor:pointer; border:none; display:inline-flex; align-items:center; gap:.3rem; text-decoration:none; transition:opacity .15s; }
.btn-sm:hover { opacity:.87; }
.btn-primary { background:#2563eb; color:#fff; }
.btn-outline { background:transparent; border:1px solid #cbd5e1; color:#475569; }
.btn-danger { background:#ef4444; color:#fff; }
.btn-success { background:#10b981; color:#fff; }

/* Ventana activa / inactiva */
.ventana-bar { padding:.4rem 1rem; font-size:.75rem; font-weight:600; text-align:center; flex-shrink:0; }
.ventana-activa { background:#d1fae5; color:#065f46; }
.ventana-inactiva { background:#fef3c7; color:#92400e; }

/* Área de mensajes */
.messages-area { flex:1; overflow-y:auto; padding:1rem; display:flex; flex-direction:column; gap:.6rem; background:#f8fafc; }
.messages-area::-webkit-scrollbar { width:6px; }
.messages-area::-webkit-scrollbar-thumb { background:#e2e8f0; border-radius:999px; }

.msg-bubble { max-width:70%; padding:.55rem .85rem; border-radius:12px; font-size:.83rem; line-height:1.45; word-break:break-word; }
.msg-entrante { background:#fff; color:#1e293b; border-radius:0 12px 12px 12px; box-shadow:0 1px 4px rgba(0,0,0,.07); align-self:flex-start; }
.msg-saliente { background:#2563eb; color:#fff; border-radius:12px 0 12px 12px; align-self:flex-end; }
.msg-meta { font-size:.65rem; opacity:.6; margin-top:.25rem; text-align:right; }
.msg-entrante .msg-meta { text-align:left; }
.msg-wrap { display:flex; flex-direction:column; }
.msg-wrap.saliente { align-items:flex-end; }
.msg-sender { font-size:.68rem; color:#94a3b8; margin-bottom:.15rem; }
/* Nota interna: no se envió al cliente, queda como registro (atendido por otro medio) */
.msg-wrap.nota { align-items:center; }
.msg-nota { background:#fef9c3; color:#713f12; border:1px dashed #facc15; border-radius:10px; font-size:.78rem; max-width:80%; }
.msg-nota .msg-meta { color:#a16207; }

/* Media */
.msg-image img { max-width:220px; border-radius:8px; cursor:pointer; display:block; }
.msg-audio audio { max-width:250px; }
.msg-document { display:flex; align-items:center; gap:.5rem; }
.msg-document .doc-icon { font-size:1.4rem; }

/* Entrada de mensajes */
.chat-input-area { border-top:1px solid #f1f5f9; padding:.75rem 1rem; background:#fff; flex-shrink:0; }
.input-row { display:flex; gap:.5rem; align-items:flex-end; }
.input-text { flex:1; padding:.55rem .85rem; border:1px solid #e2e8f0; border-radius:12px; font-size:.85rem; resize:none; outline:none; max-height:120px; transition:border-color .15s; }
.input-text:focus { border-color:#2563eb; }
.btn-enviar { background:#2563eb; color:#fff; border:none; border-radius:10px; padding:.55rem .85rem; cursor:pointer; font-size:1rem; transition:opacity .15s; }
.btn-enviar:hover { opacity:.87; }
.btn-adjuntar { background:transparent; border:none; font-size:1.1rem; cursor:pointer; padding:.45rem; border-radius:8px; color:#64748b; transition:background .12s; }
.btn-adjuntar:hover { background:#f1f5f9; }

/* Template sender */
.template-selector { border:1px solid #e2e8f0; border-radius:10px; padding:.65rem; margin-bottom:.5rem; background:#f8fafc; }
.template-selector select { width:100%; border:none; background:transparent; font-size:.83rem; outline:none; color:#0f172a; }
.template-params { display:grid; gap:.4rem; margin-top:.5rem; }
.template-params input { padding:.4rem .65rem; border:1px solid #e2e8f0; border-radius:7px; font-size:.8rem; outline:none; }

/* Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:9999; display:flex; align-items:center; justify-content:center; }
.modal-box { background:#fff; border-radius:14px; padding:1.5rem; width:420px; max-width:95vw; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.modal-title { font-size:1rem; font-weight:700; color:#0f172a; margin-bottom:1rem; }
.form-group { margin-bottom:.85rem; }
.form-label { font-size:.78rem; font-weight:600; color:#374151; display:block; margin-bottom:.3rem; }
.form-control { width:100%; padding:.45rem .7rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.83rem; outline:none; }
.form-control:focus { border-color:#2563eb; }
</style>
@endpush

@section('contenido')
<div style="padding:0 1.5rem 1.5rem">
<div class="chat-layout" x-data="chatApp()" x-init="init()">

    {{-- Sidebar con lista de conversaciones dinámica con Alpine.js --}}
    <aside class="chat-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-title">
                💬 WhatsApp
                <span class="sidebar-badge" x-show="totalNoLeidos > 0" x-text="totalNoLeidos"></span>
            </div>

            <form method="GET">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="tipo" value="{{ $tipo }}">
                <input type="text" name="buscar" class="sidebar-search"
                       value="{{ $buscar }}" placeholder="Buscar conversación...">
            </form>

            <div class="sidebar-tabs">
                <a href="{{ route('admin.whatsapp.chat.show', ['id' => $conversacion->id, 'tab' => 'general', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'general' ? 'active' : '' }}">📥 General</a>
                <a href="{{ route('admin.whatsapp.chat.show', ['id' => $conversacion->id, 'tab' => 'esperando', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'esperando' ? 'active' : '' }}"
                   title="Escribieron y nadie les ha respondido">⏳ Esperando
                    <span class="sidebar-badge" style="margin-left:.25rem;background:#d97706" x-show="totalEsperando > 0" x-text="totalEsperando"></span>
                </a>
                <a href="{{ route('admin.whatsapp.chat.show', ['id' => $conversacion->id, 'tab' => 'mias', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'mias' ? 'active' : '' }}">👤 Míos</a>
                <a href="{{ route('admin.whatsapp.chat.show', ['id' => $conversacion->id, 'tab' => 'ia', 'buscar' => $buscar, 'tipo' => $tipo]) }}"
                   class="sidebar-tab {{ $tab === 'ia' ? 'active' : '' }}">🤖 IA
                    @if($totalIa > 0)<span class="sidebar-badge" style="margin-left:.25rem;">{{ $totalIa }}</span>@endif
                </a>
            </div>

            {{-- Filtro por tipo de contacto: cliente / excliente / nuevo --}}
            <div class="sidebar-tipos">
                <a href="{{ route('admin.whatsapp.chat.show', ['id' => $conversacion->id, 'tab' => $tab, 'buscar' => $buscar]) }}"
                   class="tipo-filtro t-todos {{ $tipo ? '' : 'active' }}">Todos</a>
                @foreach(\App\Services\WhatsappTipoContacto::ETIQUETAS as $clave => $etiqueta)
                    <a href="{{ route('admin.whatsapp.chat.show', ['id' => $conversacion->id, 'tab' => $tab, 'buscar' => $buscar, 'tipo' => $clave]) }}"
                       class="tipo-filtro t-{{ $clave }} {{ $tipo === $clave ? 'active' : '' }}">
                        {{ $etiqueta }} {{ $conteoTipos[$clave] ?? 0 }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="conv-list">
            <template x-for="c in listaConversaciones" :key="c.id">
                <a :href="c.url_show + '?tab={{ $tab }}&buscar={{ urlencode($buscar ?? '') }}&tipo={{ urlencode($tipo ?? '') }}'"
                   @click.prevent="cargarConversacion(c.id)"
                   class="conv-item" :class="c.id == convId ? 'activa' : ''">
                    <div class="conv-avatar" x-text="c.nombre.substring(0, 1).toUpperCase()"></div>
                    <div class="conv-info">
                        <div class="conv-name-row">
                            <span class="conv-name" x-text="c.nombre"></span>
                            <template x-if="c.tipo_contacto">
                                <span class="tipo-chip" :class="'t-' + c.tipo_contacto"
                                      x-text="(c.desde_marketing ? '📣 ' : '') + c.tipo_label"></span>
                            </template>
                        </div>
                        <div class="conv-preview">
                            <template x-if="c.pendiente_atencion">
                                <span style="color:#d97706;font-weight:600">⚠️ Pendiente por atender</span>
                            </template>
                            <template x-if="!c.pendiente_atencion && c.esperando">
                                <span style="color:#b45309">⏳ <span x-text="c.preview || 'Sin mensajes'"></span></span>
                            </template>
                            <template x-if="!c.pendiente_atencion && !c.esperando && c.atendida_por_ia">
                                <span style="color:#2563eb">🤖 Atendiendo la IA</span>
                            </template>
                            <template x-if="!c.pendiente_atencion && !c.esperando && !c.atendida_por_ia && c.asignado_nombre">
                                <span style="color:#10b981">● <span x-text="c.asignado_nombre"></span></span>
                            </template>
                            <template x-if="!c.pendiente_atencion && !c.esperando && !c.atendida_por_ia && !c.asignado_nombre">
                                <span x-text="c.preview || 'Sin mensajes'"></span>
                            </template>
                        </div>
                    </div>
                    <div class="conv-meta">
                        <span class="conv-time" x-text="c.hora_display"></span>
                        <span class="conv-unread" x-show="c.total_mensajes_no_leidos > 0" x-text="c.total_mensajes_no_leidos"></span>
                        <template x-if="c.esperando && c.esperando_desde">
                            <span class="conv-espera" :class="claseEspera(c)" :title="tituloEspera(c)" x-text="textoEspera(c)"></span>
                        </template>
                    </div>
                </a>
            </template>
            <div x-show="listaConversaciones.length === 0" style="text-align:center;padding:2rem 1rem;color:#94a3b8;font-size:.82rem">
                @if($tipo)
                    No hay conversaciones de tipo «{{ \App\Services\WhatsappTipoContacto::ETIQUETAS[$tipo] ?? $tipo }}» en esta pestaña.
                @elseif($tab === 'esperando')
                    Nadie está esperando respuesta. 🎉
                @else
                    No hay conversaciones.
                @endif
            </div>
        </div>
    </aside>

    {{-- Chat principal --}}
    <main class="chat-main" x-show="conversacion" x-cloak>

        {{-- Header del chat --}}
        <div class="chat-header">
            <div class="conv-avatar" style="width:42px;height:42px" x-text="conversacion.nombre.substring(0, 1).toUpperCase()">
            </div>
            <div class="chat-contact-info">
                <div class="chat-contact-name">
                    <span x-text="conversacion.nombre"></span>
                    <template x-if="conversacion.tipo_contacto">
                        <span class="tipo-chip" :class="'t-' + conversacion.tipo_contacto"
                              x-text="(conversacion.desde_marketing ? '📣 ' : '') + conversacion.tipo_label"></span>
                    </template>
                </div>
                <div class="chat-contact-sub">
                    <span x-text="conversacion.celular"></span>
                    <span x-show="conversacion.estado === 'asignada' && conversacion.asignado_nombre">
                        · Asignada a <strong x-text="conversacion.asignado_nombre"></strong>
                    </span>
                    <span x-show="conversacion.estado !== 'asignada' || !conversacion.asignado_nombre" style="color:#94a3b8">
                        · Inbox general
                    </span>
                    <template x-if="conversacion.retirado_desde">
                        <span class="dato-retiro"> · Retirado el <span x-text="conversacion.retirado_desde"></span></span>
                    </template>
                    <template x-if="conversacion.origen">
                        <span class="dato-origen"> · Llegó por <span x-text="conversacion.origen"></span></span>
                    </template>
                </div>
            </div>
            <div class="header-actions">
                <button x-show="conversacion.bot_activo" class="btn-sm btn-success" @click="toggleBot()"
                        title="Silencia al Asistente IA en esta conversación y te la asigna a ti">
                    🙋 Tomar conversación
                </button>
                <button x-show="!conversacion.bot_activo" class="btn-sm btn-outline" @click="toggleBot()"
                        title="El Asistente IA vuelve a responder en esta conversación">
                    🤖 Reactivar IA
                </button>
                <button class="btn-sm btn-outline" @click="modalAtendida = true"
                        title="Ya le respondiste por otro WhatsApp, llamada o en persona: deja la nota y la saca de «Esperando»">
                    ✔ Atendido por otro medio
                </button>
                <button class="btn-sm btn-outline" @click="modalAsignar = true">👤 Asignar</button>
                @if($aliadosMover->isNotEmpty())
                    <button class="btn-sm btn-outline" @click="modalMover = true"
                            title="Pasa esta conversación (y sus mensajes) al aliado dueño del contacto">
                        🔀 Mover a aliado
                    </button>
                @endif
                <a x-show="conversacion.contrato_url" :href="conversacion.contrato_url" target="_blank" class="btn-sm btn-success">
                    📄 Ver Cliente
                </a>
                <button class="btn-sm btn-outline" @click="noContactar()" title="Bloquea este número de futuras campañas de marketing">
                    🚫 No contactar
                </button>
                <button class="btn-sm btn-danger" @click="cerrarConversacion()">✕ Cerrar</button>
            </div>
        </div>

        {{-- Aviso: el cliente pidió un asesor y la conversación quedó pendiente --}}
        <div class="ventana-bar" style="background:#fffbeb;color:#b45309;" x-show="conversacion.pendiente_atencion">
            ⚠️ Pendiente por atender<template x-if="conversacion.pendiente_motivo"> — <span x-text="conversacion.pendiente_motivo"></span></template>
        </div>

        {{-- Barra de ventana activa/inactiva --}}
        <div class="ventana-bar ventana-activa" x-show="conversacion.ventana_activa">
            ✅ Ventana activa — <span x-text="conversacion.ventana_minutos"></span> minutos restantes para enviar mensajes libres
        </div>
        <div class="ventana-bar ventana-inactiva" x-show="!conversacion.ventana_activa">
            ⚠️ Ventana expirada — Solo puedes enviar plantillas aprobadas para iniciar la conversación
        </div>

        {{-- Área de mensajes --}}
        <div class="messages-area" id="messagesArea">
            <template x-for="msg in mensajes" :key="msg.id">
                <div class="msg-wrap" :class="msg.tipo === 'nota' ? 'nota' : (msg.es_entrante ? 'entrante' : 'saliente')">
                    <template x-if="!msg.es_entrante && msg.usuario_nombre && msg.tipo !== 'nota'">
                        <div class="msg-sender" x-text="msg.usuario_nombre"></div>
                    </template>

                    <div class="msg-bubble" :class="msg.tipo === 'nota' ? 'msg-nota' : (msg.es_entrante ? 'msg-entrante' : 'msg-saliente')">

                        <!-- Nota interna (no le llegó al cliente) -->
                        <template x-if="msg.tipo === 'nota'">
                            <div><span x-html="formatearContenido(msg.contenido)"></span>
                                <template x-if="msg.usuario_nombre"><span style="opacity:.75"> — <span x-text="msg.usuario_nombre"></span></span></template>
                            </div>
                        </template>

                        <!-- Texto y plantilla -->
                        <template x-if="msg.tipo === 'text' || msg.tipo === 'template'">
                            <div>
                                <span x-html="formatearContenido(msg.contenido)"></span>
                                <template x-if="msg.tipo === 'template' && msg.plantilla_nombre">
                                    <div style="font-size:.68rem;opacity:.6;margin-top:.2rem" x-text="'📋 ' + msg.plantilla_nombre"></div>
                                </template>
                            </div>
                        </template>

                        <!-- Imagen -->
                        <template x-if="msg.tipo === 'image'">
                            <div class="msg-image">
                                <template x-if="msg.tiene_media">
                                    <img :src="msg.media_url" alt="Imagen" loading="lazy"
                                         @click="window.open(msg.media_url, '_blank')">
                                </template>
                                <template x-if="!msg.tiene_media">
                                    <div style="padding:.5rem;color:rgba(255,255,255,.6)">📷 Descargando imagen...</div>
                                </template>
                                <template x-if="msg.contenido">
                                    <div style="margin-top:.3rem" x-text="msg.contenido"></div>
                                </template>
                            </div>
                        </template>

                        <!-- Audio -->
                        <template x-if="msg.tipo === 'audio'">
                            <div class="msg-audio">
                                <template x-if="msg.tiene_media">
                                    <audio controls preload="none" style="max-width:240px">
                                        <source :src="msg.media_url" :type="msg.media_mime_type || 'audio/ogg'">
                                    </audio>
                                </template>
                                <template x-if="!msg.tiene_media">
                                    <span style="opacity:.7">🎵 Descargando audio...</span>
                                </template>
                            </div>
                        </template>

                        <!-- Documento -->
                        <template x-if="msg.tipo === 'document'">
                            <div class="msg-document">
                                <span class="doc-icon">📄</span>
                                <div>
                                    <div style="font-size:.8rem;font-weight:600" x-text="msg.media_nombre || 'Documento'"></div>
                                    <template x-if="msg.tiene_media">
                                        <a :href="msg.media_url" target="_blank"
                                           style="font-size:.72rem;text-decoration:underline"
                                           :style="msg.es_entrante ? 'color:#2563eb' : 'color:#bfdbfe'">
                                            Descargar
                                        </a>
                                    </template>
                                    <template x-if="!msg.tiene_media">
                                        <span style="font-size:.72rem;opacity:.6">Descargando...</span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <!-- Video -->
                        <template x-if="msg.tipo === 'video'">
                            <div>
                                <template x-if="msg.tiene_media">
                                    <video controls style="max-width:220px;border-radius:6px">
                                        <source :src="msg.media_url" :type="msg.media_mime_type">
                                    </video>
                                </template>
                                <template x-if="!msg.tiene_media">
                                    <span>🎥 Descargando video...</span>
                                </template>
                            </div>
                        </template>

                        <div class="msg-meta">
                            <span x-text="msg.hora"></span>
                            <template x-if="!msg.es_entrante">
                                <span x-text="msg.icono_estado"></span>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Área de entrada --}}
        <div class="chat-input-area">
            {{-- Ventana activa: texto libre + adjuntos --}}
            <template x-if="conversacion.ventana_activa">
                <div class="input-row">
                    <label class="btn-adjuntar" title="Adjuntar imagen/documento">
                        📎
                        <input type="file" style="display:none" accept="image/*,application/pdf,audio/*"
                               @change="adjuntarArchivo($event)">
                    </label>
                    <textarea class="input-text" rows="1" placeholder="Escribe un mensaje..."
                              x-model="textoMensaje" @keydown.enter.prevent="enviarTexto()"
                              @input="autoResize($event)"></textarea>
                    <button class="btn-enviar" @click="enviarTexto()" :disabled="enviando">
                        <span x-show="!enviando">➤</span>
                        <span x-show="enviando">⏳</span>
                    </button>
                </div>
            </template>

            {{-- Ventana inactiva: solo plantillas --}}
            <template x-if="!conversacion.ventana_activa">
                <div>
                    <div class="template-selector">
                        <div style="font-size:.75rem;font-weight:600;color:#92400e;margin-bottom:.4rem">
                            📋 Selecciona una plantilla para iniciar
                        </div>
                        <select x-model="plantillaSeleccionada" @change="cargarParamsPlantilla()" style="width:100%;border:none;background:transparent;font-size:.83rem;outline:none">
                            <option value="">— Elige una plantilla aprobada —</option>
                            @foreach($plantillas as $plt)
                                <option value="{{ $plt->id }}"
                                        data-vars="{{ $plt->cantidadVariables() }}"
                                        data-preview="{{ e($plt->cuerpo) }}">
                                    {{ $plt->nombre_display }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="template-params" x-show="paramsPlantilla.length > 0">
                        <template x-for="(p, idx) in paramsPlantilla" :key="idx">
                            <input type="text" class="template-params input" x-model="paramsPlantilla[idx]"
                                   :placeholder="'Variable {{' + (idx+1) + '}}: ' + (getMapaVar(idx) || '')">
                        </template>
                    </div>
                    <div class="input-row" style="margin-top:.5rem">
                        <button class="btn-sm btn-primary" style="width:100%;justify-content:center"
                                @click="enviarTemplate()" :disabled="!plantillaSeleccionada || enviando">
                            <span x-show="!enviando">📤 Enviar plantilla</span>
                            <span x-show="enviando">⏳ Enviando...</span>
                        </button>
                    </div>
                </div>
            </template>

            {{-- Mensajes de error/éxito --}}
            <div x-show="mensajeError" style="color:#ef4444;font-size:.75rem;margin-top:.4rem" x-text="mensajeError"></div>
        </div>
    </main>

{{-- Modal de asignación --}}
<div class="modal-overlay" x-show="modalAsignar" x-cloak @click.self="modalAsignar = false">
    <div class="modal-box">
        <div class="modal-title">👤 Asignar conversación</div>
        <div class="form-group">
            <label class="form-label">Usuario responsable</label>
            <select x-model="usuarioAsignar" class="form-control">
                <option value="">— Inbox general (sin asignar) —</option>
                @foreach($usuarios as $u)
                    <option value="{{ $u->id }}" :selected="usuarioAsignar == {{ $u->id }}">
                        {{ $u->nombre }}
                    </option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
            <button class="btn-sm btn-outline" @click="modalAsignar = false">Cancelar</button>
            <button class="btn-sm btn-primary" @click="asignarConversacion()">✅ Asignar</button>
        </div>
    </div>
</div>

{{-- Atendido por otro medio: nota interna, no se le envía nada al cliente --}}
<div class="modal-overlay" x-show="modalAtendida" x-cloak @click.self="modalAtendida = false">
    <div class="modal-box">
        <div class="modal-title">✔ Atendido por otro medio</div>
        <p style="font-size:.8rem;color:#64748b;margin-bottom:.75rem">
            Queda una nota interna en el chat (el cliente no recibe nada) y la conversación sale de «Esperando».
        </p>
        <div class="form-group">
            <label class="form-label">¿Por dónde se atendió?</label>
            <select x-model="atendidaMedio" class="form-control">
                @foreach(\App\Http\Controllers\Admin\WhatsappChatController::MEDIOS_ATENCION as $clave => $etiqueta)
                    <option value="{{ $clave }}">{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Nota (opcional)</label>
            <textarea x-model="atendidaNota" class="form-control" rows="2" maxlength="500" placeholder="Ej.: le confirmé el pago desde mi celular"></textarea>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
            <button class="btn-sm btn-outline" @click="modalAtendida = false">Cancelar</button>
            <button class="btn-sm btn-primary" :disabled="atendiendo" @click="marcarAtendida()">✔ Marcar atendida</button>
        </div>
    </div>
</div>

@if($aliadosMover->isNotEmpty())
{{-- Bandeja del número compartido: BryNex pasa la conversación al aliado que corresponde --}}
<div class="modal-overlay" x-show="modalMover" x-cloak @click.self="modalMover = false">
    <div class="modal-box">
        <div class="modal-title">🔀 Mover conversación a otro aliado</div>
        <p style="font-size:.8rem;color:#64748b;margin-bottom:.75rem">
            Los mensajes se pasan al aliado elegido y la conversación desaparece de este inbox.
            Si el aliado ya tenía chat con este número, se juntan en uno solo.
        </p>
        <div class="form-group">
            <label class="form-label">Aliado destino</label>
            <select x-model="aliadoMover" class="form-control">
                <option value="">— Elegir aliado —</option>
                @foreach($aliadosMover as $a)
                    <option value="{{ $a->id }}">{{ $a->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
            <button class="btn-sm btn-outline" @click="modalMover = false">Cancelar</button>
            <button class="btn-sm btn-primary" :disabled="!aliadoMover || moviendo" @click="moverAliado()">🔀 Mover</button>
        </div>
    </div>
</div>
@endif
</div>
</div>
@endsection

@push('scripts')
<!-- Load Echo & Pusher dynamically from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.3.0/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
<script>
window.Pusher = Pusher;
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: '{{ env('REVERB_APP_KEY') }}',
    wsHost: '{{ env('VITE_REVERB_HOST') }}' || window.location.hostname,
    wsPort: {{ env('VITE_REVERB_PORT', 8080) }},
    wssPort: {{ env('VITE_REVERB_PORT', 8080) }},
    forceTLS: {{ env('VITE_REVERB_SCHEME', 'http') === 'https' ? 'true' : 'false' }},
    enabledTransports: ['ws', 'wss'],
});
</script>
<script>
function chatApp() {
    return {
        textoMensaje: '',
        plantillaSeleccionada: '',
        paramsPlantilla: [],
        mapaVars: {},
        enviando: false,
        mensajeError: '',
        modalAsignar: false,
        modalMover: false,
        modalAtendida: false,
        atendidaMedio: 'otro_whatsapp',
        atendidaNota: '',
        atendiendo: false,
        aliadoMover: '',
        moviendo: false,
        usuarioAsignar: '{{ $conversacion->asignado_a ?? '' }}',
        mensajes: @json($mensajesData),
        conversacion: @json($conversacionData),
        alidoId: '{{ session('aliado_id_activo') }}',
        convId: {{ $conversacion->id }},
        listaConversaciones: @json($conversacionesData),
        tipoFiltro: @json($tipo),
        totalNoLeidos: {{ (int) $totalNoLeidos }},
        totalEsperando: {{ (int) $totalEsperando }},

        init() {
            this.scrollBottom();
            this.conectarReverb();
        },

        scrollBottom() {
            this.$nextTick(() => {
                const area = document.getElementById('messagesArea');
                if (area) area.scrollTop = area.scrollHeight;
            });
        },

        formatearContenido(contenido) {
            if (!contenido) return '';
            let escaped = contenido
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
            return escaped.replace(/\n/g, '<br>');
        },

        async cargarConversacion(id) {
            if (this.enviando) return;
            // Sin id no hay nada que cargar, y sobre todo: no se puede dejar la
            // URL en /chat/null. El pushState no pide nada al servidor, así que
            // el error solo aparecía al recargar, ya lejos de donde se originó.
            if (id === null || id === undefined || id === '' || Number.isNaN(Number(id))) {
                console.warn('cargarConversacion sin id válido:', id);
                return;
            }
            this.convId = id;
            this.textoMensaje = '';
            this.mensajeError = '';

            // Cambiar URL en el navegador de manera SPA
            const newUrl = `/admin/whatsapp/chat/${id}?tab={{ $tab }}&buscar={{ urlencode($buscar ?? '') }}&tipo={{ urlencode($tipo ?? '') }}`;
            window.history.pushState({ path: newUrl }, '', newUrl);

            try {
                const resp = await fetch(`/admin/whatsapp/chat/${id}/api-mensajes`);
                const data = await resp.json();
                if (data.ok) {
                    this.mensajes = data.mensajes;
                    this.conversacion = data.conversacion;
                    this.usuarioAsignar = data.conversacion.asignado_a || '';

                    // Resetear no leídos en el listado local de manera reactiva
                    let convItem = this.listaConversaciones.find(c => c.id == id);
                    if (convItem) {
                        this.totalNoLeidos = Math.max(0, this.totalNoLeidos - convItem.total_mensajes_no_leidos);
                        convItem.total_mensajes_no_leidos = 0;
                    }

                    this.scrollBottom();
                } else {
                    this.mensajeError = data.error || 'Error al cargar mensajes.';
                }
            } catch (e) {
                this.mensajeError = 'Error de conexión al cargar la conversación.';
            }
        },

        // ── Chip «esperando»: lo que lleva y cuánto queda de la ventana de 24 h ──
        // Se calcula en el navegador a partir de los timestamps, así la cuenta sigue
        // corriendo sin recargar y los eventos de Reverb solo tienen que mover las fechas.
        minutosVentana(c) {
            if (!c.ventana_activa || !c.ventana_minutos) return 0;
            const cargado = c._cargado_en || (c._cargado_en = Date.now());
            return Math.max(0, c.ventana_minutos - Math.floor((Date.now() - cargado) / 60000));
        },
        haceDesde(iso) {
            const min = Math.max(0, Math.floor((Date.now() - new Date(iso)) / 60000));
            if (min < 60) return min + ' min';
            if (min < 1440) return Math.floor(min / 60) + ' h';
            return Math.floor(min / 1440) + ' d';
        },
        claseEspera(c) {
            const v = this.minutosVentana(c);
            return v <= 0 ? 'vencida' : (v <= 180 ? 'urgente' : '');
        },
        textoVentana(c) {
            const v = this.minutosVentana(c);
            if (v <= 0) return 'ventana vencida';
            return v < 60 ? `vence en ${v} min` : `vence en ${Math.floor(v / 60)} h`;
        },
        textoEspera(c) {
            const v = this.minutosVentana(c);
            const base = '⏳ ' + this.haceDesde(c.esperando_desde);
            return v <= 0 ? base + ' · ⛔' : (v <= 180 ? base + ' · ' + this.textoVentana(c) : base);
        },
        tituloEspera(c) {
            return 'Esperando respuesta desde hace ' + this.haceDesde(c.esperando_desde) + ' · ' + this.textoVentana(c);
        },
        // Alguien nuestro le escribió: deja de estar esperando, salvo que siga marcada
        // como pendiente (el acuse automático responde pero la persona sigue sin atender).
        marcarRespondida(conv) {
            if (!conv) return;
            if (conv.esperando && !conv.pendiente_atencion) this.totalEsperando = Math.max(0, this.totalEsperando - 1);
            conv.esperando = !!conv.pendiente_atencion;
        },
        marcarEsperando(conv) {
            if (!conv) return;
            if (!conv.esperando) this.totalEsperando++;
            conv.esperando = true;
            conv.esperando_desde = new Date().toISOString();
            conv.ventana_activa = true;
            conv.ventana_minutos = 1440;
            conv._cargado_en = Date.now();
        },

        async marcarAtendida() {
            if (this.atendiendo) return;
            this.atendiendo = true;
            try {
                const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/atendida`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ medio: this.atendidaMedio, nota: this.atendidaNota })
                });
                const data = await resp.json();
                if (data.ok) {
                    this.modalAtendida = false;
                    this.atendidaNota = '';
                    this.mensajes.push(data.mensaje);
                    this.conversacion.pendiente_atencion = false;
                    this.conversacion.estado = 'asignada';
                    this.conversacion.asignado_a = data.asignado_a;
                    this.conversacion.asignado_nombre = data.asignado_nombre;
                    this.usuarioAsignar = data.asignado_a || '';
                    const conv = this.listaConversaciones.find(c => c.id == this.convId);
                    if (conv) {
                        conv.preview = data.mensaje.contenido;
                        conv.pendiente_atencion = false;
                        conv.asignado_a = data.asignado_a;
                        conv.asignado_nombre = data.asignado_nombre;
                        conv.ultimo_mensaje_at = new Date().toISOString();
                        this.marcarRespondida(conv);
                        this.ordenarConversaciones();
                    }
                    this.scrollBottom();
                } else {
                    alert(data.error || 'No se pudo marcar');
                }
            } catch (e) { alert('Error de conexión'); }
            this.atendiendo = false;
        },

        async moverAliado() {
            if (!this.aliadoMover || this.moviendo) return;
            this.moviendo = true;
            try {
                const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/mover-aliado`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ aliado_id: this.aliadoMover })
                });
                const data = await resp.json();
                if (data.ok) {
                    window.location.href = data.redirect;
                } else {
                    alert(data.error || 'No se pudo mover la conversación');
                }
            } catch (e) { alert('Error de conexión'); }
            this.moviendo = false;
        },

        ordenarConversaciones() {
            this.listaConversaciones.sort((a, b) => {
                if (!a.ultimo_mensaje_at) return 1;
                if (!b.ultimo_mensaje_at) return -1;
                return new Date(b.ultimo_mensaje_at) - new Date(a.ultimo_mensaje_at);
            });
        },

        async enviarTexto() {
            if (!this.textoMensaje.trim() || this.enviando) return;
            const msgOriginal = this.textoMensaje;
            this.enviando = true;
            this.mensajeError = '';

            try {
                const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/mensaje`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ tipo: 'text', contenido: msgOriginal })
                });

                const data = await resp.json();
                if (data.ok) {
                    const ahora = new Date();
                    this.mensajes.push({
                        id: data.mensaje?.id || Date.now(),
                        tipo: 'text',
                        contenido: msgOriginal,
                        es_entrante: false,
                        usuario_nombre: '{{ Auth::user()->nombre }}',
                        plantilla_nombre: null,
                        tiene_media: false,
                        media_url: null,
                        media_nombre: null,
                        media_mime_type: null,
                        hora: String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0'),
                        icono_estado: '📤',
                    });

                    // Actualizar barra lateral
                    let conv = this.listaConversaciones.find(c => c.id == this.convId);
                    if (conv) {
                        conv.preview = msgOriginal;
                        conv.ultimo_mensaje_at = ahora.toISOString();
                        conv.hora_display = String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0');
                        this.ordenarConversaciones();
                        // Un humano respondió: el backend quita el pendiente y ya no está esperando.
                        conv.pendiente_atencion = false;
                        this.conversacion.pendiente_atencion = false;
                        this.marcarRespondida(conv);
                    }

                    this.textoMensaje = '';
                    this.scrollBottom();
                } else {
                    this.mensajeError = data.error || 'Error al enviar el mensaje.';
                }
            } catch (e) {
                this.mensajeError = 'Error de conexión.';
            } finally {
                this.enviando = false;
            }
        },

        async enviarTemplate() {
            if (!this.plantillaSeleccionada || this.enviando) return;
            this.enviando = true;
            this.mensajeError = '';

            try {
                const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/mensaje`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        tipo: 'template',
                        plantilla_id: this.plantillaSeleccionada,
                        parametros: this.paramsPlantilla,
                    })
                });
                const data = await resp.json();
                if (data.ok) {
                    const ahora = new Date();
                    this.mensajes.push({
                        id: Date.now(),
                        tipo: 'template',
                        contenido: '📋 Plantilla enviada',
                        es_entrante: false,
                        usuario_nombre: '{{ Auth::user()->nombre }}',
                        plantilla_nombre: null,
                        tiene_media: false,
                        media_url: null,
                        media_nombre: null,
                        media_mime_type: null,
                        hora: String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0'),
                        icono_estado: '📤',
                    });

                    // Actualizar ventana activa directamente — sin recargar la página
                    if (data.ventana_activa !== undefined) {
                        this.conversacion.ventana_activa  = data.ventana_activa;
                        this.conversacion.ventana_minutos = data.ventana_minutos ?? 1440;
                    }

                    // Actualizar barra lateral
                    let conv = this.listaConversaciones.find(c => c.id == this.convId);
                    if (conv) {
                        conv.preview = '📋 Plantilla enviada';
                        conv.ultimo_mensaje_at = ahora.toISOString();
                        conv.hora_display = String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0');
                        this.ordenarConversaciones();
                        // Un humano respondió: el backend quita el pendiente y ya no está esperando.
                        conv.pendiente_atencion = false;
                        this.conversacion.pendiente_atencion = false;
                        this.marcarRespondida(conv);
                    }

                    this.plantillaSeleccionada = '';
                    this.paramsPlantilla = [];
                    this.scrollBottom();
                } else {
                    this.mensajeError = data.error || 'Error al enviar la plantilla.';
                }
            } catch(e) {
                this.mensajeError = 'Error de conexión.';
            } finally {
                this.enviando = false;
            }
        },

        async adjuntarArchivo(event) {
            const file = event.target.files[0];
            if (!file) return;

            this.enviando = true;
            this.mensajeError = '';

            const tipo = file.type.startsWith('image/') ? 'image'
                : file.type === 'application/pdf' ? 'document'
                : file.type.startsWith('audio/') ? 'audio' : 'document';

            const formData = new FormData();
            formData.append('tipo', tipo);
            formData.append('archivo', file);
            formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

            try {
                const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/mensaje`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData,
                });
                const data = await resp.json();
                if (data.ok) {
                    const ahora = new Date();
                    this.mensajes.push({
                        id: Date.now(),
                        tipo: tipo,
                        contenido: '📎 ' + file.name,
                        es_entrante: false,
                        usuario_nombre: '{{ Auth::user()->nombre }}',
                        plantilla_nombre: null,
                        tiene_media: true,
                        media_url: data.mensaje?.media_url || '',
                        media_nombre: file.name,
                        media_mime_type: file.type,
                        hora: String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0'),
                        icono_estado: '📤',
                    });

                    // Actualizar barra lateral
                    let conv = this.listaConversaciones.find(c => c.id == this.convId);
                    if (conv) {
                        conv.preview = '📎 ' + file.name;
                        conv.ultimo_mensaje_at = ahora.toISOString();
                        conv.hora_display = String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0');
                        this.ordenarConversaciones();
                        // Un humano respondió: el backend quita el pendiente y ya no está esperando.
                        conv.pendiente_atencion = false;
                        this.conversacion.pendiente_atencion = false;
                        this.marcarRespondida(conv);
                    }

                    this.scrollBottom();
                } else {
                    this.mensajeError = data.error || 'Error al enviar el archivo.';
                }
            } catch(e) {
                this.mensajeError = 'Error de conexión.';
            } finally {
                this.enviando = false;
                event.target.value = '';
            }
        },

        cargarParamsPlantilla() {
            const sel = document.querySelector(`option[value="${this.plantillaSeleccionada}"]`);
            const cantVars = sel ? parseInt(sel.dataset.vars || 0) : 0;
            this.paramsPlantilla = Array(cantVars).fill('');
        },

        getMapaVar(idx) {
            return this.mapaVars[idx + 1] || '';
        },

        async asignarConversacion() {
            try {
                const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/asignar`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ user_id: this.usuarioAsignar || null })
                });
                const data = await resp.json();
                if (data.ok) {
                    this.modalAsignar = false;
                    // Actualizar asignado_nombre localmente
                    const userSelected = document.querySelector(`option[value="${this.usuarioAsignar}"]`);
                    this.conversacion.asignado_nombre = userSelected ? userSelected.textContent.trim() : null;
                    this.conversacion.estado = this.usuarioAsignar ? 'asignada' : 'abierta';
                    // asignarA() en el backend limpia pendiente_atencion al asignar — sin esto,
                    // el badge "⚠️ Pendiente por atender" se quedaba pegado porque el template
                    // lo revisa antes que asignado_nombre.
                    if (this.usuarioAsignar) this.conversacion.pendiente_atencion = false;

                    // Actualizar en el listado lateral
                    let convItem = this.listaConversaciones.find(c => c.id == this.convId);
                    if (convItem) {
                        convItem.asignado_nombre = this.conversacion.asignado_nombre;
                        convItem.asignado_a = this.usuarioAsignar || null;
                        if (this.usuarioAsignar) convItem.pendiente_atencion = false;
                    }
                }
                else alert(data.error || 'Error al asignar');
            } catch(e) { alert('Error de conexión'); }
        },

        async cerrarConversacion() {
            if (!confirm('¿Cerrar esta conversación? Se archivará y no aparecerá en el inbox.')) return;
            const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/cerrar`, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                }
            });
            const data = await resp.json();
            if (data.ok) window.location = '{{ route('admin.whatsapp.chat.index') }}';
        },

        async noContactar() {
            if (!confirm('¿Bloquear este número de futuras campañas de marketing? No volverá a recibir publicidad.')) return;
            const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/no-contactar`, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                }
            });
            const data = await resp.json();
            if (data.ok) alert(data.mensaje);
            else alert(data.error || 'Error al bloquear el número');
        },

        async toggleBot() {
            const nuevoEstado = !this.conversacion.bot_activo;
            const resp = await fetch(`/admin/whatsapp/chat/${this.convId}/toggle-bot`, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ activo: nuevoEstado })
            });
            const data = await resp.json();
            if (data.ok) {
                this.conversacion.bot_activo = data.bot_activo;
                this.conversacion.atendida_por_ia = data.atendida_por_ia;
                this.conversacion.pendiente_atencion = data.pendiente_atencion;
                this.conversacion.asignado_a = data.asignado_a;
                this.conversacion.asignado_nombre = data.asignado_nombre;
                this.conversacion.estado = data.estado;
                this.usuarioAsignar = data.asignado_a || '';

                // Reflejar el cambio en la lista lateral sin recargar
                const convItem = this.listaConversaciones.find(c => c.id == this.convId);
                if (convItem) {
                    convItem.bot_activo = data.bot_activo;
                    convItem.atendida_por_ia = data.atendida_por_ia;
                    convItem.pendiente_atencion = data.pendiente_atencion;
                    convItem.asignado_nombre = data.asignado_nombre;
                }

                if (data.mensaje && data.mensaje.includes('apagado')) alert(data.mensaje);
            }
        },

        conectarReverb() {
            if (typeof window.Echo === 'undefined') return;

            window.Echo.private(`whatsapp-aliado.${this.alidoId}`)
                .listen('.mensaje.nuevo', async (e) => {
                    const ahora = new Date();
                    
                    // 1. Si es de la conversación abierta actual
                    if (e.conversacion_id == this.convId && e.direccion === 'entrante') {
                        this.mensajes.push({
                            id: e.mensaje_id || Date.now(),
                            tipo: e.tipo || 'text',
                            contenido: e.contenido,
                            es_entrante: true,
                            usuario_nombre: null,
                            plantilla_nombre: null,
                            tiene_media: e.tipo !== 'text' && e.tipo !== 'template',
                            media_url: e.media_url || null,
                            media_nombre: e.media_nombre || null,
                            media_mime_type: e.media_mime_type || null,
                            hora: String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0'),
                            icono_estado: null,
                        });
                        
                        this.scrollBottom();
                        // Marcar como leído
                        fetch(`/admin/whatsapp/chat/${this.convId}/leer`, {
                            method: 'PATCH',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                        });
                    }

                    // 2. Reordenar y actualizar barra lateral en tiempo real para cualquier conversación
                    const convIdTarget = e.conversacion_id;
                    let conv = this.listaConversaciones.find(c => c.id == convIdTarget);

                    if (conv) {
                        // Conversación ya conocida — actualizar en el sidebar
                        conv.preview = e.contenido || '📎 ' + e.tipo;
                        conv.ultimo_mensaje_at = ahora.toISOString();

                        const hrs  = String(ahora.getHours()).padStart(2, '0');
                        const mins = String(ahora.getMinutes()).padStart(2, '0');
                        conv.hora_display = `${hrs}:${mins}`;

                        // Si no es la conversación abierta, sumar no leído
                        if (convIdTarget != this.convId) {
                            conv.total_mensajes_no_leidos++;
                            this.totalNoLeidos++;
                        }

                        // El cliente escribió: queda esperando y se le abren 24 h de ventana.
                        // Si lo que llegó es nuestro (bot o acuse), deja de esperar.
                        if (e.direccion === 'entrante') this.marcarEsperando(conv);
                        else this.marcarRespondida(conv);

                        this.ordenarConversaciones();

                    } else {
                        // Conversación NUEVA (contacto que nunca había escrito)
                        // Traemos sus datos del servidor y la agregamos al sidebar
                        try {
                            const sidebarResp = await fetch(`/admin/whatsapp/chat/${convIdTarget}/api-sidebar`);
                            const sidebarData = await sidebarResp.json();
                            if (sidebarData.ok && sidebarData.conversacion) {
                                // Con un filtro de tipo activo, el contador de no leídos
                                // sigue contando todo el inbox, pero la fila solo entra a
                                // la lista si pertenece al tipo que se está mirando.
                                this.totalNoLeidos += sidebarData.conversacion.total_mensajes_no_leidos;
                                if (sidebarData.conversacion.esperando) this.totalEsperando++;
                                if (!this.tipoFiltro || sidebarData.conversacion.tipo_contacto === this.tipoFiltro) {
                                    this.listaConversaciones.unshift(sidebarData.conversacion);
                                    this.ordenarConversaciones();
                                }
                            }
                        } catch (err) {
                            console.warn('No se pudo cargar la conversación nueva al sidebar:', err);
                        }
                    }
                })
                .listen('.conversacion.actualizada', (e) => {
                    // Mantiene sincronizados a OTROS asesores viendo el mismo inbox cuando alguien
                    // más asigna/libera una conversación o el bot escala a un humano — antes este
                    // evento no tenía listener y el sidebar solo se actualizaba al recargar la página.
                    const convItem = this.listaConversaciones.find(c => c.id == e.conversacion_id);
                    if (convItem) {
                        convItem.estado             = e.estado;
                        convItem.asignado_a         = e.asignado_a;
                        convItem.asignado_nombre    = e.asignado_nombre;
                        convItem.bot_activo         = e.bot_activo;
                        convItem.pendiente_atencion = e.pendiente_atencion;
                        convItem.atendida_por_ia    = e.atendida_por_ia;
                        if (e.pendiente_atencion && !convItem.esperando) {
                            convItem.esperando = true;
                            convItem.esperando_desde = convItem.esperando_desde || e.ultimo_mensaje || new Date().toISOString();
                            this.totalEsperando++;
                        }
                    }

                    if (e.conversacion_id == this.convId) {
                        this.conversacion.estado             = e.estado;
                        this.conversacion.asignado_a          = e.asignado_a;
                        this.conversacion.asignado_nombre     = e.asignado_nombre;
                        this.conversacion.bot_activo          = e.bot_activo;
                        this.conversacion.pendiente_atencion  = e.pendiente_atencion;
                        this.conversacion.atendida_por_ia     = e.atendida_por_ia;
                        this.usuarioAsignar = e.asignado_a || '';
                    }
                });
        },

        autoResize(event) {
            event.target.style.height = 'auto';
            event.target.style.height = Math.min(event.target.scrollHeight, 120) + 'px';
        },
    };
}
</script>
@endpush
