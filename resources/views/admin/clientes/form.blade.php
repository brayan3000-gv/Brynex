@extends('layouts.app')
@section('modulo', isset($cliente->id) && $cliente->id ? 'Editar Cliente' : 'Nuevo Cliente')

@section('contenido')
<div style="margin:0 auto;">

    @if($errors->any())
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;padding:0.6rem 1rem;margin-bottom:1rem;font-size:0.83rem;">
            <strong>Corrige los errores:</strong>
            <ul style="margin:0.25rem 0 0 1rem;">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST"
        action="{{ isset($cliente->id) && $cliente->id ? route('admin.clientes.update', $cliente->id) : route('admin.clientes.store') }}">
        @csrf
        @if(isset($cliente->id) && $cliente->id) @method('PUT') @endif
        @if($isIframe ?? false)<input type="hidden" name="iframe" value="1">@endif

        {{-- ═══════════ LAYOUT: Iconos (izq) + Datos (centro) + SS/Obs (der) ═══════════ --}}
        <div style="display:grid;grid-template-columns:65px 1fr 290px;gap:1rem;margin-bottom:1.25rem;align-items:stretch;">

            {{-- ══════════ BARRA ICONOS LATERALES ══════════ --}}
            <div style="display:flex;flex-direction:column;gap:0.5rem;padding-top:0.5rem;">
                <a href="#" onclick="showTab('beneficiarios'); return false;" class="icono-lateral" title="Beneficiarios" style="background:#dbeafe;">
                    <span style="font-size:1.4rem;">👨‍👩‍👧</span>
                    <span class="icono-lbl">Benefic.</span>
                </a>
                @if(isset($cliente->id) && $cliente->id)
                <a href="{{ route('admin.incapacidades.index', ['cedula' => $cliente->cedula]) }}" class="icono-lateral" title="Incapacidades" style="background:#fef3c7;">
                    <span style="font-size:1.4rem;">🏥</span>
                    <span class="icono-lbl">Incapac.</span>
                </a>
                @else
                <a href="#" class="icono-lateral" title="Incapacidades" style="background:#fef3c7;opacity:0.5;cursor:not-allowed;">
                    <span style="font-size:1.4rem;">🏥</span>
                    <span class="icono-lbl">Incapac.</span>
                </a>
                @endif
                @if(isset($cliente->id) && $cliente->id && !empty($cliente->cedula))
                <a href="{{ route('admin.facturacion.historial', $cliente->cedula) }}" class="icono-lateral" title="Historial de Pagos" style="background:#e0e7ff;">
                    <span style="font-size:1.4rem;">📜</span>
                    <span class="icono-lbl">Historial</span>
                </a>
                @else
                <a href="#" class="icono-lateral" title="Historial (guarde el cliente primero)" style="background:#e0e7ff;opacity:0.5;cursor:not-allowed;">
                    <span style="font-size:1.4rem;">📜</span>
                    <span class="icono-lbl">Historial</span>
                </a>
                @endif
                <a href="#" onclick="showTab('documentos'); return false;" class="icono-lateral" title="Documentos" style="background:#fce7f3;">
                    <span style="font-size:1.4rem;">📄</span>
                    <span class="icono-lbl">Docum.</span>
                </a>
                <a href="#" onclick="if(typeof abrirPanelClaves==='function') abrirPanelClaves(); return false;" class="icono-lateral" title="Claves y Accesos" style="background:#fef9c3;">
                    <span style="font-size:1.4rem;">🔑</span>
                    <span class="icono-lbl">Claves</span>
                </a>
                @if(isset($cliente->id) && $cliente->id)
                <a href="#" onclick="OI_abrirCliente(); return false;" class="icono-lateral" title="Otro Ingreso / Trámite" style="background:#d1fae5;">
                    <span style="font-size:1.4rem;">💼</span>
                    <span class="icono-lbl">Trámite</span>
                </a>
                @endif
            </div>

            {{-- ══════════ COLUMNA CENTRAL — DATOS PERSONALES ══════════ --}}
            <div style="background:#fff;border-radius:14px;padding:1.1rem 1.25rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

                {{-- Header: Título con nombre completo + badge CC + Empresa --}}
                @php
                    $nombreCompleto = collect([
                        $cliente->primer_nombre ?? null,
                        $cliente->segundo_nombre ?? null,
                        $cliente->primer_apellido ?? null,
                        $cliente->segundo_apellido ?? null,
                    ])->filter()->implode(' ');
                    $nombreCompleto = nombre_oracion($nombreCompleto);
                @endphp
                <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
                        <div style="height:36px;width:36px;border-radius:9px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">👤</div>
                        <div>
                            <h2 style="font-size:0.95rem;font-weight:700;color:#0f172a;margin:0;line-height:1.2;">
                                {{ nombre_oracion($nombreCompleto ?: (isset($cliente->id) && $cliente->id ? 'Editar Cliente #'.$cliente->id : 'Nuevo Cliente')) }}
                            </h2>
                            @if(!empty($cliente->cedula))
                            <span style="background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;border-radius:999px;padding:0.15rem 0.65rem;font-size:0.68rem;font-weight:700;letter-spacing:0.03em;display:inline-block;margin-top:0.1rem;">{{ $cliente->tipo_doc ?: 'CC' }} {{ $cliente->cedula }}</span>
                            @endif
                        </div>
                    </div>
                    {{-- Empresa --}}
                    <div style="display:flex;align-items:center;gap:0.4rem;flex:1;min-width:200px;">
                        <span style="font-size:0.7rem;font-weight:700;color:#64748b;white-space:nowrap;letter-spacing:0.04em;">🏢 EMPRESA</span>
                        <select name="cod_empresa" style="flex:1;padding:0.32rem 0.6rem;border:1px solid #cbd5e1;border-radius:7px;font-size:0.82rem;color:#0f172a;background:#f8fafc;font-weight:500;">
                            <option value="1" {{ old('cod_empresa', $cliente->cod_empresa ?? 1) == 1 ? 'selected' : '' }}>— Individual —</option>
                            @foreach($lookups['empresas'] as $emp)
                                @if($emp->id != 1)
                                <option value="{{ $emp->id }}" {{ old('cod_empresa', $cliente->cod_empresa) == $emp->id ? 'selected' : '' }}>{{ $emp->empresa }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    @if(isset($cliente->id) && $cliente->id)
                    <span style="background:#f1f5f9;color:#475569;border-radius:999px;padding:0.22rem 0.7rem;font-size:0.7rem;font-weight:700;white-space:nowrap;flex-shrink:0;border:1px solid #e2e8f0;">ID #{{ $cliente->id }}</span>
                    @endif
                </div>

                <div style="border-top:1px solid #f1f5f9;padding-top:0.85rem;">
                    <div style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.5rem;">👤 Datos Personales</div>
                    <div style="display:grid;grid-template-columns:210px 140px 1fr 1fr 1fr 1fr;gap:0.5rem;margin-bottom:0.55rem;">
                        <div>
                            <label class="lbl-campo">Tipo Doc.</label>
                            <select name="tipo_doc" class="inp-campo">
                                <option value="">—</option>
                                @foreach($lookups['tipos_doc'] as $k => $v)
                                    <option value="{{ $k }}" {{ old('tipo_doc', $cliente->tipo_doc) == $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lbl-campo" style="display:flex;justify-content:space-between;align-items:center;">
                                <span>Cédula *</span>
                                @if($cedulaBloqueada ?? false)
                                <a href="#" onclick="CC_abrir(); return false;" style="font-size:0.66rem;font-weight:700;color:#2563eb;text-decoration:none;text-transform:none;letter-spacing:0;" title="Cambia la cédula en la ficha y en sus contratos, facturas y demás registros">✏️ Corregir</a>
                                @endif
                            </label>
                            {{-- Con contratos u otros registros ligados, el número no se
                                 cambia aquí: los dejaría huérfanos. Se corrige con el
                                 botón de arriba, que los mueve todos. --}}
                            <input
                                type="text"
                                name="cedula"
                                value="{{ ($cedulaBloqueada ?? false) ? $cliente->cedula : old('cedula', $cliente->cedula ?: '') }}"
                                required
                                class="inp-campo"
                                style="font-family:monospace;font-weight:700;{{ ($cedulaBloqueada ?? false) ? 'background:#f1f5f9;color:#475569;cursor:not-allowed;' : '' }}"
                                @if($cedulaBloqueada ?? false) readonly @endif
                                {{-- La verificación (duplicado + registro oficial) ya ocurrió
                                     en el modal de "Nuevo Cliente" del listado. Al llegar aquí
                                     los datos ya vienen confirmados. --}}
                            >
                            {{-- Error de servidor --}}
                            @error('cedula')
                                <div style="margin-top:4px;padding:5px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:0.75rem;color:#b91c1c;">⚠️ {{ $message }}</div>
                            @enderror
                        </div>
                        <div>
                            <label class="lbl-campo">1er Nombre *</label>
                            <input type="text" name="primer_nombre" value="{{ old('primer_nombre', $cliente->primer_nombre) }}" required class="inp-campo" style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label class="lbl-campo">2do Nombre</label>
                            <input type="text" name="segundo_nombre" value="{{ old('segundo_nombre', $cliente->segundo_nombre) }}" class="inp-campo" style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label class="lbl-campo">1er Apellido *</label>
                            <input type="text" name="primer_apellido" value="{{ old('primer_apellido', $cliente->primer_apellido) }}" required class="inp-campo" style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label class="lbl-campo">2do Apellido</label>
                            <input type="text" name="segundo_apellido" value="{{ old('segundo_apellido', $cliente->segundo_apellido) }}" class="inp-campo" style="text-transform:uppercase;">
                        </div>
                    </div>

                    {{-- Fila B: F.Nac | Edad | F.Exp | Género | Sisben | Teléfonos | Celular --}}
                    <div style="display:grid;grid-template-columns:125px 34px 135px 100px 1fr 1fr 1.2fr;gap:0.5rem;margin-bottom:0.55rem;align-items:end;">
                        <div>
                            <label class="lbl-campo">F. Nacimiento</label>
                            <input type="date" name="fecha_nacimiento"
                                value="{{ old('fecha_nacimiento', $cliente->fecha_nacimiento ? $cliente->fecha_nacimiento->format('Y-m-d') : '') }}"
                                class="inp-campo" id="fNacimiento">
                        </div>
                        <div>
                            <label class="lbl-campo">Edad</label>
                            <input type="text" id="edadCalc" value="{{ $cliente->edad ?? '' }}" class="inp-campo" readonly
                                style="background:#f1f5f9;text-align:center;font-weight:700;color:#2563eb;padding-left:0.3rem;padding-right:0.3rem;">
                        </div>
                        <div>
                            <label class="lbl-campo">F. Expedición</label>
                            <input type="date" name="fecha_expedicion"
                                value="{{ old('fecha_expedicion', $cliente->fecha_expedicion ? $cliente->fecha_expedicion->format('Y-m-d') : '') }}"
                                class="inp-campo">
                        </div>
                        <div>
                            <label class="lbl-campo">Género</label>
                            <select name="genero" class="inp-campo">
                                <option value="">—</option>
                                @foreach($lookups['generos'] as $k => $v)
                                    <option value="{{ $k }}" {{ old('genero', trim($cliente->genero ?? '')) == $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lbl-campo">Sisben</label>
                            <select name="sisben" class="inp-campo">
                                <option value="">—</option>
                                @foreach($lookups['sisben'] as $k => $v)
                                    <option value="{{ $k }}" {{ old('sisben', trim($cliente->sisben ?? '')) == $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lbl-campo">Teléfonos</label>
                            <input type="text" name="telefono" value="{{ old('telefono', $cliente->telefono) }}" class="inp-campo">
                        </div>
                        <div>
                            <label class="lbl-campo">Celular <span style="color:#16a34a;font-weight:800;">★</span></label>
                            <div style="display:flex;gap:0.25rem;align-items:center;">
                                <input type="text" name="celular" id="inputCelular" value="{{ old('celular', $cliente->celular ?: '') }}" class="inp-campo" style="flex:1;border-color:#16a34a;background:#f0fdf4;">
                                <a id="wa-link" href="#" target="_blank" title="Abrir WhatsApp"
                                    style="display:flex;align-items:center;justify-content:center;width:32px;height:32px;background:#25d366;border-radius:6px;text-decoration:none;flex-shrink:0;transition:background 0.2s;"
                                    onmouseover="this.style.background='#1da854'" onmouseout="this.style.background='#25d366'"
                                    onclick="var n=document.getElementById('inputCelular').value.replace(/\D/g,'');this.href='https://wa.me/57'+n;return true;">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="#fff">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>


                    <div style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin:0.75rem 0 0.5rem;">📍 Contacto y Ubicación</div>
                    @php
                        $deptoActual = old('departamento_id', $cliente->departamento_id);
                        $munActual   = old('municipio_id', $cliente->municipio_id);
                    @endphp
                    <div style="display:grid;grid-template-columns:1.4fr 1.2fr 1fr 1.6fr;gap:0.5rem;margin-bottom:0.55rem;">
                        <div>
                            <label class="lbl-campo">Correo</label>
                            <input type="email" name="correo" value="{{ old('correo', $cliente->correo) }}" class="inp-campo">
                        </div>
                        <div>
                            <label class="lbl-campo">Departamento</label>
                            <select name="departamento_id" id="selDepto" class="inp-campo">
                                <option value="">— Seleccionar —</option>
                                @foreach($lookups['departamentos'] as $id => $nombre)
                                    <option value="{{ $id }}" {{ $deptoActual == $id ? 'selected' : '' }}>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lbl-campo">Municipio</label>
                            <select name="municipio_id" id="selMunicipio" class="inp-campo">
                                <option value="">— Seleccionar —</option>
                            </select>
                        </div>
                        <div>
                            <label class="lbl-campo">Dirección Vivienda</label>
                            <input type="text" name="direccion_vivienda" value="{{ old('direccion_vivienda', $cliente->direccion_vivienda) }}" class="inp-campo">
                        </div>
                    </div>

                    <div style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin:0.75rem 0 0.5rem;">💼 Información Laboral</div>
                    {{-- operador_planilla_id se gestiona desde el Contrato (cuando RS = independiente) --}}
                    <input type="hidden" name="operador_planilla_id"
                           value="{{ old('operador_planilla_id', $cliente->operador_planilla_id) }}">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 80px;gap:0.5rem;">
                        <div>
                            <label class="lbl-campo">Ocupación</label>
                            <input type="text" name="ocupacion" value="{{ old('ocupacion', $cliente->ocupacion) }}" class="inp-campo">
                        </div>
                        <div>
                            <label class="lbl-campo">Referido</label>
                            <input type="text" name="referido" value="{{ old('referido', $cliente->referido) }}" class="inp-campo">
                        </div>
                        <div>
                            <label class="lbl-campo">IPS</label>
                            <input type="text" name="ips" value="{{ old('ips', $cliente->ips) }}" class="inp-campo">
                        </div>
                        <div>
                            <label class="lbl-campo">IVA</label>
                            <select name="iva" class="inp-campo">
                                <option value="">—</option>
                                <option value="SI" {{ strtoupper(old('iva', $cliente->iva ?? '')) === 'SI' ? 'selected' : '' }}>Sí</option>
                                <option value="NO" {{ strtoupper(old('iva', $cliente->iva ?? '')) === 'NO' ? 'selected' : '' }}>No</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:0.55rem;">
                        <textarea name="observacion" rows="2" class="inp-campo" style="resize:vertical;min-height:unset;" placeholder="Observación general...">{{ old('observacion', $cliente->observacion) }}</textarea>
                    </div>

                </div>
            </div>

            {{-- ══════════ COLUMNA DERECHA — SS + BOTONES ══════════ --}}
            <div style="display:flex;flex-direction:column;justify-content:space-between;gap:1rem;">

                {{-- Seguridad Social --}}
                <div style="background:#fff;border-radius:14px;padding:1.1rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">
                    <h3 style="font-size:0.85rem;font-weight:700;color:#0f172a;margin-bottom:0.6rem;display:flex;align-items:center;gap:0.35rem;">
                        <span style="color:#16a34a;">🏥</span> Seguridad Social
                    </h3>
                    <div style="margin-bottom:0.5rem;">
                        <label class="lbl-campo">EPS</label>
                        <select name="eps_id" class="inp-campo">
                            <option value="">— Seleccionar —</option>
                            @foreach($lookups['eps'] as $id => $nombre)
                                <option value="{{ $id }}" {{ old('eps_id', $cliente->eps_id) == $id ? 'selected' : '' }}>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <label class="lbl-campo">Pensión</label>
                        <select name="pension_id" class="inp-campo">
                            <option value="">— Seleccionar —</option>
                            @foreach($lookups['pension'] as $id => $nombre)
                                <option value="{{ $id }}" {{ old('pension_id', $cliente->pension_id) == $id ? 'selected' : '' }}>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>{{-- /SS card --}}

                {{-- Card Resumen --}}
                @if(isset($cliente->id) && $cliente->id)
                <div style="background:#fff;border-radius:14px;padding:1rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">
                    <h3 style="font-size:0.78rem;font-weight:700;color:#0f172a;margin-bottom:0.6rem;display:flex;align-items:center;gap:0.35rem;">
                        <span style="color:#6366f1;">📊</span> Resumen
                    </h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem;">
                        <a href="#" onclick="showTab('beneficiarios'); return false;"
                           style="background:#eff6ff;border-radius:10px;padding:0.65rem 0.5rem;text-align:center;text-decoration:none;transition:background 0.15s;"
                           onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                            <div style="font-size:1.4rem;line-height:1;">👨‍👩‍👧</div>
                            <div style="font-size:1.1rem;font-weight:800;color:#1d4ed8;margin:0.15rem 0;">{{ $resumen['beneficiarios'] ?? 0 }}</div>
                            <div style="font-size:0.6rem;font-weight:600;color:#64748b;line-height:1.1;">Benefic.</div>
                        </a>
                        <a href="{{ route('admin.incapacidades.index', ['cedula' => $cliente->cedula]) }}"
                           style="background:#fff7ed;border-radius:10px;padding:0.65rem 0.5rem;text-align:center;text-decoration:none;transition:background 0.15s;"
                           onmouseover="this.style.background='#fed7aa'" onmouseout="this.style.background='#fff7ed'">
                            <div style="font-size:1.4rem;line-height:1;">🏥</div>
                            <div style="font-size:1.1rem;font-weight:800;color:#ea580c;margin:0.15rem 0;">{{ $resumen['incapacidades'] ?? 0 }}</div>
                            <div style="font-size:0.6rem;font-weight:600;color:#64748b;line-height:1.1;">Incapac.</div>
                        </a>
                        <div style="background:#f0fdf4;border-radius:10px;padding:0.65rem 0.5rem;text-align:center;">
                            <div style="font-size:1.4rem;line-height:1;">📋</div>
                            <div style="font-size:1.1rem;font-weight:800;color:#16a34a;margin:0.15rem 0;">{{ $resumen['contratos_vigent'] ?? 0 }}</div>
                            <div style="font-size:0.6rem;font-weight:600;color:#64748b;line-height:1.1;">Vigentes</div>
                        </div>
                    </div>
                    {{-- Fila 2: Claves --}}
                    <div style="margin-top:0.5rem;">
                        <a href="#" onclick="showTab('claves'); return false;"
                           style="display:block;background:#fef9c3;border-radius:10px;padding:0.55rem 0.5rem;text-align:center;text-decoration:none;transition:background 0.15s;"
                           onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef9c3'">
                            <div style="display:flex;align-items:center;justify-content:center;gap:0.5rem;">
                                <span style="font-size:1.2rem;">🔑</span>
                                <div>
                                    <span style="font-size:1rem;font-weight:800;color:#92400e;">{{ $resumen['claves'] ?? 0 }}</span>
                                    <span style="font-size:0.6rem;font-weight:600;color:#78350f;margin-left:0.25rem;">clave(s) activa(s)</span>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
                @endif

                {{-- Card Botones --}}
                <div style="background:#fff;border-radius:14px;padding:0.85rem 1rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">
                    <div style="display:flex;gap:0.6rem;justify-content:flex-end;">
                        <a href="{{ route('admin.clientes.index') }}"
                            style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:8px;color:#475569;text-decoration:none;font-size:0.82rem;font-weight:500;">
                            Cancelar
                        </a>
                        <button type="submit"
                            style="padding:0.5rem 1.4rem;background:linear-gradient(135deg,#dc2626,#991b1b);border:none;border-radius:8px;color:#fff;font-size:0.85rem;font-weight:700;cursor:pointer;box-shadow:0 3px 10px rgba(220,38,38,0.35);display:flex;align-items:center;gap:0.35rem;">
                            💾 GUARDAR
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </form>

    {{-- ═══════════════════════════ CONTRATOS ═══════════════════════════ --}}
    <div style="background:#fff;border-radius:14px;padding:1.25rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);margin-bottom:1.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
            <h3 style="font-size:0.95rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:0.4rem;">
                <span style="color:#2563eb;">📋</span> Contratos
            </h3>
            @if(isset($cliente->id) && $cliente->id)
            <a href="{{ route('admin.contratos.create', ['cedula' => $cliente->cedula]) }}"
               style="display:inline-flex;align-items:center;gap:0.4rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;padding:0.35rem 0.9rem;border-radius:8px;text-decoration:none;font-size:0.78rem;font-weight:600;box-shadow:0 2px 8px rgba(37,99,235,0.3);">
                📂 + Nuevo Contrato
            </a>
            @endif
        </div>

        @if($contratos->count() > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                <thead>
                    <tr style="background:#f1f5f9;border-bottom:2px solid #e2e8f0;">
                        <th class="th-c" style="text-align:center;">Id</th>
                        <th class="th-c" style="text-align:center;">Estado</th>
                        <th class="th-c" style="text-align:center;">Tipo</th>
                        <th class="th-c" style="text-align:center;">Razón Social</th>
                        <th class="th-c" style="text-align:center;">F. Ingreso</th>
                        <th class="th-c" style="text-align:center;">F. Retiro</th>
                        <th class="th-c" style="text-align:center;">Salario M.</th>
                        <th class="th-c" style="text-align:center;">Plan</th>
                        <th class="th-c" style="text-align:center;">ARL</th>
                        <th class="th-c" style="text-align:center;">Observación</th>
                        <th class="th-c" style="text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contratos as $c)
                    <tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background='#fafbff'" onmouseout="this.style.background='transparent'">
                        <td class="td-c" style="text-align:center;font-weight:600;">
                            {{ $c->id }}
                        </td>
                        <td class="td-c" style="text-align:center;">
                            @php
                                $estadoColor = match(strtolower(trim($c->estado ?? ''))) {
                                    'vigente'  => ['bg'=>'#dcfce7','color'=>'#16a34a'],
                                    'retirado' => ['bg'=>'#fee2e2','color'=>'#dc2626'],
                                    default    => ['bg'=>'#f1f5f9','color'=>'#475569'],
                                };
                            @endphp
                            <span style="background:{{ $estadoColor['bg'] }};color:{{ $estadoColor['color'] }};padding:0.15rem 0.55rem;border-radius:999px;font-size:0.7rem;font-weight:600;white-space:nowrap;">
                                {{ strtoupper($c->estado ?? '—') }}
                            </span>
                        </td>
                        <td class="td-c" style="text-align:center;font-weight:600;color:#374151;">{{ $c->tipo_mod ?: '—' }}</td>
                        <td class="td-c" style="text-align:center;">
                            {{ $razonesMap[$c->razon_social_id] ?? '—' }}
                        </td>
                        <td class="td-c" style="text-align:center;white-space:nowrap;">{{ $c->fecha_ingreso ? sqldate($c->fecha_ingreso)->locale('es')->translatedFormat('d-F-y') : '—' }}</td>
                        <td class="td-c" style="text-align:center;white-space:nowrap;">
                            @if($c->fecha_retiro)
                                <span style="color:#dc2626;font-weight:600;">{{ sqldate($c->fecha_retiro)->locale('es')->translatedFormat('d-F-y') }}</span>
                            @else <span style="color:#94a3b8;">—</span>
                            @endif
                        </td>
                        <td class="td-c" style="text-align:center;font-weight:600;">${{ number_format($c->salario ?? 0, 0, ',', '.') }}</td>
                        {{-- Plan: badge visual --}}
                        <td class="td-c" style="text-align:center;">
                            @if(!empty($c->plan_nombre))
                            <span style="background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-radius:6px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:700;letter-spacing:0.03em;">
                                {{ $c->plan_nombre }}
                            </span>
                            @else
                            <span style="color:#94a3b8;font-size:0.72rem;">—</span>
                            @endif
                        </td>
                        <td class="td-c" style="text-align:center;">{{ $c->n_arl ? 'N'.$c->n_arl : '—' }}</td>
                        <td class="td-c" style="text-align:center;font-size:0.72rem;color:#64748b;max-width:180px;">{{ \Illuminate\Support\Str::limit($c->observacion ?? '', 55) }}</td>
                        <td class="td-c" style="text-align:center;">
                            @php
                                $tituloContrato = trim(($c->tipo_mod ?: '') . ' — ' . ($razonesMap[$c->razon_social_id] ?? '') . ' (' . ($c->estado ?? '') . ')');
                            @endphp
                            <a href="{{ route('admin.contratos.edit', $c->id) }}"
                               style="display:inline-flex;align-items:center;gap:0.25rem;background:#eff6ff;color:#2563eb;padding:0.2rem 0.65rem;border-radius:6px;text-decoration:none;font-size:0.72rem;font-weight:600;border:1px solid #bfdbfe;white-space:nowrap;"
                               title="{{ $tituloContrato }}">
                               ✏️ Abrir
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:0.85rem;">
            @if(isset($cliente->id) && $cliente->id)
                No se encontraron contratos para esta cédula.
            @else
                Guarde el cliente primero para asociar contratos.
            @endif
        </div>
        @endif
    </div>

    {{-- ═══════════════════ HISTORIAL DE PAGOS (últimos 2) ═══════════════════ --}}
    @if(isset($ultimosPagos) && $ultimosPagos->count() > 0)
    @php
        // ¿Ya hay factura del mes en curso? Se evalúa sobre los datos ya cargados (sin query extra).
        // Solo se alerta si el cliente tiene al menos un contrato vigente.
        $mesActual   = (int) date('n');
        $anioActual  = (int) date('Y');
        $mesActualLbl = ucfirst(\Carbon\Carbon::create($anioActual, $mesActual, 1)->locale('es')->translatedFormat('F'));
        $tieneMesActual = $ultimosPagos->contains(
            fn($x) => (int)$x->mes === $mesActual && (int)$x->anio === $anioActual
        );
        $alertaPendiente = !$tieneMesActual && ($resumen['contratos_vigent'] ?? 0) > 0;
    @endphp
    <div style="background:{{ $alertaPendiente ? '#fffbeb' : '#fff' }};border:{{ $alertaPendiente ? '2px solid #f59e0b' : 'none' }};border-radius:14px;padding:0.6rem 1rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);margin-bottom:1.5rem;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.4rem 1.4rem;font-size:0.74rem;color:#94a3b8;">
        <span style="font-weight:700;color:#0f172a;white-space:nowrap;">Últimos pagos:</span>
        {{-- Orden cronológico ascendente: el mes más viejo primero --}}
        @foreach($ultimosPagos->reverse() as $p)
            @php
                $mesLabel  = ucfirst(\Carbon\Carbon::create((int)$p->anio, (int)$p->mes, 1)
                                ->locale('es')->translatedFormat('F'));
                $estadoLbl = match($p->estado) {
                    'pre_factura' => 'Pre-factura',
                    'abono'       => 'Abono',
                    'pagada'      => 'Pagada',
                    'prestamo'    => 'Préstamo',
                    default       => ucfirst((string)$p->estado),
                };
                $estadoCol = match($p->estado) {
                    'pagada'   => '#16a34a',
                    'abono'    => '#d97706',
                    'prestamo' => '#7c3aed',
                    default    => '#64748b',
                };
                $opNombre = $p->operador_nombre
                    ?: ($operadoresPlanilla ?? collect())->firstWhere('id', $cliente->operador_planilla_id)?->nombre;
            @endphp
            <div style="display:flex;align-items:center;gap:0.5rem;white-space:nowrap;{{ !$loop->first ? 'border-left:1px solid #e2e8f0;padding-left:1.4rem;' : '' }}">
                <span>Mes: <b style="color:#0f172a;">{{ $mesLabel }}</b></span>
                <span>Estado: <b style="color:{{ $estadoCol }};">{{ $estadoLbl }}</b></span>
                <span>Valor: <b style="color:#0f172a;">${{ number_format($p->total ?? 0, 0, ',', '.') }}</b></span>
                <span>Planilla:
                    @if(!empty($p->numero_planilla))
                        <b style="color:#0f172a;font-family:ui-monospace,monospace;">{{ $p->numero_planilla }}</b>
                    @else
                        <b style="color:#d97706;">Pendiente</b>
                    @endif
                </span>
                <span>Operador: <b style="color:{{ $opNombre ? '#475569' : '#cbd5e1' }};">{{ $opNombre ?: '—' }}</b></span>
            </div>
        @endforeach

        {{-- Mes en curso sin facturar (solo si hay contrato vigente) --}}
        @if($alertaPendiente)
        <div style="display:flex;align-items:center;gap:0.45rem;white-space:nowrap;border-left:1px solid #fcd34d;padding-left:1.4rem;">
            <span style="background:#f59e0b;color:#fff;padding:0.15rem 0.6rem;border-radius:999px;font-size:0.72rem;font-weight:800;">
                ⚠ {{ $mesActualLbl }}: PENDIENTE DE PAGO
            </span>
        </div>
        @endif
    </div>
    @endif

    {{-- ═══════════════════════════ PANELES DINÁMICOS (TABS) ═══════════════════════════ --}}
    {{-- ═══════════════════════════ PANELES DINÁMICOS (TABS) ═══════════════════════════ --}}
    @if(isset($cliente->id) && $cliente->id)
        @include('admin.clientes.partials.beneficiarios')
        @include('admin.clientes.partials.documentos')
        @include('admin.clientes.partials.clave_accesos')
        @if($cedulaBloqueada ?? false)
            @include('admin.clientes.partials.corregir_cedula')
        @endif

        {{-- ═══ MODAL OTRO INGRESO / TRÁMITE ═══════════════════════ --}}
        @if(isset($bancos))
        @php
            $oiCedula    = $cliente->cedula;
            $oiEmpresaId = $cliente->cod_empresa ?? null;
        @endphp
        @include('admin.facturacion._modal_otro_ingreso', [
            'bancos'      => $bancos,
            'oiCedula'    => $oiCedula,
            'oiEmpresaId' => $oiEmpresaId,
        ])
        @endif
    @endif

</div>

<style>
    .lbl-campo { display:block;font-size:0.7rem;font-weight:600;color:#475569;margin-bottom:0.15rem;text-transform:uppercase;letter-spacing:0.02em; }
    .inp-campo { width:100%;padding:0.4rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;color:#0f172a;transition:border 0.2s; }
    .inp-campo:focus { outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,0.15); }
    select.inp-campo { background:#fff; }
    .th-c { padding:0.5rem 0.6rem;text-align:left;color:#475569;font-weight:600;font-size:0.75rem;white-space:nowrap; }
    .td-c { padding:0.4rem 0.6rem;color:#0f172a; }

    .icono-lateral {
        display:flex;flex-direction:column;align-items:center;gap:0.2rem;
        padding:0.6rem 0.3rem;border-radius:10px;text-decoration:none;
        transition:transform 0.15s, box-shadow 0.15s;
        box-shadow:0 1px 4px rgba(0,0,0,0.06);
        border:1px solid rgba(0,0,0,0.04);
    }
    .icono-lateral:hover { transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.12); }
    .icono-lbl { font-size:0.58rem;font-weight:600;color:#475569;text-align:center;line-height:1.1; }
</style>

<script>
// === Ciudades data ===
var ciudadesData = @json($lookups['ciudades']);
var munActual = {{ $munActual ?: 'null' }};

var selDepto = document.getElementById('selDepto');
var selMun   = document.getElementById('selMunicipio');

function filtrarCiudades() {
    var deptoId = parseInt(selDepto.value);
    selMun.innerHTML = '<option value="">— Seleccionar —</option>';
    if (!deptoId) return;

    ciudadesData.forEach(function(c) {
        if (parseInt(c.departamento_id) === deptoId) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.nombre;
            if (munActual && parseInt(c.id) === parseInt(munActual)) opt.selected = true;
            selMun.appendChild(opt);
        }
    });
}

selDepto.addEventListener('change', function() { munActual = null; filtrarCiudades(); });
if (selDepto.value) filtrarCiudades();

// Edad automática
document.getElementById('fNacimiento')?.addEventListener('change', function() {
    var val = this.value;
    if(!val) { document.getElementById('edadCalc').value=''; return; }
    var born = new Date(val);
    var ageDifMs = Date.now() - born.getTime();
    var ageDate = new Date(ageDifMs);
    document.getElementById('edadCalc').value = Math.abs(ageDate.getUTCFullYear() - 1970);
});
// Tab Toggle → abre modales
function showTab(tabName) {
    if (tabName === 'beneficiarios') {
        if (typeof abrirPanelBeneficiarios === 'function') abrirPanelBeneficiarios();
    } else if (tabName === 'documentos') {
        if (typeof abrirPanelDocumentos === 'function') abrirPanelDocumentos();
    } else if (tabName === 'claves') {
        if (typeof abrirPanelClaves === 'function') abrirPanelClaves();
    }
}


@if(isset($cliente->id) && $cliente->id && isset($bancos))
// ─── Otro Ingreso: abrir desde ficha cliente ───────────────────────
function OI_abrirCliente() {
    if (typeof OI === 'undefined') {
        alert('El módulo de trámites aún no está cargado. Espere un momento y vuelva a intentar.');
        return;
    }
    OI.abrir({
        cedula:      {{ $cliente->cedula }},
        empresaId:   {{ $cliente->cod_empresa ?? 'null' }},
        subtitulo:   {!! json_encode(trim(($cliente->primer_nombre ?? '') . ' ' . ($cliente->primer_apellido ?? '') . ' — ' . ($cliente->tipo_doc ?: 'CC') . ' ' . $cliente->cedula)) !!},
        aplicaIva:   {{ strtoupper($cliente->iva ?? '') === 'SI' ? 'true' : 'false' }},
        pctIva:      19,
        mes:         {{ now()->month }},
        anio:        {{ now()->year }},
        asesorNombre: null, // asesor se obtiene automáticamente del backend
    });
}
@endif
</script>

@if(($isIframe ?? false) && session('success'))
<script>
// Notificar al padre que el cliente fue actualizado
(function(){
    try {
        window.parent.postMessage({
            type: 'brynex:cliente_updated',
            clienteId: {{ $cliente->id ?? 'null' }},
            nombre: {!! json_encode(trim(($cliente->primer_nombre ?? '') . ' ' . ($cliente->primer_apellido ?? ''))) !!}
        }, '*');
    } catch(e) {}
})();
</script>
@endif
@endsection
