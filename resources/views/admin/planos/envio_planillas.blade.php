@extends('layouts.app')

@section('titulo', 'Planillas de Seguridad Social')
@section('modulo', 'Envío de Planillas por WhatsApp')

@section('contenido')
<div class="page-container" x-data="enviosPlanillaApp()" x-init="init()">
    
    <style>
        /* Estilos de botones inspirados en el modulo de cobros */
        .wa-pill-btn {
            padding: .45rem 1.25rem;
            border-radius: 50px;
            font-size: .8rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            transition: all .2s ease;
            text-decoration: none;
            outline: none;
            box-sizing: border-box;
            height: 34px;
        }
        .wa-pill-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        .wa-pill-btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #15803d 100%);
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(34, 197, 94, .25);
        }
        .wa-pill-btn-success:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(34, 197, 94, .38);
            background: linear-gradient(135deg, #26d063 0%, #168a41 100%);
        }
        .wa-pill-btn-success:active:not(:disabled) {
            transform: translateY(0);
        }
        .wa-pill-btn-outline {
            background: #fff;
            color: #475569 !important;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 4px rgba(0,0,0,.03);
        }
        .wa-pill-btn-outline:hover:not(:disabled) {
            background: #f8fafc;
            color: #0f172a !important;
            border-color: #94a3b8;
            transform: translateY(-1px);
        }
        .wa-pill-btn-outline:active:not(:disabled) {
            transform: translateY(0);
        }
        .wa-pill-btn-accent {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, .25);
        }
        .wa-pill-btn-accent:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(37, 99, 235, .38);
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
        }
        .wa-pill-btn-accent:active:not(:disabled) {
            transform: translateY(0);
        }
        .wa-pill-btn-warn {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
        }
        .wa-pill-btn-warn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.38);
            background: linear-gradient(135deg, #fbbf24 0%, #b45309 100%);
        }
        .wa-pill-btn-warn:active:not(:disabled) {
            transform: translateY(0);
        }
        .wa-pill-btn-glass {
            background: rgba(255,255,255,0.15);
            color: #fff !important;
            border: 1px solid rgba(255,255,255,0.25);
            backdrop-filter: blur(4px);
        }
        .wa-pill-btn-glass:hover:not(:disabled) {
            background: rgba(255,255,255,0.25);
            transform: translateY(-1px);
        }

        /* Estilos de cabecera oscura y filtros integrados (como en cobros) */
        .tabla-brynex thead th {
            background: #0f172a !important;
            color: #fff !important;
            font-size: 0.76rem !important;
            font-weight: 700 !important;
            padding: 0.55rem 0.6rem !important;
            vertical-align: middle !important;
            border-bottom: 2px solid #1e293b !important;
        }
        .th-select {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.15rem 0;
            cursor: pointer;
            outline: none;
            appearance: auto;
            -webkit-appearance: auto;
        }
        .th-select:hover {
            border-bottom-color: rgba(255, 255, 255, 0.5);
        }
        .th-select:focus {
            border-bottom-color: #3b82f6;
        }
        .th-select option {
            background: #0f172a;
            color: #fff;
            font-weight: 600;
        }
        .th-input {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.15rem 0;
            outline: none;
        }
        .th-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
            font-weight: 700;
        }
        .th-input:hover {
            border-bottom-color: rgba(255, 255, 255, 0.5);
        }
        .th-input:focus {
            border-bottom-color: #3b82f6;
        }
    </style>
    
    {{-- Notificaciones locales --}}
    <div id="local-alerts">
        <template x-if="mensajeExito">
            <div class="notif-success" x-init="setTimeout(() => mensajeExito = '', 5000)">
                <i class="fas fa-check-circle"></i>
                <span x-text="mensajeExito"></span>
                <button @click="mensajeExito = ''" class="notif-close">&times;</button>
            </div>
        </template>
        <template x-if="mensajeError">
            <div class="notif-error" x-init="setTimeout(() => mensajeError = '', 8000)">
                <i class="fas fa-exclamation-circle"></i>
                <span x-text="mensajeError"></span>
                <button @click="mensajeError = ''" class="notif-close">&times;</button>
            </div>
        </template>
    </div>

    {{-- Cabecera del Módulo --}}
    <div class="page-header" style="background: linear-gradient(135deg, var(--azul-oscuro) 0%, var(--azul-medio) 60%, var(--azul-vivo) 100%); padding: 1.25rem 1.5rem; border-radius: 12px; color: #fff; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 class="page-title" style="color: #fff; font-size: 1.4rem; font-weight: 700; margin: 0;">📤 Envíos de Planillas por WhatsApp</h1>
            <p class="page-subtitle" style="color: rgba(255,255,255,0.8); font-size: 0.82rem; margin: 0.25rem 0 0 0;">
                Envía los certificados PDF de planillas pagadas directamente a los celulares de tus clientes de forma masiva o individual.
            </p>
        </div>
        <div class="page-actions" style="display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap;">
            {{-- Contadores en la cabecera --}}
            <div style="display: flex; align-items: center; gap: 1rem; background: rgba(0, 0, 0, 0.15); padding: 0.4rem 1rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);">
                <div style="text-align: right;">
                    <span style="font-size: 0.65rem; color: rgba(255,255,255,0.7); display: block; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Enviados</span>
                    <span style="font-size: 1.25rem; font-weight: 800; color: #10b981; text-shadow: 0 1px 2px rgba(0,0,0,0.15);" x-text="contadorEnviados">0</span>
                </div>
                <div style="width: 1px; height: 22px; background: rgba(255,255,255,0.2);"></div>
                <div style="text-align: right;">
                    <span style="font-size: 0.65rem; color: rgba(255,255,255,0.7); display: block; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Pendientes</span>
                    <span style="font-size: 1.25rem; font-weight: 800; color: #fbbf24; text-shadow: 0 1px 2px rgba(0,0,0,0.15);" x-text="contadorPendientes">0</span>
                </div>
            </div>

            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button class="wa-pill-btn wa-pill-btn-glass" @click="abrirHistorialModal()">
                    <i class="fas fa-history"></i> Historial de Lotes
                </button>
                <template x-if="!plantillaConfigurada">
                    <button class="wa-pill-btn wa-pill-btn-success" @click="crearPlantillaAutomatico()" :disabled="creandoPlantilla">
                        <span x-show="!creandoPlantilla"><i class="fas fa-magic"></i> Crear Plantilla Meta</span>
                        <span x-show="creandoPlantilla" x-cloak><i class="fas fa-spinner fa-spin"></i> Creando...</span>
                    </button>
                </template>
                <template x-if="plantillaConfigurada">
                    <button class="wa-pill-btn wa-pill-btn-glass" @click="crearPlantillaAutomatico(true)" :disabled="creandoPlantilla" title="Recrear la plantilla en Meta (por si fue eliminada o expiró)">
                        <span x-show="!creandoPlantilla"><i class="fas fa-sync-alt"></i> Recrear Plantilla</span>
                        <span x-show="creandoPlantilla" x-cloak><i class="fas fa-spinner fa-spin"></i> Recreando...</span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    {{-- Panel de Filtros y Acciones Unificado (Una Sola Fila Compacta) --}}
    <div class="card-tabla" style="padding: 0.6rem 1rem; margin-bottom: 1rem; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
            
            {{-- Filtros a la izquierda --}}
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; flex: 1;">
                
                {{-- Selector Año/Mes --}}
                <div style="display: flex; align-items: center; gap: 0.25rem;">
                    <span style="font-size: 0.72rem; font-weight: 700; color: #475569; text-transform: uppercase;">Periodo:</span>
                    <select x-model="filtroMes" @change="cargarDestinatarios()" style="padding: 0.3rem 0.5rem; font-size: 0.78rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; width: 95px; height: 30px; font-weight: 600;">
                        @foreach(range(1,12) as $m)
                            <option value="{{ $m }}">{{ \Carbon\Carbon::create()->month($m)->locale('es')->monthName }}</option>
                        @endforeach
                    </select>
                    <select x-model="filtroAnio" @change="cargarDestinatarios()" style="padding: 0.3rem 0.5rem; font-size: 0.78rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; width: 75px; height: 30px; font-weight: 600;">
                        @foreach(range(2024, now()->year + 1) as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                    </select>
                </div>
                
                {{-- Selector Destinatarios --}}
                <div style="display: flex; align-items: center; gap: 0.25rem;">
                    <span style="font-size: 0.72rem; font-weight: 700; color: #475569; text-transform: uppercase;">Destinatarios:</span>
                    <select x-model="tipoEnvio" @change="cargarDestinatarios()" style="padding: 0.3rem 0.5rem; font-size: 0.78rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; width: 175px; height: 30px; font-weight: 600;">
                        <option value="individual">Clientes Individuales</option>
                        <option value="empleado_empresa">Clientes dentro de Empresa</option>
                        <option value="contacto_empresa">Contacto de la Empresa</option>
                    </select>
                </div>

                {{-- Selector Estado Envío --}}
                <div style="display: flex; align-items: center; gap: 0.25rem;">
                    <span style="font-size: 0.72rem; font-weight: 700; color: #475569; text-transform: uppercase;">Estado:</span>
                    <select x-model="estadoFiltro" @change="cargarDestinatarios()" style="padding: 0.3rem 0.5rem; font-size: 0.78rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; width: 155px; height: 30px; font-weight: 600;">
                        <option value="pendientes">Pendientes / Fallidos</option>
                        <option value="enviados">Enviados</option>
                        <option value="entregados">Entregados</option>
                        <option value="sin_confirmar">Sin confirmar entrega</option>
                        <option value="fallidos">Fallidos</option>
                        <option value="omitidos">Omitidos</option>
                        <option value="todos">Todos</option>
                    </select>
                </div>

                {{-- Buscador Global Compacto --}}
                <div style="position: relative; width: 180px;">
                    <span style="position: absolute; left: 0.6rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.75rem;">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" x-model="busquedaGlobal" placeholder="Buscar..." 
                           style="width: 100%; padding: 0.3rem 0.5rem 0.3rem 1.7rem; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.78rem; background: #fff; height: 30px;"
                           @input.debounce.300ms="aplicarFiltrosTabla()">
                </div>
            </div>

            {{-- Botones a la derecha --}}
            <div style="display: flex; gap: 0.4rem; align-items: center;">
                <button class="wa-pill-btn wa-pill-btn-warn" style="height: 30px; padding: 0 .9rem; font-size: 0.74rem;" @click="abrirPruebaModal()" :disabled="cargando || !plantillaConfigurada">
                    <i class="fas fa-vial"></i> Enviar Prueba
                </button>
                <button class="wa-pill-btn wa-pill-btn-success" style="height: 30px; padding: 0 .9rem; font-size: 0.74rem;" :disabled="cargando || seleccionadosCount === 0 || !plantillaConfigurada" @click="lanzarEnvioMasivo()">
                    <span x-show="!enviandoMasivo">
                        <i class="fas fa-paper-plane"></i> Enviar a Seleccionados (<span x-text="seleccionadosCount"></span>)
                    </span>
                    <span x-show="enviandoMasivo" x-cloak>
                        <i class="fas fa-spinner fa-spin"></i> Enviando...
                    </span>
                </button>
            </div>
            
        </div>
    </div>

    {{-- Vista de Carga --}}
    <div x-show="cargando" class="card-tabla" style="padding: 3rem; text-align: center; border-radius: 12px; background: #fff; border: 1px solid #e2e8f0;">
        <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--azul-btn);"></i>
        <p style="margin-top: 1rem; color: #64748b; font-weight: 500;">Buscando planillas pagadas...</p>
    </div>

    {{-- Tabla de Destinatarios --}}
    <div class="card-tabla" x-show="!cargando" style="background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <table class="tabla-brynex" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #0f172a;">
                    <th style="width: 40px; text-align: center; padding: 0.55rem 0.6rem;">
                        <input type="checkbox" x-model="seleccionarTodos" @change="toggleSeleccionarTodos()" :disabled="filtrados.length === 0">
                    </th>
                    <th style="text-align: left; min-width: 180px; padding: 0.55rem 0.6rem;">
                        <input type="text" x-model="filtroNombre" @input="aplicarFiltrosTabla()" placeholder="↓ Cliente" class="th-input" style="text-align: left; padding-left: 0.25rem;">
                    </th>
                    <th style="min-width: 100px; padding: 0.55rem 0.6rem;">
                        <input type="text" x-model="filtroCedula" @input="aplicarFiltrosTabla()" placeholder="↓ Identificación" class="th-input">
                    </th>
                    <th style="min-width: 130px; padding: 0.55rem 0.6rem;">
                        <select x-model="filtroOperador" @change="aplicarFiltrosTabla()" class="th-select">
                            <option value="">↓ Operador</option>
                            <option value="">Todos</option>
                            <template x-for="op in listaOperadores" :key="op">
                                <option :value="op" x-text="op"></option>
                            </template>
                        </select>
                    </th>
                    <th style="min-width: 100px; padding: 0.55rem 0.6rem;">
                        <input type="text" x-model="filtroPlanilla" @input="aplicarFiltrosTabla()" placeholder="↓ Nº Planilla" class="th-input">
                    </th>
                    <th style="min-width: 140px; padding: 0.55rem 0.6rem;">
                        <input
                            type="text"
                            x-model="filtroEmpresa"
                            @input="aplicarFiltrosTabla()"
                            list="empresas-datalist"
                            placeholder="↓ Empresa Cliente"
                            class="th-input"
                        >
                        <datalist id="empresas-datalist">
                            <template x-for="emp in listaEmpresas" :key="emp">
                                <option :value="emp" x-text="emp"></option>
                            </template>
                        </datalist>
                    </th>
                    <th style="min-width: 110px; color: #fff; font-size: 0.74rem; text-align: center; padding: 0.55rem 0.6rem;">
                        WhatsApp
                    </th>
                    <th style="min-width: 130px; color: #fff; font-size: 0.74rem; text-align: center; padding: 0.55rem 0.6rem;">
                        Estado / Envío
                    </th>
                    <th style="min-width: 150px; text-align: center; color: #fff; font-size: 0.74rem; padding: 0.55rem 0.6rem;">
                        Acciones
                    </th>
                </tr>
            </thead>
            <tbody>
                <template x-for="d in filtrados" :key="d.plano_id">
                    <tr :style="d.es_operador_autorizado === false ? 'border-bottom: 1px solid #e2e8f0; font-size: 0.82rem; opacity: 0.55;' : 'border-bottom: 1px solid #e2e8f0; font-size: 0.82rem;'">
                        <td style="padding: 0.6rem 0.75rem; text-align: center;">
                            <input
                                type="checkbox"
                                x-model="d.seleccionado"
                                @change="verificarSeleccionIndividual()"
                                :disabled="d.es_operador_autorizado === false"
                                :title="d.es_operador_autorizado === false ? 'Operador no autorizado para envío PDF' : ''"
                            >
                        </td>
                        {{-- Columna Cliente: siempre muestra el nombre del afiliado --}}
                        <td style="padding: 0.6rem 0.75rem; font-weight: 600; color: #1e293b;">
                            <span x-text="d.cliente_nombre || d.nombre_destinatario"></span>
                            {{-- Indicador de contacto empresa cuando aplica --}}
                            <template x-if="d.contacto_nombre">
                                <div style="font-size: 0.65rem; color: #64748b; font-weight: 400; margin-top: 0.1rem;">
                                    📬 <span x-text="'Envía a: ' + d.contacto_nombre"></span>
                                </div>
                            </template>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; color: #475569;" x-text="d.cliente_cedula"></td>
                        <td style="padding: 0.6rem 0.75rem; color: #475569;">
                            <span x-text="d.operador_nombre"></span>
                            <template x-if="d.sin_clave_miplanilla">
                                <span style="display: inline-block; margin-left: 4px; font-size: 0.65rem; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; border-radius: 4px; padding: 0 4px;" title="Falta la clave de Mi Planilla de esta persona. Guárdala en Claves (entidad MI PLANILLA) o desde el botón Planilla del historial.">🔑 Sin clave</span>
                            </template>
                            <template x-if="d.es_operador_autorizado === false && !d.sin_clave_miplanilla">
                                <span style="display: inline-block; margin-left: 4px; font-size: 0.65rem; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; border-radius: 4px; padding: 0 4px;" title="Sin plantilla PDF autorizada">⚠️ Sin PDF</span>
                            </template>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; font-family: monospace; color: #475569;">
                            <span x-text="d.numero_planilla"></span>
                            <span x-show="d.soporte_operador_listo" title="El PDF del operador ya está en el servidor: se envía el soporte real"
                                  style="margin-left: 0.3rem; font-family: inherit; font-size: 0.65rem; font-weight: 600; color: #15803d; background: #dcfce7; border-radius: 999px; padding: 0.05rem 0.4rem;">✔ real</span>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; color: #475569;" x-text="d.empresa_nombre"></td>
                        <td style="padding: 0.6rem 0.75rem; color: #475569; text-align: center;" x-text="d.wa_numero || 'Sin Celular'"></td>
                        <td style="padding: 0.6rem 0.75rem; text-align: center;">
                            {{-- «Sin envío» es un pronóstico, no un estado: vale
                                 mientras no haya intento registrado. Si el lote ya
                                 pasó por esta planilla, el estado real manda, o un
                                 «omitido» se queda sin verse nunca. --}}
                            <template x-if="d.es_operador_autorizado === false && (d.envio_state || d.envio_estado) === 'pendiente'">
                                <span class="badge-info" style="background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db;" :title="d.sin_clave_miplanilla ? 'Falta la clave de Mi Planilla de esta persona' : 'El operador de esta planilla no tiene plantilla PDF autorizada para WhatsApp'">⚠️ Sin envío</span>
                            </template>
                            <template x-if="d.es_operador_autorizado !== false || (d.envio_state || d.envio_estado) !== 'pendiente'">
                                <span :class="badgeEstado(d.envio_state || d.envio_estado)" x-text="etiquetaEstado(d.envio_state || d.envio_estado)" :title="d.envio_error || ''"></span>
                            </template>
                            <div x-show="d.envio_fecha" style="font-size: 0.65rem; color: #64748b; margin-top: 0.2rem;" x-text="formatearFecha(d.envio_fecha)"></div>
                            {{-- El motivo, que es lo que uno viene a buscar cuando ve
                                 una planilla omitida o fallida. --}}
                            <template x-if="d.envio_error && (d.envio_state || d.envio_estado) !== 'enviado'">
                                <div style="font-size: 0.62rem; color: #b45309; margin-top: 0.2rem; line-height: 1.3;" x-text="d.envio_error"></div>
                            </template>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; text-align: center;">
                            <div style="display: flex; gap: 0.3rem; justify-content: center; align-items: center;">
                                <a :href="`/admin/planos/certificado-pdf?cedula=${d.cliente_cedula}&numero_planilla=${d.numero_planilla}&forzar_operador_id=${d.operador_id}`" target="_blank" class="wa-pill-btn wa-pill-btn-outline" style="padding: 0 0.5rem; font-size: 0.72rem; height: 26px; border-color: #f87171; color: #dc2626 !important; background: #fef2f2;">
                                    <i class="far fa-file-pdf"></i> PDF
                                </a>
                                <button
                                    class="wa-pill-btn wa-pill-btn-success"
                                    style="padding: 0 0.6rem; font-size: 0.72rem; height: 26px;"
                                    @click="abrirConfirmacionIndividual(d)"
                                    :disabled="reenviandoId === d.plano_id || !plantillaConfigurada || d.es_operador_autorizado === false"
                                    :title="d.es_operador_autorizado === false ? 'Operador no autorizado para envío PDF' : 'Enviar planilla por WhatsApp'"
                                >
                                    <span x-show="reenviandoId !== d.plano_id"><i class="fab fa-whatsapp"></i> Enviar</span>
                                    <span x-show="reenviandoId === d.plano_id" x-cloak><i class="fas fa-spinner fa-spin"></i></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-if="filtrados.length === 0">
                    <tr>
                        <td colspan="9" class="tabla-vacia" style="padding: 3rem; text-align: center; color: #94a3b8;">
                            <i class="fas fa-inbox fa-2x"></i>
                            <p style="margin-top: 0.5rem; font-size: 0.88rem;">No se encontraron planillas pagadas que coincidan con los filtros.</p>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- MODAL: Historial de Envíos --}}
    <div x-show="historialModalOpen" class="modal-overlay" style="background: rgba(0,0,0,0.55); position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;" x-cloak @click.self="historialModalOpen = false">
        <div class="modal-box" style="background: #fff; border-radius: 12px; max-width: 800px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.25); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb;">
                <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 600;">📋 Historial de Lotes de WhatsApp</h3>
                <button class="modal-close" style="background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer;" @click="historialModalOpen = false">&times;</button>
            </div>
            <div class="modal-body" style="padding: 1.25rem; overflow-y: auto; flex-grow: 1;">
                <template x-if="cargandoHistorial">
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-spinner fa-spin fa-lg" style="color: var(--azul-btn);"></i>
                        <p style="margin-top: 0.5rem; color: #64748b;">Cargando historial...</p>
                    </div>
                </template>
                <template x-if="!cargandoHistorial">
                    <table class="tabla-brynex" style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                                <th style="padding: 0.5rem;">Fecha</th>
                                <th style="padding: 0.5rem;">Usuario</th>
                                <th style="padding: 0.5rem;">Periodo</th>
                                <th style="padding: 0.5rem;">Tipo</th>
                                <th style="padding: 0.5rem; text-align: center;">Total</th>
                                <th style="padding: 0.5rem; text-align: center;">Enviados</th>
                                <th style="padding: 0.5rem; text-align: center;">Fallidos</th>
                                <th style="padding: 0.5rem; text-align: center;">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="l in historialLotes" :key="l.id">
                                <tr style="border-bottom: 1px solid #e2e8f0; cursor: pointer; transition: background 0.15s;" @click="verDetalleLote(l.id)" class="hover-gasto">
                                    <td style="padding: 0.5rem;" x-text="formatearFecha(l.created_at)"></td>
                                    <td style="padding: 0.5rem;" x-text="l.usuario ? l.usuario.nombre : 'Sistemas'"></td>
                                    <td style="padding: 0.5rem;" x-text="l.mes + '/' + l.anio"></td>
                                    <td style="padding: 0.5rem;" x-text="etiquetaTipoEnvio(l.tipo_envio)"></td>
                                    <td style="padding: 0.5rem; text-align: center;" x-text="l.total_destinatarios"></td>
                                    <td style="padding: 0.5rem; text-align: center; color: #16a34a;" x-text="l.total_enviados"></td>
                                    <td style="padding: 0.5rem; text-align: center; color: #dc2626;" x-text="l.total_fallidos"></td>
                                    <td style="padding: 0.5rem; text-align: center;">
                                        <span :class="badgeLoteEstado(l.estado)" x-text="l.estado"></span>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="historialLotes.length === 0">
                                <tr>
                                    <td colspan="8" style="padding: 2rem; text-align: center; color: #94a3b8;">Sin registros de envío previos.</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
            </div>
            <div class="modal-foot" style="padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end;">
                <button class="wa-pill-btn wa-pill-btn-outline" @click="historialModalOpen = false">Cerrar</button>
            </div>
        </div>
    </div>

    {{-- MODAL: Detalles de un Lote Específico --}}
    <div x-show="detalleLoteModalOpen" class="modal-overlay" style="background: rgba(0,0,0,0.55); position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;" x-cloak @click.self="detalleLoteModalOpen = false">
        <div class="modal-box" style="background: #fff; border-radius: 12px; max-width: 800px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.25); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb;">
                <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 600;">📄 Detalle del Lote #<span x-text="loteDetalleId"></span></h3>
                <button class="modal-close" style="background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer;" @click="detalleLoteModalOpen = false">&times;</button>
            </div>
            <div class="modal-body" style="padding: 1.25rem; overflow-y: auto; flex-grow: 1;">
                <div style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.8rem; display: flex; justify-content: space-around;">
                    <div><strong>Estado:</strong> <span x-text="loteDetalleObj?.estado"></span></div>
                    <div><strong>Destinatarios:</strong> <span x-text="loteDetalleObj?.total_destinatarios"></span></div>
                    <div><strong>Enviados:</strong> <span x-text="loteDetalleObj?.total_enviados" style="color:#16a34a"></span></div>
                    <div><strong>Fallidos:</strong> <span x-text="loteDetalleObj?.total_fallidos" style="color:#dc2626"></span></div>
                </div>
                <template x-if="cargandoLoteDetalle">
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-spinner fa-spin fa-lg" style="color: var(--azul-btn);"></i>
                        <p style="margin-top: 0.5rem; color: #64748b;">Cargando detalles...</p>
                    </div>
                </template>
                <template x-if="!cargandoLoteDetalle">
                    <table class="tabla-brynex" style="width: 100%; font-size: 0.78rem; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                                <th style="padding: 0.5rem;">Destinatario</th>
                                <th style="padding: 0.5rem;">Cédula</th>
                                <th style="padding: 0.5rem;">Número Planilla</th>
                                <th style="padding: 0.5rem;">Teléfono</th>
                                <th style="padding: 0.5rem; text-align: center;">Estado</th>
                                <th style="padding: 0.5rem;">Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="d in loteDetallesList" :key="d.id">
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 0.5rem;" x-text="d.nombre_destinatario"></td>
                                    <td style="padding: 0.5rem;" x-text="d.cliente_cedula"></td>
                                    <td style="padding: 0.5rem;" x-text="d.numero_planilla"></td>
                                    <td style="padding: 0.5rem;" x-text="d.wa_numero"></td>
                                    <td style="padding: 0.5rem; text-align: center;">
                                        <span :class="badgeEstado(d.estado)" x-text="d.estado"></span>
                                    </td>
                                    <td style="padding: 0.5rem; color: #dc2626; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" :title="d.error" x-text="d.error || 'N/A'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
            </div>
            <div class="modal-foot" style="padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between;">
                <button class="wa-pill-btn wa-pill-btn-outline" @click="detalleLoteModalOpen = false">Cerrar</button>
                <button class="wa-pill-btn wa-pill-btn-accent" @click="detalleLoteModalOpen = false; historialModalOpen = true;"><i class="fas fa-arrow-left"></i> Volver</button>
            </div>
        </div>
    </div>

    {{-- MODAL: Enviar Mensaje de Prueba --}}
    <div x-show="pruebaModalOpen" class="modal-overlay" style="background: rgba(0,0,0,0.55); position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;" x-cloak @click.self="pruebaModalOpen = false">
        <div class="modal-box" style="background: #fff; border-radius: 16px; max-width: 820px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; animation: mIn .18s ease;">
            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb;">
                <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-vial" style="color: #f59e0b;"></i> Enviar WhatsApp de Prueba
                </h3>
                <button class="modal-close" style="background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer;" @click="pruebaModalOpen = false">&times;</button>
            </div>
            
            <div class="modal-body" style="padding: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; max-height: 70vh; overflow-y: auto;">
                {{-- Columna Izquierda: Formulario --}}
                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem;">
                        <label class="form-label" style="font-weight: 700; font-size: 0.8rem; color: #334155; display: block; margin-bottom: 0.5rem;">📱 Celular de Destino</label>
                        <input type="text" x-model="waPruebaCelular" placeholder="Ej: 3123456789" class="form-control" style="width: 100%; padding: 0.55rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.88rem; background: #fff;">
                        <small style="color: #64748b; font-size: 0.72rem; margin-top: 0.35rem; display: block; line-height: 1.3;">
                            Ingresa el número de WhatsApp al que le llegará este mensaje de prueba.
                        </small>
                    </div>
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem;">
                        <label class="form-label" style="font-weight: 700; font-size: 0.8rem; color: #334155; display: block; margin-bottom: 0.5rem;">👤 Cliente para Simular</label>
                        <select x-model="planoPruebaId" class="form-control" style="width: 100%; padding: 0.55rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.88rem; background: #fff;">
                            <option value="">-- Seleccionar Destinatario --</option>
                            <template x-for="d in filtrados" :key="d.plano_id">
                                <option :value="d.plano_id" x-text="`${d.nombre_destinatario} (Planilla: ${d.numero_planilla || 'N/A'})`"></option>
                            </template>
                        </select>
                        <small style="color: #64748b; font-size: 0.72rem; margin-top: 0.35rem; display: block; line-height: 1.3;">
                            Los datos de este cliente (Nombre, Operador y N° de Planilla) se mapearán automáticamente en la plantilla de previsualización.
                        </small>
                    </div>
                </div>
                
                {{-- Columna Derecha: Mockup de Celular / Vista Previa --}}
                <div style="display: flex; flex-direction: column;">
                    <div style="font-size: 0.72rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.25rem;">
                        <i class="fab fa-whatsapp" style="color: #25d366; font-size: 1rem;"></i> Vista previa del mensaje
                    </div>
                    
                    {{-- Celular Container --}}
                    <div style="background: #e5ddd5; border-radius: 12px; padding: 1rem; border: 1px solid #cbd5e1; min-height: 260px; display: flex; flex-direction: column; justify-content: flex-start; background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-repeat: repeat; box-shadow: inset 0 2px 8px rgba(0,0,0,0.08);">
                        
                        {{-- Burbuja de chat saliente (verde claro) --}}
                        <div x-show="planoPruebaId" class="wa-bubble-msg" style="background: #d9fdd3; border-radius: 8px 8px 0 8px; padding: 0.6rem; box-shadow: 0 1px 2px rgba(0,0,0,0.15); max-width: 88%; align-self: flex-end; position: relative;" x-cloak>
                            
                            {{-- Header DOCUMENT (PDF) --}}
                            <div style="background: #cfe9ba; border-radius: 6px; padding: 0.55rem; display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.6rem; border-left: 4px solid #ef4444;">
                                <i class="far fa-file-pdf fa-2x" style="color: #dc2626;"></i>
                                <div style="display: flex; flex-direction: column; overflow: hidden; width: 100%;">
                                    <span style="font-size: 0.78rem; font-weight: 600; color: #1e293b; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" x-text="nombrePdfPrevisualizado"></span>
                                    <span style="font-size: 0.65rem; color: #64748b;">PDF • 1 página • 142 KB</span>
                                </div>
                            </div>
                            
                            {{-- Cuerpo del mensaje --}}
                            <div style="font-size: 0.8rem; color: #111b21; white-space: pre-wrap; word-break: break-word; line-height: 1.45;" x-html="textoPrevisualizadoFormateado"></div>
                            
                            {{-- Footer: Hora y doble check azul --}}
                            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 0.2rem; margin-top: 0.25rem; font-size: 0.65rem; color: #667781; opacity: 0.9;">
                                <span x-text="new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})"></span>
                                <span style="color: #53bdeb;"><i class="fas fa-check-double"></i></span>
                            </div>
                        </div>
                        
                        {{-- Mensaje de seleccion --}}
                        <div x-show="!planoPruebaId" style="display: flex; align-items: center; justify-content: center; height: 100%; min-height: 200px; color: #64748b; font-size: 0.82rem; text-align: center; background: rgba(255,255,255,0.7); border-radius: 8px; padding: 1rem;">
                            <div>
                                <i class="far fa-comment-alt fa-2x" style="margin-bottom: 0.5rem; color: #94a3b8;"></i>
                                <p style="margin: 0;">Selecciona un destinatario para ver la previsualización del WhatsApp en tiempo real.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-foot" style="padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; background: #f8fafc;">
                {{-- Resultado del envío de prueba --}}
                <template x-if="pruebaResultado">
                    <div :style="pruebaResultado.ok
                            ? 'background:#f0fdf4; border:1px solid #86efac; color:#166534; border-radius:8px; padding:0.65rem 0.85rem; font-size:0.82rem; margin-bottom:0.75rem; display:flex; align-items:flex-start; gap:0.5rem;'
                            : 'background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:8px; padding:0.65rem 0.85rem; font-size:0.82rem; margin-bottom:0.75rem; display:flex; align-items:flex-start; gap:0.5rem;'"
                    >
                        <i :class="pruebaResultado.ok ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'" style="margin-top:0.1rem; flex-shrink:0;"></i>
                        <span x-text="pruebaResultado.mensaje"></span>
                    </div>
                </template>
                <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                    <button class="wa-pill-btn wa-pill-btn-outline" @click="pruebaModalOpen = false; pruebaResultado = null;">Cerrar</button>
                    <button class="wa-pill-btn wa-pill-btn-warn" @click="ejecutarEnvioPrueba()" :disabled="enviandoPrueba || !waPruebaCelular || !planoPruebaId">
                        <span x-show="!enviandoPrueba"><i class="fas fa-paper-plane"></i> Enviar Prueba</span>
                        <span x-show="enviandoPrueba" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL: Confirmar Envío Masivo --}}
    <div x-show="confirmarMasivoModalOpen" class="modal-overlay" style="background: rgba(0,0,0,0.55); position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;" x-cloak @click.self="confirmarMasivoModalOpen = false">
        <div class="modal-box" style="background: #fff; border-radius: 16px; max-width: 650px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; animation: mIn .18s ease;">
            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; background: #f8fafc;">
                <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-check-double" style="color: #10b981;"></i> Confirmar Envío Masivo
                </h3>
                <button class="modal-close" style="background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer;" @click="confirmarMasivoModalOpen = false">&times;</button>
            </div>
            
            <div class="modal-body" style="padding: 1.5rem; max-height: 60vh; overflow-y: auto;">
                {{-- Banner informativo --}}
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 0.85rem; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 0.5rem;">
                    <i class="fas fa-info-circle" style="color: #3b82f6; margin-top: 0.15rem; flex-shrink: 0;"></i>
                    <div style="font-size: 0.82rem; color: #1e3a8a; line-height: 1.4;">
                        <span style="font-weight: 700;">Resumen del Envío:</span>
                        Se enviará la planilla de WhatsApp con el PDF correspondiente a los <span x-text="seleccionadosCount" style="font-weight: 700;"></span> destinatarios seleccionados.
                        <template x-if="tipoEnvio === 'contacto_empresa'">
                            <div style="margin-top: 0.4rem; font-weight: 600; color: #b91c1c;">
                                ⚠️ ATENCIÓN: Las planillas NO van al celular de cada trabajador, sino al celular registrado de su empresa. Verifique los números antes de continuar.
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Tabla de Destinatarios --}}
                <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.78rem;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 1px solid #cbd5e1; text-align: left; color: #475569; font-weight: 600;">
                                <th style="padding: 0.5rem 0.75rem;">Cliente</th>
                                <th style="padding: 0.5rem 0.75rem;">Empresa</th>
                                <th style="padding: 0.5rem 0.75rem;">Destinatario / Celular</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="d in seleccionadosParaConfirmar" :key="d.plano_id">
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 0.5rem 0.75rem; font-weight: 600; color: #1e293b;" x-text="d.cliente_nombre || d.nombre_destinatario"></td>
                                    <td style="padding: 0.5rem 0.75rem; color: #475569;" x-text="d.empresa_nombre"></td>
                                    <td style="padding: 0.5rem 0.75rem; color: #1e293b;">
                                        <template x-if="tipoEnvio === 'contacto_empresa'">
                                            <div>
                                                <span style="font-weight: 600;" x-text="d.wa_numero || 'Sin Celular'"></span>
                                                <div style="font-size: 0.7rem; color: #64748b;">
                                                    Celular de la empresa<span x-show="d.contacto_nombre" x-text="' · contacto: ' + (d.contacto_nombre || '')"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="tipoEnvio !== 'contacto_empresa'">
                                            <div>
                                                <span x-text="d.wa_numero || 'Sin Celular'"></span>
                                            </div>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="modal-foot" style="padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; background: #f8fafc;">
                {{-- Resultado del envío masivo --}}
                <template x-if="confirmarResultado">
                    <div :style="confirmarResultado.ok
                            ? 'background:#f0fdf4; border:1px solid #86efac; color:#166534; border-radius:8px; padding:0.65rem 0.85rem; font-size:0.82rem; margin-bottom:0.75rem; display:flex; align-items:flex-start; gap:0.5rem;'
                            : 'background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:8px; padding:0.65rem 0.85rem; font-size:0.82rem; margin-bottom:0.75rem; display:flex; align-items:flex-start; gap:0.5rem;'"
                    >
                        <i :class="confirmarResultado.ok ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'" style="margin-top:0.1rem; flex-shrink:0;"></i>
                        <span x-text="confirmarResultado.mensaje"></span>
                    </div>
                </template>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                    {{-- Si ya se envió con éxito, solo mostrar botón de Aceptar --}}
                    <template x-if="confirmarResultado && confirmarResultado.ok">
                        <button class="wa-pill-btn wa-pill-btn-success" @click="confirmarMasivoModalOpen = false; confirmarResultado = null;">Aceptar</button>
                    </template>

                    {{-- Si no se ha enviado o hubo error, mostrar botones normales --}}
                    <template x-if="!confirmarResultado || !confirmarResultado.ok">
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="wa-pill-btn wa-pill-btn-outline" @click="confirmarMasivoModalOpen = false; confirmarResultado = null;" :disabled="enviandoMasivo">Cancelar</button>
                            <button class="wa-pill-btn wa-pill-btn-success" @click="ejecutarEnvioMasivoConfirmado()" :disabled="enviandoMasivo">
                                <span x-show="!enviandoMasivo"><i class="fas fa-paper-plane"></i> Confirmar y Enviar</span>
                                <span x-show="enviandoMasivo" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando Lote...</span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL: Confirmar Envío Individual --}}
    {{-- Antes el botón «Enviar» de la tabla despachaba de una. Con «Contacto de
         la Empresa» eso significa que la planilla de un trabajador sale al
         celular de su empresa, y el operador no tenía dónde verlo antes de
         hacer clic. --}}
    <div x-show="confirmarIndividualModalOpen" class="modal-overlay" style="background: rgba(0,0,0,0.55); position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;" x-cloak @click.self="cerrarConfirmacionIndividual()">
        <div class="modal-box" style="background: #fff; border-radius: 16px; max-width: 540px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; animation: mIn .18s ease;">
            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; background: #f8fafc;">
                <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fab fa-whatsapp" style="color: #10b981;"></i> ¿A quién le llega esta planilla?
                </h3>
                <button class="modal-close" style="background: none; border: none; font-size: 1.4rem; color: #94a3b8; cursor: pointer;" @click="cerrarConfirmacionIndividual()">&times;</button>
            </div>

            <div class="modal-body" style="padding: 1.5rem; max-height: 65vh; overflow-y: auto;" x-show="individualAConfirmar">
                {{-- El destinatario, que es lo que se viene a confirmar --}}
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 1rem; margin-bottom: 1.15rem;">
                    <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: #15803d; font-weight: 700; margin-bottom: 0.4rem;">
                        El mensaje llega a
                    </div>
                    <div style="font-size: 1rem; font-weight: 700; color: #14532d;" x-text="destinatarioIndividual.quien"></div>
                    <div style="font-family: monospace; font-size: 1.05rem; color: #166534; margin-top: 0.25rem;" x-text="destinatarioIndividual.numeroVisible"></div>
                    <div style="font-size: 0.75rem; color: #15803d; margin-top: 0.35rem;" x-text="destinatarioIndividual.detalle"></div>
                </div>

                {{-- Cuando el número no se parece a un celular colombiano: es la
                     forma de ver a tiempo un campo con dos números metidos. --}}
                <template x-if="destinatarioIndividual.advertencia">
                    <div style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 0.85rem; margin-bottom: 1.15rem; display: flex; align-items: flex-start; gap: 0.5rem;">
                        <i class="fas fa-exclamation-triangle" style="color: #d97706; margin-top: 0.15rem; flex-shrink: 0;"></i>
                        <div style="font-size: 0.8rem; color: #92400e; line-height: 1.45;" x-text="destinatarioIndividual.advertencia"></div>
                    </div>
                </template>

                {{-- Qué se envía --}}
                <div style="border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                        <tbody>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.6rem 0.85rem; color: #64748b; width: 40%;">Planilla de</td>
                                <td style="padding: 0.6rem 0.85rem; color: #1e293b; font-weight: 600;" x-text="individualAConfirmar?.cliente_nombre || individualAConfirmar?.nombre_destinatario"></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.6rem 0.85rem; color: #64748b;">Cédula</td>
                                <td style="padding: 0.6rem 0.85rem; color: #475569;" x-text="individualAConfirmar?.cliente_cedula"></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.6rem 0.85rem; color: #64748b;">Empresa</td>
                                <td style="padding: 0.6rem 0.85rem; color: #475569;" x-text="individualAConfirmar?.empresa_nombre"></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.6rem 0.85rem; color: #64748b;">N° de planilla</td>
                                <td style="padding: 0.6rem 0.85rem; color: #475569; font-family: monospace;" x-text="individualAConfirmar?.numero_planilla"></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.6rem 0.85rem; color: #64748b;">Operador</td>
                                <td style="padding: 0.6rem 0.85rem; color: #475569;" x-text="individualAConfirmar?.operador_nombre"></td>
                            </tr>
                            <tr>
                                <td style="padding: 0.6rem 0.85rem; color: #64748b;">Periodo</td>
                                <td style="padding: 0.6rem 0.85rem; color: #475569;" x-text="etiquetaTipoEnvio(tipoEnvio) + ' · ' + (individualAConfirmar?.periodo_mes || filtroMes) + '/' + (individualAConfirmar?.periodo_anio || filtroAnio)"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- Un reenvío sobre algo ya enviado es un duplicado para quien recibe --}}
                <template x-if="['enviado', 'entregado', 'leido'].includes(individualAConfirmar?.envio_estado)">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 0.85rem; margin-top: 1.15rem; display: flex; align-items: flex-start; gap: 0.5rem;">
                        <i class="fas fa-info-circle" style="color: #3b82f6; margin-top: 0.15rem; flex-shrink: 0;"></i>
                        <div style="font-size: 0.8rem; color: #1e3a8a; line-height: 1.45;">
                            Esta planilla ya figura como enviada. Si continúas, al destinatario le llegará por segunda vez.
                        </div>
                    </div>
                </template>
            </div>

            <div class="modal-foot" style="padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; background: #f8fafc; display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button class="wa-pill-btn wa-pill-btn-outline" @click="cerrarConfirmacionIndividual()" :disabled="reenviandoId !== null">Cancelar</button>
                <button class="wa-pill-btn wa-pill-btn-success" @click="confirmarEnvioIndividual()" :disabled="reenviandoId !== null || !destinatarioIndividual.numeroVisible">
                    <span x-show="reenviandoId === null"><i class="fas fa-paper-plane"></i> Confirmar y Enviar</span>
                    <span x-show="reenviandoId !== null" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando...</span>
                </button>
            </div>
        </div>
    </div>

</div>

<script>
function enviosPlanillaApp() {
    return {
        // Filtros
        filtroAnio: @json($anio),
        filtroMes: @json($mes),
        tipoEnvio: 'individual',
        estadoFiltro: 'pendientes',
        busquedaGlobal: '',
        
        // Filtros específicos por columna
        filtroNombre: '',
        filtroCedula: '',
        filtroOperador: '',
        filtroPlanilla: '',
        filtroEmpresa: '',

        // Datos
        destinatarios: [],
        filtrados: [],
        listaOperadores: [],
        listaEmpresas: [],
        seleccionadosCount: 0,
        seleccionarTodos: false,

        // Estados de UI
        cargando: false,
        enviandoMasivo: false,
        reenviandoId: null,
        creandoPlantilla: false,
        plantillaConfigurada: @json($plantilla ? true : false),
        plantillaNombre: @json($plantilla ? $plantilla->nombre_display : ''),

        // Notificaciones
        mensajeExito: '',
        mensajeError: '',

        // Historial
        historialModalOpen: false,
        cargandoHistorial: false,
        historialLotes: [],
        
        // Detalle de lote
        detalleLoteModalOpen: false,
        cargandoLoteDetalle: false,
        loteDetalleId: null,
        loteDetalleObj: null,
        loteDetallesList: [],

        // Modal de Prueba
        pruebaModalOpen: false,
        enviandoPrueba: false,
        waPruebaCelular: '',
        planoPruebaId: '',
        pruebaResultado: null, // null | {ok: bool, mensaje: string},

        // Modal de Confirmación Masiva
        confirmarMasivoModalOpen: false,
        seleccionadosParaConfirmar: [],
        confirmarResultado: null, // null | {ok: bool, mensaje: string}

        // Modal de Confirmación Individual
        confirmarIndividualModalOpen: false,
        individualAConfirmar: null,

        init() {
            const currentAliadoId = @json(session('aliado_id_activo'));
            const savedAliadoId = sessionStorage.getItem('planilla_ultimo_aliado_id');

            // Si cambió de aliado, limpiar los filtros de la URL para empezar en limpio
            if (savedAliadoId && parseInt(savedAliadoId) !== parseInt(currentAliadoId)) {
                const url = new URL(window.location.href);
                url.search = ''; // Limpiar query string
                window.history.replaceState({}, '', url.toString());
            }

            // Registrar el aliado actual en sessionStorage
            sessionStorage.setItem('planilla_ultimo_aliado_id', currentAliadoId);

            // Recuperar filtros persistidos en los parámetros de la URL
            const urlParams = new URLSearchParams(window.location.search);
            const savedAnio = urlParams.get('anio');
            const savedMes = urlParams.get('mes');
            const savedTipo = urlParams.get('tipo_envio');
            const savedEstado = urlParams.get('estado');
            const savedEmpresa = urlParams.get('empresa');

            if (savedAnio) this.filtroAnio = parseInt(savedAnio);
            if (savedMes) this.filtroMes = parseInt(savedMes);
            if (savedTipo) this.tipoEnvio = savedTipo;
            if (savedEstado) this.estadoFiltro = savedEstado;
            if (savedEmpresa) this.filtroEmpresa = savedEmpresa;

            this.cargarDestinatarios();
        },

        async cargarDestinatarios() {
            // Actualizar filtros en la URL del navegador
            this.actualizarUrlFiltros();

            this.cargando = true;
            this.seleccionarTodos = false;
            
            try {
                const res = await fetch(`/admin/planos/envio-planillas/api?anio=${this.filtroAnio}&mes=${this.filtroMes}&tipo_envio=${this.tipoEnvio}&estado=${this.estadoFiltro}&_=${new Date().getTime()}`);
                const data = await res.json();
                
                if (data.ok) {
                    this.destinatarios = data.data.map(d => {
                        d.seleccionado = false;
                        return d;
                    });
                    
                    // Extraer operadores únicos
                    this.listaOperadores = [...new Set(this.destinatarios.map(d => d.operador_nombre))].filter(Boolean);

                    // Extraer empresas únicas (sin «Individual» en la lista de autocompletado)
                    this.listaEmpresas = [...new Set(
                        this.destinatarios
                            .map(d => d.empresa_nombre)
                            .filter(e => e && e !== 'Individual')
                    )].sort();

                    this.aplicarFiltrosTabla();
                } else {
                    this.mensajeError = data.mensaje || 'Error al obtener destinatarios.';
                }
            } catch (err) {
                console.error(err);
                this.mensajeError = 'Error de conexión con el servidor.';
            } finally {
                this.cargando = false;
            }
        },

        aplicarFiltrosTabla() {
            let resultado = [...this.destinatarios];
            // Sin tildes: "jose" debe encontrar a "José" y al revés.
            const norm = t => (t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

            // Búsqueda global
            if (this.busquedaGlobal.trim() !== '') {
                const search = norm(this.busquedaGlobal.trim());
                resultado = resultado.filter(d => 
                    norm(d.nombre_destinatario).includes(search) ||
                    d.cliente_cedula.includes(search) ||
                    (d.numero_planilla && d.numero_planilla.includes(search)) ||
                    norm(d.empresa_nombre).includes(search)
                );
            }

            // Búsquedas específicas por columna
            if (this.filtroNombre.trim() !== '') {
                const nom = norm(this.filtroNombre.trim());
                resultado = resultado.filter(d => norm(d.nombre_destinatario).includes(nom));
            }
            if (this.filtroCedula.trim() !== '') {
                const ced = this.filtroCedula.trim();
                resultado = resultado.filter(d => d.cliente_cedula.includes(ced));
            }
            if (this.filtroOperador !== '') {
                resultado = resultado.filter(d => d.operador_nombre === this.filtroOperador);
            }
            if (this.filtroPlanilla.trim() !== '') {
                const pla = this.filtroPlanilla.toLowerCase().trim();
                resultado = resultado.filter(d => d.numero_planilla && d.numero_planilla.toLowerCase().includes(pla));
            }
            if (this.filtroEmpresa.trim() !== '') {
                const emp = norm(this.filtroEmpresa.trim());
                resultado = resultado.filter(d => norm(d.empresa_nombre).includes(emp));
            }
            
            // Actualizar empresa en la URL
            this.actualizarUrlFiltros();

            this.filtrados = resultado;
            this.verificarSeleccionIndividual();
        },

        // Las filas de operador no autorizado tienen el checkbox deshabilitado,
        // pero «seleccionar todos» las marcaba igual: el contador prometía más
        // envíos de los posibles y el lote nacía con detalles que el job solo
        // podía omitir.
        get seleccionables() {
            return this.filtrados.filter(d => d.es_operador_autorizado !== false);
        },

        toggleSeleccionarTodos() {
            this.filtrados.forEach(d => {
                d.seleccionado = this.seleccionarTodos && d.es_operador_autorizado !== false;
            });
            this.verificarSeleccionIndividual();
        },

        verificarSeleccionIndividual() {
            const seleccionables = this.seleccionables;
            const activos = seleccionables.filter(d => d.seleccionado);
            this.seleccionadosCount = activos.length;
            this.seleccionarTodos = seleccionables.length > 0 && activos.length === seleccionables.length;
        },

        actualizarUrlFiltros() {
            const url = new URL(window.location.href);
            url.searchParams.set('anio', this.filtroAnio);
            url.searchParams.set('mes', this.filtroMes);
            url.searchParams.set('tipo_envio', this.tipoEnvio);
            url.searchParams.set('estado', this.estadoFiltro);
            if (this.filtroEmpresa.trim() !== '') {
                url.searchParams.set('empresa', this.filtroEmpresa.trim());
            } else {
                url.searchParams.delete('empresa');
            }
            window.history.replaceState({}, '', url.toString());
        },

        lanzarEnvioMasivo() {
            if (this.seleccionadosCount === 0) return;
            
            // Llenar datos de confirmación
            this.confirmarResultado = null;
            this.seleccionadosParaConfirmar = this.seleccionables.filter(d => d.seleccionado);
            this.confirmarMasivoModalOpen = true;
        },

        async ejecutarEnvioMasivoConfirmado() {
            this.enviandoMasivo = true;
            this.confirmarResultado = null;
            this.mensajeExito = '';
            this.mensajeError = '';

            try {
                const seleccionadosPlanoIds = this.seleccionadosParaConfirmar.map(d => d.plano_id);
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                
                const res = await fetch('/admin/planos/envio-planillas/enviar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        anio: this.filtroAnio,
                        mes: this.filtroMes,
                        tipo_envio: this.tipoEnvio,
                        plano_ids: seleccionadosPlanoIds
                    })
                });

                let data;
                try {
                    data = await res.json();
                } catch (e) {
                    data = { ok: false, mensaje: `Error HTTP ${res.status}: No se pudo leer la respuesta del servidor.` };
                }
                
                if (data.ok) {
                    this.confirmarResultado = { ok: true, mensaje: data.mensaje || '\u2705 Lote de envío masivo completado con éxito.' };
                    this.mensajeExito = this.confirmarResultado.mensaje;

                    // Actualizar localmente el estado de los destinatarios enviados en la tabla a 'procesando'
                    seleccionadosPlanoIds.forEach(planoId => {
                        const plano = this.destinatarios.find(d => d.plano_id === planoId);
                        if (plano) {
                            plano.envio_estado = 'procesando';
                            plano.seleccionado = false; // Desmarcar
                        }
                    });
                    
                    // Volver a aplicar los filtros de la tabla localmente sin hacer fetch al backend
                    this.aplicarFiltrosTabla();
                } else {
                    this.confirmarResultado = { ok: false, mensaje: data.mensaje || '\u274C Error al iniciar el envío masivo.' };
                    this.mensajeError = this.confirmarResultado.mensaje;
                }
            } catch (err) {
                console.error(err);
                this.confirmarResultado = { ok: false, mensaje: '\u274C Error al enviar petición al servidor: ' + err.message };
                this.mensajeError = this.confirmarResultado.mensaje;
            } finally {
                this.enviandoMasivo = false;
            }
        },

        // Quién recibe el mensaje, leído del mismo `wa_numero` que el backend
        // va a usar: el selector de destinatarios decide el campo, y la API
        // ya lo resolvió (cl.celular para los dos tipos de cliente,
        // em.celular para contacto de empresa).
        get destinatarioIndividual() {
            const d = this.individualAConfirmar;
            if (!d) {
                return { quien: '', numeroVisible: '', detalle: '', advertencia: '' };
            }

            const esContacto = this.tipoEnvio === 'contacto_empresa';

            return {
                quien: esContacto
                    ? (d.empresa_nombre || 'la empresa')
                    : (d.cliente_nombre || d.nombre_destinatario),
                numeroVisible: d.wa_numero || '',
                detalle: esContacto
                    ? (d.contacto_nombre
                        ? `Celular de la empresa · contacto: ${d.contacto_nombre}`
                        : 'Celular de la empresa')
                    : 'Celular del cliente',
                advertencia: this.advertenciaNumero(d.wa_numero),
            };
        },

        // Misma normalización que WhatsappApiService::normalizarNumero(), para
        // que el operador vea aquí lo que Meta va a recibir. Un campo con dos
        // números metidos («300... / 320...») se delata solo: al quitarle los
        // no-dígitos quedan 20 y el mensaje rebota.
        numeroNormalizado(numero) {
            let n = (numero || '').replace(/[^0-9]/g, '').replace(/^0+/, '');
            if (n.length === 10) n = '57' + n;
            return n;
        },

        advertenciaNumero(numero) {
            if (!numero) {
                return this.tipoEnvio === 'contacto_empresa'
                    ? 'La empresa no tiene celular registrado; el envío será rechazado.'
                    : 'El cliente no tiene celular registrado; el envío será rechazado.';
            }

            const n = this.numeroNormalizado(numero);

            if (!/^573\d{9}$/.test(n)) {
                return `El número guardado («${numero}») queda como ${n || 'vacío'} y no parece un celular colombiano. `
                     + 'Revisa que el campo no tenga dos números juntos ni sea un fijo, o WhatsApp lo rechazará.';
            }

            return '';
        },

        abrirConfirmacionIndividual(d) {
            this.mensajeExito = '';
            this.mensajeError = '';
            this.individualAConfirmar = d;
            this.confirmarIndividualModalOpen = true;
        },

        cerrarConfirmacionIndividual() {
            if (this.reenviandoId !== null) return; // hay un envío en curso
            this.confirmarIndividualModalOpen = false;
            this.individualAConfirmar = null;
        },

        async confirmarEnvioIndividual() {
            const d = this.individualAConfirmar;
            if (!d) return;

            await this.reenviarPlanillaIndividual(
                d.plano_id,
                d.cliente_nombre || d.nombre_destinatario,
                d.periodo_mes,
                d.periodo_anio
            );

            this.confirmarIndividualModalOpen = false;
            this.individualAConfirmar = null;
        },

        async reenviarPlanillaIndividual(planoId, nombre, periodoMes, periodoAnio) {
            this.reenviandoId = planoId;
            this.mensajeExito = '';
            this.mensajeError = '';

            // Mostrar estado de procesamiento localmente de forma inmediata
            const planoLocal = this.destinatarios.find(d => d.plano_id === planoId);
            if (planoLocal) {
                planoLocal.envio_estado = 'procesando';
                this.aplicarFiltrosTabla();
            }

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const res = await fetch(`/admin/planos/envio-planillas/${planoId}/reenviar`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        tipo_envio: this.tipoEnvio,
                        mes: periodoMes || this.filtroMes,
                        anio: periodoAnio || this.filtroAnio,
                    })
                });

                const data = await res.json();
                
                if (data.ok) {
                    // Decir a quién llegó, no solo de quién era la planilla:
                    // con «Contacto de la Empresa» no son la misma persona.
                    const paraQuien = this.tipoEnvio === 'contacto_empresa'
                        ? (planoLocal?.empresa_nombre || 'la empresa')
                        : nombre;
                    const numero = planoLocal?.wa_numero ? ` (${planoLocal.wa_numero})` : '';
                    this.mensajeExito = `Planilla de ${nombre} enviada a ${paraQuien}${numero}.`
                        + (data.aviso ? ' ' + data.aviso : '');
                    // Actualizar el estado de este plano localmente en la tabla a enviado
                    if (planoLocal) {
                        planoLocal.envio_estado = 'enviado';
                    }
                } else {
                    this.mensajeError = data.mensaje || 'Error al reenviar planilla.';
                    // En caso de error, volver a marcar como fallido
                    if (planoLocal) {
                        planoLocal.envio_estado = 'fallido';
                    }
                }
                this.aplicarFiltrosTabla();
            } catch (err) {
                console.error(err);
                this.mensajeError = 'Error de comunicación.';
                if (planoLocal) {
                    planoLocal.envio_estado = 'fallido';
                    this.aplicarFiltrosTabla();
                }
            } finally {
                this.reenviandoId = null;
            }
        },

        async crearPlantillaAutomatico(forzar = false) {
            this.creandoPlantilla = true;
            this.mensajeExito = '';
            this.mensajeError = '';

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const res = await fetch('/admin/planos/envio-planillas/crear-plantilla', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ forzar })
                });

                const data = await res.json();

                // Caso especial: ya existe en Meta, pedir confirmación para recrear
                if (!data.ok && data.ya_existe) {
                    this.creandoPlantilla = false;
                    if (confirm(data.mensaje + '\n\n¿Desea recrearla de todas formas?')) {
                        return this.crearPlantillaAutomatico(true);
                    }
                    return;
                }

                if (data.ok) {
                    this.plantillaConfigurada = true;
                    // Actualizar nombre si viene
                    if (data.plantilla?.nombre_display) {
                        this.plantillaNombre = data.plantilla.nombre_display;
                    }
                    const estadoIcono = (data.estado === 'APPROVED') ? '\u2705' : '\u23F3';
                    this.mensajeExito = `${estadoIcono} ${data.mensaje}`;
                } else {
                    this.mensajeError = '\u274C ' + (data.mensaje || 'Error al crear la plantilla.');
                }
            } catch (err) {
                console.error(err);
                this.mensajeError = '\u274C Error de conexión: ' + err.message;
            } finally {
                this.creandoPlantilla = false;
            }
        },

        // Modales e historial
        async abrirHistorialModal() {
            this.historialModalOpen = true;
            this.cargandoHistorial = true;
            
            try {
                const res = await fetch(`/admin/planos/envio-planillas/historial?_=${new Date().getTime()}`);
                const data = await res.json();
                if (data.ok) {
                    this.historialLotes = data.data;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.cargandoHistorial = false;
            }
        },

        async verDetalleLote(loteId) {
            this.historialModalOpen = false;
            this.detalleLoteModalOpen = true;
            this.cargandoLoteDetalle = true;
            this.loteDetalleId = loteId;

            try {
                const res = await fetch(`/admin/planos/envio-planillas/${loteId}/detalle?_=${new Date().getTime()}`);
                const data = await res.json();
                if (data.ok) {
                    this.loteDetalleObj = data.lote;
                    this.loteDetallesList = data.detalles;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.cargandoLoteDetalle = false;
            }
        },

        // Helpers de UI
        badgeEstado(estado) {
            return {
                'enviado': 'badge-warn',
                'entregado': 'badge-ok',
                'leido': 'badge-ok',
                'pendiente': 'badge-warn',
                'fallido': 'badge-err',
                'omitido': 'badge-info',
                'procesando': 'badge-info'
            }[estado] || 'badge-warn';
        },

        // «Enviado» no es verde a propósito: significa que Meta aceptó el
        // mensaje, no que haya llegado. El verde se lo gana «Entregado», que
        // es el que confirma el webhook.
        etiquetaEstado(estado) {
            return {
                'enviado': '📤 Enviado a Meta',
                'entregado': '🟢 Entregado',
                'leido': '🔵 Leído',
                'pendiente': '⏳ Pendiente',
                'fallido': '🔴 Fallido',
                'omitido': '⚪ Omitido',
                'procesando': '⚙️ En proceso'
            }[estado] || estado;
        },

        badgeLoteEstado(estado) {
            return {
                'completado': 'badge-ok',
                'procesando': 'badge-info',
                'pendiente': 'badge-warn',
                'fallido': 'badge-err'
            }[estado] || 'badge-warn';
        },

        etiquetaTipoEnvio(tipo) {
            return {
                'individual': 'Individuales',
                'empleado_empresa': 'Clientes Empresa',
                'contacto_empresa': 'Contacto Empresa'
            }[tipo] || tipo;
        },

        abrirPruebaModal() {
            this.pruebaModalOpen = true;
            this.pruebaResultado = null;
            this.waPruebaCelular = '';
            // Preferir el primer destinatario con operador autorizado para la prueba
            const autorizado = this.filtrados.find(d => d.es_operador_autorizado !== false);
            const primero = autorizado || this.filtrados[0];
            if (primero) {
                this.planoPruebaId = primero.plano_id;
                this.waPruebaCelular = primero.wa_numero || '';
            } else {
                this.planoPruebaId = '';
            }
        },

        async ejecutarEnvioPrueba() {
            if (!this.waPruebaCelular || !this.planoPruebaId) return;

            this.enviandoPrueba = true;
            this.pruebaResultado = null;

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const res = await fetch(`/admin/planos/envio-planillas/${this.planoPruebaId}/reenviar`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        tipo_envio: this.tipoEnvio,
                        celular_prueba: this.waPruebaCelular,
                        mes: this.filtroMes,
                        anio: this.filtroAnio,
                    })
                });

                let data;
                try {
                    data = await res.json();
                } catch (e) {
                    data = { ok: false, mensaje: `Error HTTP ${res.status}: No se pudo leer la respuesta del servidor.` };
                }

                if (data.ok) {
                    this.pruebaResultado = { ok: true, mensaje: data.mensaje || '\u2705 Mensaje de prueba enviado exitosamente.' };
                    this.mensajeExito = this.pruebaResultado.mensaje;
                } else {
                    this.pruebaResultado = { ok: false, mensaje: data.mensaje || '\u274C Error al enviar mensaje de prueba.' };
                    this.mensajeError = this.pruebaResultado.mensaje;
                }
            } catch (err) {
                console.error(err);
                this.pruebaResultado = { ok: false, mensaje: '\u274C Error de red: ' + err.message };
                this.mensajeError = this.pruebaResultado.mensaje;
            } finally {
                this.enviandoPrueba = false;
            }
        },

        get nombrePdfPrevisualizado() {
            if (!this.planoPruebaId) return 'Planilla_SS.pdf';
            const cliente = this.filtrados.find(d => d.plano_id == this.planoPruebaId);
            if (!cliente) return 'Planilla_SS.pdf';

            const mesesEs = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                             'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
            const mesNombre = mesesEs[(this.filtroMes - 1)] || `Mes${this.filtroMes}`;

            const nombreCompleto = (cliente.cliente_nombre || cliente.nombre_destinatario || 'Cliente');
            // Igual que PlanillaWhatsappService::generarNombreArchivoPdf: mayúsculas
            // y sin tildes ni eñes ("PEñA" → "PENA", no "PEnA").
            const nombreLimpio = nombreCompleto
                .toUpperCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/[^A-Z0-9\s]/g, '')
                .trim().replace(/\s+/g, '_');

            return `Planilla_SS_${nombreLimpio}_${mesNombre}_${this.filtroAnio}.pdf`;
        },

        get textoPrevisualizadoFormateado() {
            if (!this.planoPruebaId) return '';
            const cliente = this.filtrados.find(d => d.plano_id == this.planoPruebaId);
            if (!cliente) return '';
            
            // Texto con formato WhatsApp
            let txt = "Hola *{{1}}*👋,\n\nTu planilla de seguridad social ha sido pagada exitosamente. ✅\n\n*Operador:* {{2}}\n*Número de planilla:* {{3}}\n\nSi tienes alguna duda, estamos atentos. 😊";
            
            // Reemplazar variables
            txt = txt.replace('{{1}}', cliente.cliente_nombre || cliente.nombre_destinatario || 'Cliente');
            txt = txt.replace('{{2}}', cliente.operador_nombre || 'Operador');
            txt = txt.replace('{{3}}', cliente.numero_planilla || 'N/A');
            
            // Convertir *negrita* de WhatsApp en <strong>negrita</strong> de HTML
            txt = txt.replace(/\*([^*]+)\*/g, '<strong>$1</strong>');
            // Reemplazar saltos de línea por <br>
            txt = txt.replace(/\n/g, '<br>');
            
            return txt;
        },

        formatearFecha(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        },

        get contadorEnviados() {
            return this.destinatarios.filter(d => ['enviado', 'entregado', 'leido'].includes(d.envio_state || d.envio_estado)).length;
        },

        get contadorPendientes() {
            return this.destinatarios.filter(d => (d.envio_state || d.envio_estado) === 'pendiente' || (d.envio_state || d.envio_estado) === 'fallido').length;
        }
    }
}
</script>
@endsection
