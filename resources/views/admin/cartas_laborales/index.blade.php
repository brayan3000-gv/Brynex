@extends('layouts.app')
@section('modulo', 'Cartas laborales')

@section('contenido')
<style>
.cl-head{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);padding:1.1rem 1.5rem;border-radius:14px;color:#fff;margin-bottom:1rem}
.cl-head h1{font-size:1.3rem;font-weight:800;margin:0}
.cl-head p{font-size:.78rem;color:#94a3b8;margin:.2rem 0 0}
.cl-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e2e8f0;padding:1rem 1.1rem;margin-bottom:1rem}
.cl-card h2{font-size:.95rem;font-weight:700;color:#0f172a;margin:0 0 .75rem}
.cl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:.7rem}
.cl-campo label{display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem}
.cl-campo input,.cl-campo select,.cl-campo textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.42rem .55rem;font-size:.82rem;font-family:inherit;background:#fff;box-sizing:border-box}
.cl-campo input:focus,.cl-campo select:focus,.cl-campo textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.cl-campo textarea{min-height:70px;resize:vertical}
.cl-ancho{grid-column:1/-1}
.cl-busca{display:flex;gap:.5rem;flex-wrap:wrap}
.cl-busca input{flex:1;min-width:200px;border:1px solid #cbd5e1;border-radius:8px;padding:.5rem .7rem;font-size:.9rem}
.cl-btn{background:#2563eb;color:#fff;border:none;border-radius:8px;padding:.5rem 1rem;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem;text-decoration:none}
.cl-btn:hover{background:#3b82f6}
.cl-btn:disabled{opacity:.5;cursor:not-allowed}
.cl-btn.sec{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}
.cl-btn.sec:hover{background:#e2e8f0}
.cl-btn.verde{background:#10b981}.cl-btn.verde:hover{background:#059669}
.cl-contratos{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.6rem;margin-top:.8rem}
.cl-contrato{border:1.5px solid #e2e8f0;border-radius:10px;padding:.65rem .8rem;cursor:pointer;transition:all .15s;background:#fff;text-align:left;font-family:inherit}
.cl-contrato:hover{border-color:#3b82f6;box-shadow:0 4px 14px rgba(59,130,246,.12)}
.cl-contrato.sel{border-color:#2563eb;background:#eff6ff}
.cl-contrato .emp{font-weight:700;font-size:.82rem;color:#0f172a}
.cl-contrato .det{font-size:.72rem;color:#64748b;margin-top:.2rem}
.cl-badge{display:inline-block;border-radius:999px;padding:.1rem .55rem;font-size:.66rem;font-weight:700}
.cl-ok{background:#d1fae5;color:#065f46}.cl-no{background:#fee2e2;color:#991b1b}.cl-gris{background:#f1f5f9;color:#64748b}
.cl-tabs{display:flex;gap:.5rem;flex-wrap:wrap}
.cl-tab{border:1.5px solid #e2e8f0;border-radius:10px;padding:.55rem .9rem;cursor:pointer;background:#fff;font-size:.8rem;font-weight:600;color:#334155;font-family:inherit}
.cl-tab.sel{border-color:#2563eb;background:#eff6ff;color:#1e40af}
.cl-avisos{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.7rem .9rem;margin-bottom:1rem}
.cl-avisos li{font-size:.78rem;color:#92400e;margin:.15rem 0}
.cl-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#991b1b;border-radius:10px;padding:.65rem .9rem;margin-bottom:1rem;font-size:.8rem}
.cl-acciones{display:flex;gap:.5rem;flex-wrap:wrap;justify-content:flex-end}
.cl-tabla{width:100%;border-collapse:collapse;font-size:.76rem}
.cl-tabla th{background:#0f172a;color:#fff;padding:.45rem .55rem;text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.03em}
.cl-tabla td{padding:.45rem .55rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.cl-modal{position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:1rem}
.cl-modal-box{background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:520px;padding:1.1rem 1.25rem}
.cl-subir{display:flex;align-items:center;justify-content:center;border:1.5px dashed #94a3b8;border-radius:10px;min-height:150px;background:#f8fafc;cursor:pointer;padding:.8rem;text-align:center}
.cl-subir img{max-height:130px;max-width:100%}
.cl-nota{font-size:.72rem;color:#64748b}
</style>

<div x-data="cartaLaboral()" x-init="iniciar()">

    <div class="cl-head">
        <h1>📝 Cartas laborales</h1>
        <p>Busca a la persona por cédula, escoge el contrato y descarga la carta con el membrete, el sello y la firma de la empresa.</p>
    </div>

    {{-- 1. Buscar --}}
    <div class="cl-card">
        <h2>1. Trabajador</h2>
        <form class="cl-busca" @submit.prevent="buscar()">
            <input type="text" x-model.trim="cedula" placeholder="Cédula del trabajador" inputmode="numeric" autofocus>
            <button class="cl-btn" :disabled="buscando || !cedula">
                <span x-show="!buscando">🔍 Buscar</span>
                <span x-show="buscando" x-cloak>Buscando…</span>
            </button>
        </form>

        <template x-if="buscado && !contratos.length">
            <p class="cl-nota" style="margin-top:.7rem">No hay contratos con esa cédula en este aliado.</p>
        </template>

        <template x-if="cliente">
            <p style="margin:.8rem 0 0;font-size:.85rem;font-weight:700;color:#0f172a" x-text="cliente.nombre"></p>
        </template>

        <div class="cl-contratos" x-show="contratos.length">
            <template x-for="c in contratos" :key="c.id">
                <button type="button" class="cl-contrato" :class="{sel: f.contrato_id === c.id}" @click="escoger(c)" :disabled="!c.tiene_razon_social">
                    <div class="emp" x-text="c.empresa"></div>
                    <div class="det">
                        <span class="cl-badge" :class="c.estado === 'vigente' ? 'cl-ok' : 'cl-gris'" x-text="c.estado"></span>
                        <span x-text="'Ingreso ' + (c.fecha_ingreso || '—') + (c.fecha_retiro ? ' · Retiro ' + c.fecha_retiro : '')"></span>
                    </div>
                    <div class="det" x-text="[c.cargo, c.modalidad, '$' + pesos(c.salario)].filter(Boolean).join(' · ')"></div>
                </button>
            </template>
        </div>
    </div>

    <template x-if="cargando">
        <p class="cl-nota">Cargando contrato…</p>
    </template>

    {{-- 2. La carta --}}
    <form x-ref="form" method="POST" action="{{ route('admin.cartas_laborales.generar') }}" x-show="listo" x-cloak @input.debounce.700ms="revisar()" @change="revisar()">
        @csrf
        <input type="hidden" name="contrato_id" :value="f.contrato_id">
        <input type="hidden" name="formato" x-ref="formato" value="pdf">
        <input type="hidden" name="vista_previa" x-ref="vistaPrevia" value="0">
        <input type="hidden" name="destino" :value="f.destino">
        <input type="hidden" name="firmante_tipo" :value="f.firmante_tipo">

        <div class="cl-card">
            <h2>2. ¿Para qué es la carta?</h2>
            <div class="cl-tabs">
                <button type="button" class="cl-tab" :class="{sel: f.destino === 'caja'}" @click="f.destino = 'caja'; revisar()">🏦 Caja de compensación (subsidio)</button>
                <button type="button" class="cl-tab" :class="{sel: f.destino === 'certificacion'}" @click="f.destino = 'certificacion'; revisar()">📄 Certificación laboral</button>
            </div>
            <p class="cl-nota" style="margin:.6rem 0 0" x-show="f.destino === 'caja'">Va dirigida a la caja e incluye jornada y salario del año: es lo que piden para el subsidio familiar monetario.</p>
            <p class="cl-nota" style="margin:.6rem 0 0" x-show="f.destino === 'certificacion'" x-cloak>«A quien interese»: membrete centrado, el logo de fondo y «CERTIFICA:». Escoge abajo si lleva jornada, salario y auxilio de transporte.</p>
        </div>

        <div class="cl-card" x-show="empresa">
            <h2>3. Empresa</h2>
            <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                <div>
                    <div style="font-weight:800;color:#0f172a" x-text="empresa?.nombre"></div>
                    <div class="cl-nota" x-text="'NIT ' + empresa?.nit_formateado"></div>
                </div>
                <span class="cl-badge" :class="empresa?.tiene_logo ? 'cl-ok' : 'cl-no'" x-text="empresa?.tiene_logo ? '✓ Logo' : '✗ Sin logo'"></span>
                <span class="cl-badge" :class="empresa?.tiene_sello ? 'cl-ok' : 'cl-no'" x-text="empresa?.tiene_sello ? '✓ Sello' : '✗ Sin sello'"></span>
                @can('razones_sociales.gestionar')
                <a class="cl-btn sec" target="_blank" :href="'{{ url('admin/configuracion/razones-sociales') }}/' + f.razon_social_id + '/editar'">🏷️ Logo y sello (pestaña Archivos)</a>
                @endcan
            </div>
            <details style="margin-top:.8rem">
                <summary class="cl-nota" style="cursor:pointer">Pie de página del membrete (dirección, teléfono, correo)</summary>
                <div class="cl-grid" style="margin-top:.6rem">
                    <div class="cl-campo"><label>Dirección</label><input name="empresa_direccion" x-model="f.empresa_direccion"></div>
                    <div class="cl-campo"><label>Teléfono</label><input name="empresa_telefono" x-model="f.empresa_telefono"></div>
                    <div class="cl-campo"><label>Correo</label><input name="empresa_correo" x-model="f.empresa_correo"></div>
                </div>
            </details>
        </div>

        <div class="cl-card">
            <h2>4. Datos del trabajador</h2>
            <div class="cl-grid">
                <div class="cl-campo cl-ancho"><label>Nombre (como debe salir, con tildes)</label><input name="trabajador_nombre" x-model="f.trabajador_nombre" required></div>
                <div class="cl-campo"><label>Tipo de documento</label>
                    <select name="trabajador_tipo_doc" x-model="f.trabajador_tipo_doc">
                        <option value="CC">CC</option><option value="CE">CE</option><option value="PT">PT</option>
                        <option value="PE">PE</option><option value="PA">PA</option><option value="TI">TI</option>
                    </select>
                </div>
                <div class="cl-campo"><label>Documento</label><input :value="f.trabajador_documento" disabled></div>
                <div class="cl-campo"><label>Trato</label>
                    <select name="trabajador_genero" x-model="f.trabajador_genero">
                        <option value="F">La señora</option><option value="M">El señor</option>
                    </select>
                </div>
                <div class="cl-campo"><label>Cargo</label><input name="cargo" x-model="f.cargo"></div>
                <div class="cl-campo" x-show="f.destino === 'certificacion'"><label>Tipo de contrato</label>
                    <select name="tipo_contrato" x-model="f.tipo_contrato">
                        <option value="indefinido">Término indefinido</option>
                        <option value="fijo">Término fijo</option>
                        <option value="obra">Obra o labor</option>
                        <option value="aprendizaje">Aprendizaje</option>
                        <option value="">— No mencionarlo —</option>
                    </select>
                </div>
                <div class="cl-campo"><label>Fecha de ingreso</label><input type="date" name="fecha_ingreso" x-model="f.fecha_ingreso" required></div>
                <div class="cl-campo"><label>Fecha de retiro (si ya salió)</label><input type="date" name="fecha_retiro" x-model="f.fecha_retiro"></div>
            </div>
        </div>

        <div class="cl-card">
            <h2>5. Jornada y salario</h2>
            <div style="display:flex;gap:1.2rem;flex-wrap:wrap;margin-bottom:.7rem" x-show="f.destino === 'certificacion'">
                <input type="hidden" name="incluir_jornada" :value="f.incluir_jornada ? 1 : 0">
                <input type="hidden" name="incluir_salario" :value="f.incluir_salario ? 1 : 0">
                <label style="font-size:.8rem"><input type="checkbox" x-model="f.incluir_jornada" @change="revisar()"> Incluir jornada</label>
                <label style="font-size:.8rem"><input type="checkbox" x-model="f.incluir_salario" @change="revisar()"> Incluir salario</label>
            </div>
            <div class="cl-grid">
                <div class="cl-campo"><label>Salario mensual ($)</label><input type="number" name="salario" x-model.number="f.salario" min="0" required></div>
                <div class="cl-campo" x-show="f.destino === 'certificacion' && f.incluir_salario">
                    <label>Auxilio de transporte ($)</label>
                    <input type="number" name="auxilio_transporte" x-model.number="f.auxilio_transporte" min="0">
                    <div class="cl-nota" style="margin-top:.2rem">En 0 no sale. Es de ley hasta 2 mínimos.</div>
                </div>
                <div class="cl-campo cl-ancho">
                    <input type="hidden" name="incluir_comisiones" :value="f.incluir_comisiones ? 1 : 0">
                    <label style="font-size:.8rem;text-transform:none;letter-spacing:0;color:#334155;font-weight:600"><input type="checkbox" x-model="f.incluir_comisiones" @change="revisar()"> Recibe ingresos extra por comisiones</label>
                </div>
                <div class="cl-campo" x-show="f.incluir_comisiones" x-cloak>
                    <label>Promedio mensual de comisiones ($)</label>
                    <input type="number" name="comisiones_valor" x-model.number="f.comisiones_valor" min="0">
                    <div class="cl-nota" style="margin-top:.2rem">Opcional: vacío o en 0 solo dice que recibe comisiones, sin valor.</div>
                </div>
                <div class="cl-campo"><label>Año de las condiciones</label><input type="number" name="anio" x-model.number="f.anio" min="2000" max="2100" required></div>
                <div class="cl-campo"><label>Días al mes</label><input type="number" name="dias_mes" x-model.number="f.dias_mes" min="1" max="30" @input="f.horas_mes = f.dias_mes >= 30 ? 210 : f.dias_mes * 7"></div>
                <div class="cl-campo"><label>Horas al mes</label><input type="number" name="horas_mes" x-model.number="f.horas_mes" min="0" max="400"></div>
                <div class="cl-campo cl-ancho">
                    <label>Jornada (así sale en la carta)</label>
                    <textarea name="jornada" x-model="f.jornada" @input="jornadaManual = true"></textarea>
                    <div class="cl-nota" style="margin-top:.25rem">
                        Se arma sola con días y horas mientras no la edites. Agrega cómo se reparte (p. ej. «día de por medio, 8 horas entre semana y menos los sábados»).
                        <a href="#" x-show="jornadaManual" @click.prevent="jornadaManual = false; revisar()">Volver a la sugerida</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="cl-card">
            <h2>6. Destinatario y fecha</h2>
            <div class="cl-grid">
                <div class="cl-campo" x-show="f.destino === 'caja'"><label>Caja de compensación</label><input name="caja_nombre" x-model="f.caja_nombre"></div>
                <div class="cl-campo"><label>Ciudad</label><input name="ciudad" x-model="f.ciudad" required></div>
                <div class="cl-campo"><label>Fecha de la carta</label><input type="date" name="fecha" x-model="f.fecha" required></div>
            </div>
        </div>

        <div class="cl-card">
            <h2>7. Quién firma</h2>
            <div class="cl-tabs" style="margin-bottom:.8rem">
                <button type="button" class="cl-tab" :class="{sel: f.firmante_tipo === 'representante'}" @click="f.firmante_tipo = 'representante'; f.firmante_celular = representante?.celular || ''; revisar()">👤 Representante legal</button>
                <button type="button" class="cl-tab" :class="{sel: f.firmante_tipo === 'usuario'}" @click="f.firmante_tipo = 'usuario'; escogerUsuario(); revisar()">🧑‍💼 Un usuario</button>
            </div>

            <div x-show="f.firmante_tipo === 'representante'">
                <template x-if="representante && representante.documento">
                    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                        <div>
                            <div style="font-weight:700" x-text="representante.nombre"></div>
                            <div class="cl-nota">Representante legal</div>
                        </div>
                        <span class="cl-badge" :class="representante.tiene_firma ? 'cl-ok' : 'cl-gris'" x-text="representante.tiene_firma ? '✓ Firma guardada' : 'Sin firma: línea para firmar a mano'"></span>
                        <button type="button" class="cl-btn sec" @click="abrirFirma(representante.documento, representante.nombre)" x-text="representante.tiene_firma ? 'Cambiar firma' : '⬆️ Subir firma (PNG)'"></button>
                    </div>
                </template>
                <template x-if="!representante || !representante.documento">
                    <p class="cl-error" style="margin:0">La razón social no tiene representante legal registrado. Complétalo en la ficha de la razón social.</p>
                </template>
            </div>

            <div x-show="f.firmante_tipo === 'usuario'" x-cloak>
                <div class="cl-grid">
                    <div class="cl-campo"><label>Usuario</label>
                        <select name="firmante_user_id" x-model.number="f.firmante_user_id" @change="escogerUsuario()">
                            <option value="">— Escoge —</option>
                            <template x-for="u in usuarios" :key="u.id">
                                <option :value="u.id" x-text="u.nombre" :selected="u.id === f.firmante_user_id"></option>
                            </template>
                        </select>
                    </div>
                    <div class="cl-campo"><label>Cargo con que firma</label>
                        <select x-model="f.cargo_opcion" @change="if (f.cargo_opcion !== 'otro') f.firmante_cargo = f.cargo_opcion; revisar()">
                            <option value="">— Escoge —</option>
                            <template x-for="c in cargos" :key="c">
                                <option :value="c" x-text="c" :selected="c === f.cargo_opcion"></option>
                            </template>
                            <option value="otro">Otro…</option>
                        </select>
                    </div>
                    <div class="cl-campo" x-show="f.cargo_opcion === 'otro'"><label>¿Cuál cargo?</label><input name="firmante_cargo" x-model="f.firmante_cargo" placeholder="Ej. Coordinadora de talento humano"></div>
                </div>
                <template x-if="usuarioSel()">
                    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:.7rem">
                        <span class="cl-badge" :class="usuarioSel().tiene_firma ? 'cl-ok' : 'cl-gris'" x-text="usuarioSel().tiene_firma ? '✓ Firma guardada' : 'Sin firma: línea para firmar a mano'"></span>
                        <button type="button" class="cl-btn sec" @click="abrirFirma(usuarioSel().cedula, usuarioSel().nombre)" x-text="usuarioSel().tiene_firma ? 'Cambiar firma' : '⬆️ Subir firma (PNG)'"></button>
                    </div>
                </template>
            </div>
        </div>

        <div class="cl-card" style="margin-top:-.6rem">
            <input type="hidden" name="sello_en_firma" :value="f.sello_en_firma ? 1 : 0">
            <label style="font-size:.8rem;display:flex;gap:.45rem;align-items:flex-start;margin-bottom:.8rem" x-show="empresa?.tiene_sello">
                <input type="checkbox" x-model="f.sello_en_firma" @change="revisar()" style="margin-top:.15rem">
                <span>Poner el <strong>sello</strong> en el lugar de la firma <span class="cl-nota">(para sellos que ya traen la firma; así no se usa la imagen de la firma)</span></span>
            </label>
            <div class="cl-grid">
                <div class="cl-campo"><label>Celular de quien firma</label><input name="firmante_celular" x-model.trim="f.firmante_celular" inputmode="tel" placeholder="Ej. 300 156 3615"></div>
            </div>
            <p class="cl-nota" style="margin:.4rem 0 0">Sale bajo la firma en lugar de la cédula, para que puedan verificar la carta.</p>
        </div>

        <div class="cl-error" x-show="error" x-text="error" x-cloak></div>

        <div class="cl-avisos" x-show="avisos.length" x-cloak>
            <strong style="font-size:.8rem;color:#92400e">Revisa antes de firmar</strong>
            <ul style="margin:.3rem 0 0;padding-left:1.1rem">
                <template x-for="a in avisos"><li x-text="a"></li></template>
            </ul>
        </div>

        <div class="cl-nota" x-show="mensaje" x-text="mensaje" x-cloak style="text-align:right;margin:-.4rem 0 .5rem;color:#065f46"></div>
        <div class="cl-card cl-acciones">
            <button type="button" class="cl-btn sec" @click="enviar('pdf', true)">👁️ Vista previa</button>
            <button type="button" class="cl-btn" @click="enviar('pdf', false)">⬇️ Descargar PDF</button>
            <button type="button" class="cl-btn verde" @click="enviar('word', false)">⬇️ Descargar Word</button>
        </div>
    </form>

    {{-- Historial --}}
    <div class="cl-card">
        <h2>Cartas generadas</h2>
        @if($historial->isEmpty())
            <p class="cl-nota">Todavía no se ha generado ninguna carta en este aliado.</p>
        @else
        <div style="overflow-x:auto">
        <table class="cl-tabla">
            <thead><tr><th>Fecha</th><th>Trabajador</th><th>Empresa</th><th>Para</th><th>Firma</th><th>Generó</th><th></th></tr></thead>
            <tbody>
            @foreach($historial as $carta)
                <tr>
                    <td>{{ $carta->created_at?->format('d/m/Y H:i') }}</td>
                    <td>{{ $carta->datos['trabajador']['nombre'] ?? '' }}<br><span class="cl-nota">{{ $carta->cedula }}</span></td>
                    <td>{{ $carta->datos['empresa']['nombre'] ?? '' }}</td>
                    <td>{{ $carta->destino === 'caja' ? 'Caja · '.($carta->datos['caja_nombre'] ?? '') : 'Certificación' }}</td>
                    <td>{{ $carta->firmante_nombre }}<br><span class="cl-nota">{{ $carta->firmante_cargo }}</span></td>
                    <td>{{ $carta->usuario?->nombre }}</td>
                    <td style="white-space:nowrap">
                        <a class="cl-btn sec" style="padding:.3rem .6rem" href="{{ route('admin.cartas_laborales.descargar', $carta->id) }}?formato=pdf">PDF</a>
                        <a class="cl-btn sec" style="padding:.3rem .6rem" href="{{ route('admin.cartas_laborales.descargar', $carta->id) }}?formato=word">Word</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>

    {{-- Modal de firma --}}
    <div class="cl-modal" x-show="firma.abierta" x-cloak @click.self="firma.abierta = false">
        <div class="cl-modal-box">
            <h2 style="font-size:1rem;margin:0 0 .2rem">Firma de <span x-text="firma.nombre"></span></h2>
            <p class="cl-nota" style="margin:0 0 .8rem">Sube la firma escaneada, mejor en <strong>PNG con fondo transparente</strong> para que se vea limpia sobre la línea. También sirve JPG en fondo blanco.</p>
            <label class="cl-subir">
                <input type="file" x-ref="archivoFirma" accept=".png,.jpg,.jpeg,.webp" style="display:none" @change="escogerArchivoFirma($event)">
                <img x-show="firma.vista" :src="firma.vista" alt="Firma">
                <span x-show="!firma.vista" class="cl-nota">📎 Toca aquí para escoger la imagen</span>
            </label>
            <div style="height:.8rem"></div>
            <p class="cl-error" x-show="firma.error" x-text="firma.error"></p>
            <div style="display:flex;justify-content:flex-end;gap:.5rem">
                <button type="button" class="cl-btn sec" @click="firma.abierta = false">Cancelar</button>
                <button type="button" class="cl-btn" @click="guardarFirma()" :disabled="firma.guardando" x-text="firma.guardando ? 'Guardando…' : 'Guardar firma'"></button>
            </div>
        </div>
    </div>
</div>

<script>
function cartaLaboral() {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const rutas = {
        buscar: @json(route('admin.cartas_laborales.buscar')),
        contrato: @json(url('admin/cartas-laborales/contrato')),
        revisar: @json(route('admin.cartas_laborales.revisar')),
        firma: @json(route('admin.cartas_laborales.firma')),
    };

    return {
        cedula: @json($cedulaInicial ?? ''),
        buscando: false, buscado: false, cargando: false, listo: false,
        cliente: null, contratos: [],
        empresa: null, representante: null,
        usuarios: @json($usuarios),
        cargos: @json($cargos),
        f: {},
        jornadaManual: false,
        avisos: [], error: '', mensaje: '',
        firma: { abierta: false, cedula: '', nombre: '', archivo: null, vista: '', guardando: false, error: '' },

        iniciar() { if (this.cedula) this.buscar(); },

        pesos(v) { return Number(v || 0).toLocaleString('es-CO'); },

        usuarioSel() { return this.usuarios.find(u => u.id === this.f.firmante_user_id) || null; },

        // Al escoger quien firma se traen el cargo y el celular de su última carta.
        escogerUsuario() {
            const u = this.usuarioSel();
            if (!u) return;
            if (u.cargo) {
                this.f.firmante_cargo = u.cargo;
                this.f.cargo_opcion = this.cargos.includes(u.cargo) ? u.cargo : 'otro';
            }
            this.f.firmante_celular = u.celular || '';
        },

        async buscar() {
            this.buscando = true; this.listo = false; this.error = '';
            try {
                const r = await fetch(rutas.buscar + '?cedula=' + encodeURIComponent(this.cedula), { headers: { Accept: 'application/json' } });
                const d = await r.json();
                this.cliente = d.cliente; this.contratos = d.contratos || [];
                this.buscado = true;
                const vigentes = this.contratos.filter(c => c.estado === 'vigente' && c.tiene_razon_social);
                if (vigentes.length === 1) this.escoger(vigentes[0]);
            } finally { this.buscando = false; }
        },

        async escoger(c) {
            this.cargando = true; this.error = '';
            try {
                const r = await fetch(rutas.contrato + '/' + c.id, { headers: { Accept: 'application/json' } });
                const p = await r.json();
                this.empresa = p.empresa;
                this.representante = p.representante;
                this.jornadaManual = false;
                this.f = {
                    contrato_id: p.contrato_id, razon_social_id: p.razon_social_id,
                    destino: this.f.destino || 'caja',
                    trabajador_nombre: p.trabajador_nombre, trabajador_tipo_doc: p.trabajador_tipo_doc,
                    trabajador_documento: p.trabajador_documento, trabajador_genero: p.trabajador_genero,
                    cargo: p.cargo, fecha_ingreso: p.fecha_ingreso, fecha_retiro: p.fecha_retiro || '',
                    salario: p.salario, auxilio_transporte: p.auxilio_transporte, tipo_contrato: p.tipo_contrato, anio: p.anio, dias_mes: p.dias_mes, horas_mes: p.horas_mes, jornada: p.jornada,
                    incluir_jornada: false, incluir_salario: true, incluir_comisiones: false, comisiones_valor: '',
                    caja_nombre: p.caja_nombre, ciudad: p.ciudad, fecha: p.fecha,
                    firmante_tipo: 'representante', firmante_user_id: '', firmante_cargo: '', cargo_opcion: '',
                    firmante_celular: p.representante?.celular || '', sello_en_firma: false,
                    empresa_direccion: p.empresa?.direccion || '', empresa_telefono: p.empresa?.telefono || '', empresa_correo: p.empresa?.correo || '',
                };
                this.listo = true;
                this.$nextTick(() => this.revisar());
            } finally { this.cargando = false; }
        },

        datosFormulario() { return new FormData(this.$refs.form); },

        async revisar() {
            if (!this.listo) return;
            const r = await fetch(rutas.revisar, {
                method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: this.datosFormulario(),
            });
            const d = await r.json();
            if (r.ok) {
                this.error = '';
                this.avisos = d.avisos || [];
                if (!this.jornadaManual && d.jornada_sugerida) this.f.jornada = d.jornada_sugerida;
            } else {
                this.avisos = [];
                this.error = d.errors ? Object.values(d.errors).flat()[0] : (d.message || 'Revisa los datos.');
            }
        },

        enviar(formato, vistaPrevia) {
            if (this.error) return;
            this.$refs.formato.value = formato;
            this.$refs.vistaPrevia.value = vistaPrevia ? 1 : 0;
            this.$refs.form.target = vistaPrevia ? '_blank' : '';
            this.$refs.form.submit();
            if (!vistaPrevia) this.mensaje = 'Descargando. La carta quedó en el historial (se ve al recargar la página).';
        },

        // ── Firma ──
        abrirFirma(cedula, nombre) {
            Object.assign(this.firma, { abierta: true, cedula, nombre, archivo: null, vista: '', error: '' });
            this.$nextTick(() => { if (this.$refs.archivoFirma) this.$refs.archivoFirma.value = ''; });
        },
        escogerArchivoFirma(e) {
            this.firma.archivo = e.target.files[0] || null;
            this.firma.vista = this.firma.archivo ? URL.createObjectURL(this.firma.archivo) : '';
        },
        async guardarFirma() {
            const fd = new FormData();
            fd.append('cedula', this.firma.cedula);
            if (!this.firma.archivo) { this.firma.error = 'Escoge la imagen de la firma.'; return; }
            fd.append('firma', this.firma.archivo);

            this.firma.guardando = true; this.firma.error = '';
            try {
                const r = await fetch(rutas.firma, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: fd });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) { this.firma.error = d.errors ? Object.values(d.errors).flat()[0] : (d.message || 'No se pudo guardar.'); return; }
                if (this.representante && this.representante.documento === this.firma.cedula) this.representante.tiene_firma = true;
                this.usuarios.filter(u => u.cedula === this.firma.cedula).forEach(u => u.tiene_firma = true);
                this.firma.abierta = false;
                this.revisar();
            } finally { this.firma.guardando = false; }
        },
    };
}
</script>
@endsection
