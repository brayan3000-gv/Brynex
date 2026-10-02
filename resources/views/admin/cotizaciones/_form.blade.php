{{--
    Formulario del prospecto + cotizador + resumen. Lo comparten «Nueva cotización»
    y el detalle. Va dentro de un <form> con x-data="cotizadorProspecto()".

    $prospecto   — CotizacionProspecto (nuevo o existente)
    $lookups     — listas del controlador
    $textoGuardar, $urlCancelar (opcional)
--}}
@php
    $esNuevo = !$prospecto->exists;
@endphp

<input type="hidden" name="resultado_cotizacion" x-ref="resultado">

<div class="cz-layout">

    {{-- ══ COLUMNA IZQUIERDA: datos ══ --}}
    <div class="cz-col-form">

        {{-- 1. Datos del prospecto --}}
        <section class="cz-card">
            <div class="cz-card-head">
                <h2 class="cz-card-title"><span class="cz-step">1</span> Datos del prospecto</h2>
            </div>

            <div class="cz-grid">
                <div class="cz-field">
                    <label class="cz-label" for="cz_cedula">Documento</label>
                    <div class="cz-group">
                        <select name="tipo_doc" x-model="tipoDoc" class="cz-input cz-group-fijo" aria-label="Tipo de documento">
                            @foreach($lookups['tipos_doc'] as $key => $val)
                                <option value="{{ $key }}" title="{{ $val }}">{{ $key }}</option>
                            @endforeach
                            @if($prospecto->tipo_doc && !isset($lookups['tipos_doc'][$prospecto->tipo_doc]))
                                <option value="{{ $prospecto->tipo_doc }}">{{ $prospecto->tipo_doc }}</option>
                            @endif
                        </select>
                        <input type="text" id="cz_cedula" name="cedula" class="cz-input" value="{{ old('cedula', $prospecto->cedula) }}"
                               placeholder="Número" autocomplete="off" :inputmode="tipoDoc === 'PA' ? 'text' : 'numeric'">
                    </div>
                </div>
                <div class="cz-field cz-span2">
                    <label class="cz-label" for="cz_nombre">Nombre completo <span class="cz-req">*</span></label>
                    <input type="text" id="cz_nombre" name="nombre_completo" class="cz-input" value="{{ old('nombre_completo', $prospecto->nombre_completo) }}"
                           placeholder="Ej: Juan Pérez García" autocomplete="off" required>
                </div>
                <div class="cz-field">
                    <label class="cz-label" for="cz_celular">Celular <span class="cz-req">*</span></label>
                    <div class="cz-group">
                        <input type="tel" id="cz_celular" name="celular" x-model="celular" class="cz-input" placeholder="3001234567" inputmode="numeric" autocomplete="off" required>
                        <a :href="celularValido ? 'https://wa.me/57' + celularLimpio : null" target="_blank" rel="noopener"
                           class="cz-wa" :class="{ 'cz-wa--off': !celularValido }" title="Escribir por WhatsApp" aria-label="Escribir por WhatsApp">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.948h.003c4.368 0 7.927-3.559 7.931-7.928a7.86 7.86 0 0 0-2.327-5.594ZM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592Zm3.69-4.98c-.202-.101-1.202-.594-1.392-.66-.189-.07-.327-.101-.466.101-.139.2-.538.66-.66.8-.12.138-.241.156-.443.055-.202-.101-.85-.313-1.62-.998-.6-.535-1.005-1.197-1.123-1.401-.118-.202-.012-.311.089-.412.091-.09.202-.236.302-.354.101-.118.135-.2.203-.332.067-.134.034-.251-.017-.352-.05-.101-.466-1.123-.638-1.54-.168-.403-.34-.348-.466-.354-.121-.006-.26-.008-.399-.008-.14 0-.368.052-.56.26-.192.208-.733.717-.733 1.748 0 1.03.75 2.023.854 2.163.104.14 1.478 2.256 3.58 3.162.5.216.89.345 1.196.443.502.16 1.037.137 1.429.078.437-.066 1.202-.492 1.371-.963.17-.472.17-.878.118-.963-.05-.084-.191-.133-.393-.234Z"/></svg>
                        </a>
                    </div>
                </div>

                <div class="cz-field cz-span2">
                    <label class="cz-label" for="cz_correo">Correo electrónico</label>
                    <input type="email" id="cz_correo" name="correo" class="cz-input" value="{{ old('correo', $prospecto->correo) }}" placeholder="correo@ejemplo.com" autocomplete="off">
                </div>
                <div class="cz-field">
                    <label class="cz-label" for="cz_municipio">Municipio / ciudad</label>
                    <input type="text" id="cz_municipio" name="municipio" class="cz-input" value="{{ old('municipio', $prospecto->getRawOriginal('municipio')) }}" placeholder="¿De dónde escribe?">
                </div>
                <div class="cz-field">
                    <label class="cz-label" for="cz_canal">Canal de origen</label>
                    <select id="cz_canal" name="canal_origen" class="cz-input">
                        <option value="">Seleccione…</option>
                        @foreach($lookups['canales'] as $key => $val)
                            <option value="{{ $key }}" {{ old('canal_origen', $prospecto->canal_origen) == $key ? 'selected' : '' }}>{{ $val }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="cz-field cz-span2">
                    <label class="cz-label" for="cz_referido">Referido por</label>
                    <input type="text" id="cz_referido" name="referido" class="cz-input" value="{{ old('referido', $prospecto->referido) }}" placeholder="Quién lo recomendó (opcional)">
                </div>
                <div class="cz-field cz-span2">
                    <label class="cz-label" for="cz_asesor">Asesor asignado</label>
                    <select id="cz_asesor" name="asesor_id" class="cz-input">
                        <option value="">Sin asignar</option>
                        @foreach($lookups['asesores'] as $id => $nombre)
                            <option value="{{ $id }}" {{ old('asesor_id', $prospecto->asesor_id) == $id ? 'selected' : '' }}>{{ nombre_oracion($nombre) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        {{-- 2. Cotizador --}}
        <section class="cz-card">
            <div class="cz-card-head">
                <h2 class="cz-card-title"><span class="cz-step">2</span> Cotizador de plan</h2>
            </div>

            <div class="cz-grid">
                <div class="cz-field">
                    <label class="cz-label" for="cz_perfil">Perfil</label>
                    <select id="cz_perfil" name="es_independiente" x-model="esIndependiente" @change="onPerfilChange()" class="cz-input">
                        <option value="0">Empresa</option>
                        <option value="1">Independiente</option>
                    </select>
                </div>
                <div class="cz-field">
                    <label class="cz-label" for="cz_modalidad">Modalidad <span class="cz-req">*</span></label>
                    <select id="cz_modalidad" name="modalidad_id" x-model="modalidadId" @change="onModalidadChange()" class="cz-input" required>
                        <option value="">Seleccione…</option>
                        <template x-for="mod in modalidadesFiltradas" :key="mod.id">
                            <option :value="String(mod.id)" :selected="String(mod.id) === modalidadId" x-text="mod.nombre"></option>
                        </template>
                    </select>
                </div>
                <div class="cz-field">
                    <label class="cz-label" for="cz_plan">Plan <span class="cz-req">*</span></label>
                    <select id="cz_plan" name="plan_id" x-model="planId" @change="recalcular()" class="cz-input" :disabled="!modalidadId" required>
                        <option value="" x-text="modalidadId ? 'Seleccione…' : 'Elija la modalidad'"></option>
                        <template x-for="p in planesFiltrados" :key="p.id">
                            <option :value="String(p.id)" :selected="String(p.id) === planId" x-text="p.nombre"></option>
                        </template>
                    </select>
                </div>
                <div class="cz-field">
                    <label class="cz-label" for="cz_riesgo">Riesgo ARL</label>
                    <select id="cz_riesgo" name="n_arl" x-model="nivelArl" @change="recalcular()" class="cz-input">
                        <option value="1">I · Bajo</option>
                        <option value="2">II</option>
                        <option value="3">III</option>
                        <option value="4">IV</option>
                        <option value="5">V · Alto</option>
                    </select>
                </div>

                <div class="cz-field cz-m-mitad">
                    <label class="cz-label" for="cz_salario">Salario base <span class="cz-req">*</span></label>
                    <div class="cz-money">
                        <input type="text" id="cz_salario" class="cz-input" inputmode="numeric" autocomplete="off" required
                               :value="miles(salario)" @focus="$el.select()" @input="salario = aNumero($el.value); $el.value = miles(salario)" @change="recalcular()">
                    </div>
                    <input type="hidden" name="salario_base" :value="salario">
                </div>
                <div class="cz-field cz-m-mitad">
                    <label class="cz-label" for="cz_fecha">Fecha de ingreso</label>
                    <input type="date" id="cz_fecha" name="fecha_ingreso" x-model="fechaIngreso" @change="calcularDias(); recalcular()" class="cz-input">
                </div>
                <div class="cz-field cz-m-mitad">
                    <label class="cz-label" for="cz_afiliacion">Cobro afiliación</label>
                    <div class="cz-money">
                        <input type="text" id="cz_afiliacion" class="cz-input" inputmode="numeric" autocomplete="off"
                               :value="miles(costoAfiliacion)" @focus="$el.select()" @input="costoAfiliacion = aNumero($el.value); $el.value = miles(costoAfiliacion)" @change="modalidadId === '15' && recalcular()">
                    </div>
                    <input type="hidden" name="costo_afiliacion" :value="costoAfiliacion">
                </div>
                <div class="cz-field cz-m-mitad">
                    <label class="cz-label" for="cz_admon">Administración</label>
                    <div class="cz-money">
                        <input type="text" id="cz_admon" class="cz-input" inputmode="numeric" autocomplete="off"
                               :value="miles(administracion)" @focus="$el.select()" @input="administracion = aNumero($el.value); $el.value = miles(administracion)" @change="recalcular()">
                    </div>
                    <input type="hidden" name="administracion" :value="administracion">
                </div>
            </div>
        </section>

        {{-- Guardar (en celular queda fija abajo, con el valor mensual a la vista) --}}
        <div class="cz-actions">
            <div class="cz-actions-total" x-show="planId" x-cloak>
                <small x-text="resultFull.total > 0 ? 'Mensual' : 'Total'"></small>
                <strong x-text="fmt(resultFull.total > 0 ? resultFull.total : resultProp.total)"></strong>
            </div>
            @isset($urlCancelar)
                <a href="{{ $urlCancelar }}" class="cz-btn cz-btn--borde">Cancelar</a>
            @endisset
            <button type="submit" class="cz-btn cz-btn--primario" :disabled="guardando">
                <span x-show="!guardando">{{ $textoGuardar }}</span>
                <span x-show="guardando" x-cloak>Guardando…</span>
            </button>
        </div>
    </div>

    {{-- ══ COLUMNA DERECHA: resumen ══ --}}
    <aside>
        <div class="cz-resumen">
            <h3 class="cz-resumen-title">
                Resumen de cotización
                <span class="cz-resumen-estado" x-show="cargando" x-cloak>Calculando…</span>
            </h3>

            @if(($prospecto->resultado_cotizacion['cotizado_por'] ?? null) === 'asistente_ia')
                <div class="cz-ia-nota">
                    Cotizado por el asistente de IA
                    @if(!empty($prospecto->resultado_cotizacion['valor_mensual']))
                        · le informó ${{ number_format($prospecto->resultado_cotizacion['valor_mensual'], 0, ',', '.') }} al mes
                    @endif
                </div>
            @endif

            <div class="cz-resumen-error" x-show="error" x-cloak>
                No se pudo calcular la cotización. <button type="button" @click="recalcular()">Reintentar</button>
            </div>

            <div class="cz-resumen-vacio" x-show="!planId">
                Elija modalidad y plan para ver el cálculo.
            </div>

            <div class="cz-resumen-cuerpo" x-show="planId" x-cloak :class="{ 'cz-calculando': cargando, 'cz-sin-prop': !hayProporcional }">

                <div class="cz-fila cz-fila--head">
                    <div>Concepto</div>
                    <div class="cz-col-prop">Primer mes<br><span x-text="`${diasProporcionales} días`"></span></div>
                    <div>Mes completo</div>
                </div>

                <div class="cz-fila">
                    <div>Salud (EPS) <span class="cz-pct" x-show="pctEps > 0 && (resultFull.eps || resultProp.eps) > 0" x-text="`${pctEps}%`"></span></div>
                    <div class="cz-col-prop" x-text="fmt(resultProp.eps)"></div>
                    <div x-text="fmt(resultFull.eps)"></div>
                </div>
                <div class="cz-fila">
                    <div>Pensión (AFP) <span class="cz-pct" x-show="pctPen > 0 && (resultFull.pen || resultProp.pen) > 0" x-text="`${pctPen}%`"></span></div>
                    <div class="cz-col-prop" x-text="fmt(resultProp.pen)"></div>
                    <div x-text="fmt(resultFull.pen)"></div>
                </div>
                <div class="cz-fila">
                    <div>Riesgos (ARL) <span class="cz-pct" x-show="pctArl > 0 && (resultFull.arl || resultProp.arl) > 0" x-text="`${pctArl}%`"></span></div>
                    <div class="cz-col-prop" x-text="fmt(resultProp.arl)"></div>
                    <div x-text="fmt(resultFull.arl)"></div>
                </div>
                <div class="cz-fila">
                    <div>Caja (CCF) <span class="cz-pct" x-show="pctCaja > 0 && (resultFull.caja || resultProp.caja) > 0" x-text="`${pctCaja}%`"></span></div>
                    <div class="cz-col-prop" x-text="fmt(resultProp.caja)"></div>
                    <div x-text="fmt(resultFull.caja)"></div>
                </div>
                <div class="cz-fila cz-fila--fijo">
                    <div>Administración</div>
                    <div class="cz-col-prop" x-text="fmt(resultFull.admon)"></div>
                    <div x-text="fmt(resultFull.admon)"></div>
                </div>
                <div class="cz-fila cz-fila--fijo" x-show="resultFull.seguro > 0">
                    <div>Seguro</div>
                    <div class="cz-col-prop" x-text="fmt(resultFull.seguro)"></div>
                    <div x-text="fmt(resultFull.seguro)"></div>
                </div>

                <div class="cz-totales">
                    <div class="cz-total">
                        <div class="cz-total-nombre">Afiliación <span class="cz-total-detalle">(pago único)</span></div>
                        <div class="cz-total-valor cz-total-valor--afiliacion" x-text="fmt(costoAfiliacion)"></div>
                    </div>
                    <div class="cz-total" x-show="hayProporcional">
                        <div>
                            <div class="cz-total-nombre">Primer mes proporcional</div>
                            <div class="cz-total-detalle">Por <span x-text="diasProporcionales"></span> días</div>
                        </div>
                        <div class="cz-total-valor cz-total-valor--prop" x-text="fmt(resultProp.total)"></div>
                    </div>
                    <div class="cz-total">
                        <div>
                            <div class="cz-total-nombre">Mensual completo</div>
                            <div class="cz-total-detalle">Meses siguientes (30 días)</div>
                        </div>
                        <div class="cz-total-valor cz-total-valor--mes" x-text="fmt(resultFull.total)"></div>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</div>

@php
    $configCotizador = [
        'tipoDoc'         => old('tipo_doc', $prospecto->tipo_doc ?: 'CC'),
        'celular'         => (string) old('celular', $prospecto->celular ?? ''),
        'salario'         => (int) round((float) old('salario_base', $prospecto->salario_base ?? $lookups['salarioMinimo'])),
        'costoAfiliacion' => (int) round((float) old('costo_afiliacion', $prospecto->costo_afiliacion ?? $lookups['costo_afiliacion_default'])),
        'administracion'  => (int) round((float) old('administracion', $prospecto->administracion ?? $lookups['administracion_default'])),
        'esIndependiente' => (string) old('es_independiente', $prospecto->es_independiente ? '1' : '0'),
        'nivelArl'        => (string) old('n_arl', $prospecto->n_arl ?: '1'),
        'modalidadId'     => (string) old('modalidad_id', $prospecto->modalidad_id ?? ''),
        'planId'          => (string) old('plan_id', $prospecto->plan_id ?? ''),
        'fechaIngreso'    => old('fecha_ingreso', $esNuevo ? date('Y-m-d') : ($prospecto->fecha_ingreso?->format('Y-m-d') ?? '')),
        'guardado'        => $prospecto->resultado_cotizacion,
        'urlCotizar'      => route('admin.contratos.cotizar'),
        'planesPorModalidad' => $lookups['planesPermitidos'] ?? [],
        'planes'          => collect($lookups['planes'] ?? [])->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])->values(),
        'modalidades'     => collect($lookups['modalidades'])->map(fn ($m) => [
            'id' => $m->id,
            'nombre' => $m->observacion ?: $m->tipo_modalidad,
            'independiente' => in_array($m->id, $lookups['modalidadesIndependientes']),
        ])->values(),
    ];
@endphp
<script>
    window.CZ_COTIZADOR = @json($configCotizador);
</script>
