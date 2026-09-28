@extends('layouts.app')
@section('modulo', 'Traslado de Razón Social')

@section('contenido')
<style>
/* ── Estructura general ─────────────────────────────── */
.trs-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;padding:1rem 1.4rem;margin-bottom:1.2rem;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem}
.trs-h-title{font-size:1.05rem;font-weight:800}
.trs-h-sub{font-size:.72rem;color:#94a3b8;margin-top:.2rem}

/* ── Pasos (stepper) ────────────────────────────────── */
.trs-steps{display:flex;gap:.5rem;margin-bottom:1.2rem;flex-wrap:wrap}
.trs-step{display:flex;align-items:center;gap:.45rem;padding:.45rem .9rem;border-radius:30px;font-size:.78rem;font-weight:700;border:1.5px solid #e2e8f0;background:#fff;color:#94a3b8;white-space:nowrap}
.trs-step.active{border-color:#2563eb;background:#eff6ff;color:#2563eb}
.trs-step.done{border-color:#16a34a;background:#f0fdf4;color:#16a34a}
.trs-step-num{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;background:#e2e8f0;color:#94a3b8}
.trs-step.active .trs-step-num{background:#2563eb;color:#fff}
.trs-step.done .trs-step-num{background:#16a34a;color:#fff}

/* ── Tarjetas de sección ────────────────────────────── */
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.2rem 1.4rem;margin-bottom:1rem;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.card-title{font-size:.85rem;font-weight:800;color:#0f172a;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem}
.card-title span{font-size:1rem}

/* ── Form controls ──────────────────────────────────── */
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.85rem;margin-bottom:.85rem}
.form-group label{display:block;font-size:.72rem;font-weight:700;color:#475569;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.04em}
.form-control{width:100%;padding:.5rem .8rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.85rem;color:#0f172a;background:#f8fafc;transition:border-color .2s;box-sizing:border-box}
.form-control:focus{outline:none;border-color:#3b82f6;background:#fff}
textarea.form-control{resize:vertical;min-height:80px;font-family:monospace;font-size:.8rem}

/* ── Botones ─────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.5rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;border:none;transition:all .15s;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.3)}
.btn-primary:hover{background:linear-gradient(135deg,#1d4ed8,#1e40af)}
.btn-success{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 2px 8px rgba(22,163,74,.25)}
.btn-success:hover{background:linear-gradient(135deg,#15803d,#166534)}
.btn-warning{background:linear-gradient(135deg,#d97706,#b45309);color:#fff}
.btn-danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff}
.btn-ghost{background:#f1f5f9;color:#334155;border:1.5px solid #cbd5e1}
.btn-ghost:hover{background:#e2e8f0}
.btn:disabled{opacity:.5;cursor:not-allowed}

/* ── Tabla resultados ────────────────────────────────── */
.tbl-wrap{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:auto}
.tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.tbl th{background:#f8fafc;color:#64748b;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;padding:.5rem .75rem;text-align:left;border-bottom:2px solid #e2e8f0;white-space:nowrap}
.tbl td{padding:.45rem .75rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#f8fafc}
.tbl .check-col{width:36px;text-align:center}
.badge{display:inline-block;font-size:.6rem;font-weight:700;padding:.1rem .45rem;border-radius:20px;white-space:nowrap}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-green{background:#dcfce7;color:#15803d}
.badge-red{background:#fee2e2;color:#dc2626}
.badge-amber{background:#fef3c7;color:#b45309}
.badge-gray{background:#f1f5f9;color:#64748b}

/* ── Panel retiro (opciones A y B) ──────────────────── */
.retiro-cards{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:640px){.retiro-cards{grid-template-columns:1fr}}
.retiro-card{border:2px solid #e2e8f0;border-radius:12px;padding:1.1rem;cursor:pointer;transition:all .2s;position:relative}
.retiro-card:hover{border-color:#3b82f6;box-shadow:0 4px 12px rgba(59,130,246,.15)}
.retiro-card.selected{border-color:#2563eb;background:#eff6ff}
.retiro-card-title{font-size:.88rem;font-weight:800;color:#0f172a;margin-bottom:.3rem}
.retiro-card-sub{font-size:.75rem;color:#64748b}
.retiro-card-icon{font-size:1.6rem;margin-bottom:.5rem}
.retiro-card input[type=radio]{position:absolute;top:.8rem;right:.8rem}

/* ── Modal de retiro ─────────────────────────────────── */
.modal-overlay{display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-box{background:#fff;border-radius:18px;width:100%;max-width:560px;margin:1rem;box-shadow:0 24px 64px rgba(0,0,0,.25);overflow:hidden;max-height:90vh;display:flex;flex-direction:column}
.modal-head{padding:1.1rem 1.4rem .8rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#0f172a,#1e3a5f)}
.modal-head h3{font-size:.95rem;font-weight:800;color:#fff}
.modal-head-sub{font-size:.7rem;color:#94a3b8;margin-top:.15rem}
.modal-close{border:none;background:rgba(255,255,255,.1);color:#fff;font-size:1.1rem;cursor:pointer;border-radius:6px;width:28px;height:28px;display:flex;align-items:center;justify-content:center}
.modal-body{padding:1.2rem 1.4rem;overflow-y:auto;flex:1}
.modal-foot{padding:.85rem 1.4rem;border-top:1px solid #f1f5f9;display:flex;gap:.6rem;justify-content:flex-end;background:#f8fafc}

/* ── Alertas ─────────────────────────────────────────── */
.alert{padding:.65rem 1rem;border-radius:10px;font-size:.82rem;margin-bottom.75rem}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.alert-warn{background:#fef9c3;border:1px solid #fde68a;color:#92400e}
.alert-danger{background:#fee2e2;border:1px solid #fca5a5;color:#dc2626}
.alert-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}

/* ── Carga ───────────────────────────────────────────── */
.spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

{{-- ─── HEADER ─────────────────────────────────────────────────────────────── --}}
<div class="trs-header">
    <div>
        <a href="{{ route('admin.configuracion.razones.index') }}" style="color:#94a3b8;font-size:.73rem;text-decoration:none">← Razones Sociales</a>
        <div class="trs-h-title">🔄 Traslado de Razón Social</div>
        <div class="trs-h-sub">Transfiere personas de una empresa a otra · Gestiona retiros y nuevas afiliaciones</div>
    </div>
</div>

{{-- ─── STEPPER ─────────────────────────────────────────────────────────────── --}}
<div class="trs-steps" id="stepper">
    <div class="trs-step active" id="step-ind-1"><div class="trs-step-num">1</div> Seleccionar personas</div>
    <div class="trs-step" id="step-ind-2"><div class="trs-step-num">2</div> Configurar destino</div>
    <div class="trs-step" id="step-ind-3"><div class="trs-step-num">3</div> Confirmar traslado</div>
    <div class="trs-step" id="step-ind-4"><div class="trs-step-num">4</div> Gestionar retiro</div>
</div>

{{-- ─── PASO 1: Selección de RS origen + cédulas ────────────────────────────── --}}
<div id="paso1">
    <div class="card">
        <div class="card-title"><span>🏭</span> Paso 1: Cédulas y Razón Social de Origen</div>

        <div class="form-row">
            <div class="form-group">
                <label>Razón Social de Origen *</label>
                <select class="form-control" id="rs-origen-sel">
                    <option value="">— Seleccionar empresa —</option>
                    @foreach($razonesSociales as $rs)
                    <option value="{{ $rs->id }}" data-nombre="{{ $rs->razon_social }}" data-nplano="{{ $rs->n_plano }}">
                        {{ $rs->razon_social }}
                        @if($rs->estado !== 'Activa') (Inactiva) @endif
                    </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:.85rem">
            <label>Cédulas a trasladar *</label>
            <textarea class="form-control" id="cedulas-input" rows="5"
                      placeholder="Pega las cédulas aquí, una por línea, o separadas por coma/punto y coma:&#10;&#10;31655834&#10;12345678&#10;87654321"></textarea>
            <div style="font-size:.7rem;color:#94a3b8;margin-top:.3rem">Acepta saltos de línea, comas o punto y coma como separadores.</div>
        </div>

        <button class="btn btn-primary" id="btn-validar" onclick="validarCedulas()">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Validar Cédulas
        </button>
    </div>
</div>

{{-- ─── PASO 2: Resultados + configuración destino ─────────────────────────── --}}
<div id="paso2" style="display:none">

    {{-- Alerta cédulas no encontradas --}}
    <div id="alerta-no-encontradas" class="alert alert-warn" style="display:none;margin-bottom:.75rem">
        ⚠️ <strong>Cédulas sin contrato vigente en la RS seleccionada:</strong>
        <span id="lista-no-encontradas"></span>
    </div>

    {{-- Tabla de contratos encontrados --}}
    <div class="card">
        <div class="card-title" style="justify-content:space-between">
            <div style="display:flex;align-items:center;gap:.4rem"><span>📋</span> Contratos Vigentes Encontrados</div>
            <div style="display:flex;gap:.5rem;align-items:center">
                <span id="contador-sel" style="font-size:.75rem;color:#64748b;font-weight:600">0 seleccionados</span>
                <button class="btn btn-ghost" style="padding:.3rem .7rem;font-size:.72rem" onclick="toggleTodos()">Seleccionar todos</button>
            </div>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="check-col"><input type="checkbox" id="chk-todos" onchange="toggleTodosCheck(this)"></th>
                        <th>Cédula</th>
                        <th>Nombre</th>
                        <th>RS Actual</th>
                        <th>Plan</th>
                        <th>Modalidad</th>
                        <th>EPS</th>
                        <th>Pensión</th>
                        <th>ARL</th>
                        <th>Caja</th>
                        <th>Salario</th>
                        <th>Ingreso</th>
                    </tr>
                </thead>
                <tbody id="tbody-contratos"></tbody>
            </table>
        </div>
    </div>

    {{-- Configuración destino --}}
    <div class="card">
        <div class="card-title"><span>🎯</span> Paso 2: Configurar Destino</div>
        <div class="form-row">
            <div class="form-group">
                <label>Nueva Razón Social (Destino) *</label>
                <select class="form-control" id="rs-destino-sel">
                    <option value="">— Seleccionar empresa destino —</option>
                    @foreach($razonesSociales->where('estado','Activa') as $rs)
                    <option value="{{ $rs->id }}">{{ $rs->razon_social }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>Encargado de la Afiliación *</label>
                <select class="form-control" id="encargado-sel">
                    <option value="">— Seleccionar encargado —</option>
                    @foreach($usuarios as $u)
                    <option value="{{ $u->id }}">{{ $u->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>Fecha de Ingreso (calculada)</label>
                <input type="text" class="form-control" id="fecha-ingreso-display"
                       value="{{ now()->startOfMonth()->format('d/m/Y') }} (1ro del mes actual)"
                       readonly style="background:#f8fafc;color:#64748b">
            </div>
        </div>

        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.5rem">
            <button class="btn btn-ghost" onclick="volverPaso1()">← Volver</button>
            <button class="btn btn-primary" id="btn-confirmar" onclick="mostrarConfirmacion()">
                Continuar → Confirmar Traslado
            </button>
        </div>
    </div>
</div>

{{-- ─── PASO 3: Modal de Confirmación ──────────────────────────────────────── --}}
<div class="modal-overlay" id="modalConfirmar">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>🔄 Confirmar Traslado</h3>
                <div class="modal-head-sub">Revisa el resumen antes de ejecutar</div>
            </div>
            <button class="modal-close" onclick="cerrarModal('modalConfirmar')">✕</button>
        </div>
        <div class="modal-body">
            <div id="confirm-resumen"></div>
            <div class="alert alert-warn" style="margin-top:.75rem">
                ⚠️ Esta acción creará <strong id="confirm-total">N</strong> nuevos contratos + planos de afiliación en la nueva RS.
                Los contratos anteriores permanecerán vigentes hasta que se gestione el retiro (paso siguiente).
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="cerrarModal('modalConfirmar')">Cancelar</button>
            <button class="btn btn-success" id="btn-ejecutar" onclick="ejecutarTraslado()">
                ✅ Confirmar y Ejecutar Traslado
            </button>
        </div>
    </div>
</div>

{{-- ─── PASO 4: Gestión de Retiro ──────────────────────────────────────────── --}}
<div id="paso4" style="display:none">
    <div class="card">
        <div class="alert alert-ok" style="margin-bottom:1rem">
            ✅ <strong id="p4-msg-exito">Traslado completado correctamente.</strong>
            Ahora gestiona el retiro de los contratos anteriores.
        </div>

        <div class="card-title"><span>📅</span> Paso 4: Tipo de Retiro</div>

        <div class="retiro-cards">
            {{-- OPCIÓN A --}}
            <label class="retiro-card" id="card-opcion-a" onclick="seleccionarOpcion('A')">
                <input type="radio" name="tipo_retiro" value="A" id="radio-a">
                <div class="retiro-card-icon">📤</div>
                <div class="retiro-card-title">Opción A · Retiro en Planilla Anterior</div>
                <div class="retiro-card-sub">La persona ya pagó su última planilla en la razón social de origen. Se le agrega el retiro como corrección de ESA planilla (queda en el historial de planos) y se descarga una planilla N por cada planilla corregida.</div>
            </label>

            {{-- OPCIÓN B --}}
            <label class="retiro-card" id="card-opcion-b" onclick="seleccionarOpcion('B')">
                <input type="radio" name="tipo_retiro" value="B" id="radio-b">
                <div class="retiro-card-icon">📅</div>
                <div class="retiro-card-title">Opción B · Retiro en Mes Siguiente</div>
                <div class="retiro-card-sub">Se crea un plano de retiro futuro en el mes indicado. La fecha de retiro se calcula automáticamente (1 del mes de retiro, o misma fecha si coincide con el ingreso).</div>
            </label>
        </div>

        {{-- Formulario Opción A --}}
        <div id="form-opcion-a" style="display:none;margin-top:1rem;padding:1rem;background:#f8fafc;border-radius:12px;border:1.5px solid #e2e8f0">
            <div style="font-size:.8rem;font-weight:700;color:#0f172a;margin-bottom:.75rem">📤 Configurar retiro en planilla anterior</div>
            <div style="font-size:.74rem;color:#475569;margin-bottom:.6rem">
                Cada persona se retira en <strong>su última planilla pagada</strong>, con fecha el último día de ese período.
                Revisa la tabla: solo se aplica a las filas en verde.
            </div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Cédula</th>
                            <th>Nombre</th>
                            <th>Razón social</th>
                            <th>Período</th>
                            <th>Plano pagado</th>
                            <th>Días</th>
                            <th>Planilla</th>
                            <th>Pagada</th>
                            <th>Fecha retiro</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-retiro-a">
                        <tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:1rem">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- Descarga TXT --}}
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.85rem;margin-top:.5rem">
                <div style="font-size:.78rem;font-weight:700;color:#1d4ed8;margin-bottom:.5rem">📥 Descarga TXT MiPlanilla con novedades ING+RET</div>
                <div class="form-row" style="margin-bottom:.5rem">
                    <div class="form-group">
                        <label>Operador MiPlanilla</label>
                        <select class="form-control" id="a-operador-id">
                            <option value="">— Seleccionar operador —</option>
                            @foreach($operadores as $op)
                            <option value="{{ $op->id }}">{{ $op->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:.6rem;margin-top:.85rem;flex-wrap:wrap">
                <button class="btn btn-primary" id="btn-retiro-a" onclick="ejecutarRetiroA()" disabled>
                    📤 Aplicar Retiro en Planilla Anterior
                </button>
            </div>
        </div>

        {{-- Formulario Opción B --}}
        <div id="form-opcion-b" style="display:none;margin-top:1rem;padding:1rem;background:#f8fafc;border-radius:12px;border:1.5px solid #e2e8f0">
            <div style="font-size:.8rem;font-weight:700;color:#0f172a;margin-bottom:.75rem">📅 Configurar retiro en mes siguiente</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Mes de Retiro (Planilla) *</label>
                    <select class="form-control" id="b-mes-retiro">
                        @foreach(range(1,12) as $m)
                        <option value="{{ $m }}" {{ now()->month === $m ? 'selected' : '' }}>
                            {{ ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'][$m] }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Año de Retiro *</label>
                    <input type="number" class="form-control" id="b-anio-retiro" value="{{ now()->year }}" min="2020" max="2099">
                </div>
                <div class="form-group">
                    <label>N° Plano RS Origen *</label>
                    <select class="form-control" id="b-n-plano">
                        <option value="">Cargando...</option>
                    </select>
                </div>
            </div>

            <div class="alert alert-info" style="margin-bottom:.5rem">
                ℹ️ La fecha de retiro se calculará automáticamente:
                <ul style="margin-top:.4rem;margin-left:1rem;font-size:.78rem">
                    <li>Si el ingreso nuevo es el mismo mes del retiro → fecha_ret = fecha_ingreso</li>
                    <li>Si no → fecha_ret = 1 del mes de retiro (ej: junio → 1-mayo)</li>
                </ul>
            </div>

            <div style="display:flex;gap:.6rem;margin-top:.85rem;flex-wrap:wrap">
                <button class="btn btn-primary" onclick="ejecutarRetiroB()">
                    📅 Crear Plano de Retiro Futuro
                </button>
            </div>
        </div>
    </div>
</div>

{{-- ─── PASO FINAL: Descargas ────────────────────────────────────────────────── --}}
<div id="paso-final" style="display:none">
    <div class="card">
        <div class="alert alert-ok" style="margin-bottom:1rem">
            🎉 <strong id="final-msg">Traslado y retiro completados correctamente.</strong>
        </div>
        <div id="panel-descargas" style="display:none">
            <div class="card-title"><span>📥</span> Descargar Archivos de Planilla</div>
            <p style="font-size:.8rem;color:#64748b;margin-bottom:1rem">
                Descarga las novedades en formato TXT o Excel para el operador seleccionado.
            </p>

            <div class="form-row" style="margin-bottom:1.2rem; max-width:400px">
                <div class="form-group">
                    <label>Operador de Planilla *</label>
                    <select class="form-control" id="final-operador-id" onchange="actualizarOperadorDescarga(this.value)">
                        <option value="">— Seleccionar operador —</option>
                        @foreach($operadores as $op)
                        <option value="{{ $op->id }}">{{ $op->nombre }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Opción A: quienes siguen vigentes en el origen (sin planilla que corregir) --}}
            <div id="lista-omitidos" style="display:none;margin-bottom:1rem"></div>

            {{-- Opción A: una corrección N por planilla corregida --}}
            <div id="lista-correcciones" style="display:none;margin-bottom:1rem"></div>

            <div id="botones-descarga-periodo" style="display:flex;gap:.75rem;flex-wrap:wrap">
                <button class="btn btn-primary" onclick="descargarTxt()">
                    📄 Descargar TXT MiPlanilla
                </button>
                <button class="btn btn-success" onclick="descargarCsv()">
                    📊 Descargar CSV MiPlanilla
                </button>
            </div>
        </div>
        <div style="margin-top:1.5rem;display:flex;gap:.6rem;flex-wrap:wrap;border-top:1px solid #e2e8f0;padding-top:1rem">
            <a href="{{ route('admin.traslados.index') }}" class="btn btn-ghost">🔄 Nuevo Traslado</a>
            <a href="{{ route('admin.configuracion.razones.index') }}" class="btn btn-ghost">← Razones Sociales</a>
        </div>
    </div>
</div>

{{-- ─── SPINNER OVERLAY ─────────────────────────────────────────────────────── --}}
<div id="spinner-overlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;flex-direction:column;gap:.75rem">
    <div style="width:44px;height:44px;border:4px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite"></div>
    <div style="color:#fff;font-size:.9rem;font-weight:600" id="spinner-msg">Procesando...</div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const fechaIngresoNuevo = '{{ now()->startOfMonth()->toDateString() }}';

// Estado global del flujo
let contratosCargados = [];
let contratosSeleccionados = [];
let rsOrigenId   = null;
let rsOrigenNome = '';
let rsOrigenNPlano = 1;
let opcionRetiroActual = null;
let descargaTxtParams = null;

// ── STEPPER ────────────────────────────────────────────────────────────────
function setStep(n) {
    [1,2,3,4].forEach(i => {
        const el = document.getElementById(`step-ind-${i}`);
        if (!el) return;
        el.classList.remove('active','done');
        if (i < n) el.classList.add('done');
        if (i === n) el.classList.add('active');
    });
}

// ── PASO 1: VALIDAR CÉDULAS ────────────────────────────────────────────────
async function validarCedulas() {
    rsOrigenId = document.getElementById('rs-origen-sel').value;
    const cedulas = document.getElementById('cedulas-input').value.trim();

    if (!rsOrigenId) { alert('Selecciona la Razón Social de origen.'); return; }
    if (!cedulas)    { alert('Ingresa las cédulas a trasladar.'); return; }

    const sel = document.getElementById('rs-origen-sel');
    const opt = sel.options[sel.selectedIndex];
    rsOrigenNome  = opt.dataset.nombre;
    rsOrigenNPlano = parseInt(opt.dataset.nplano || '1');

    mostrarSpinner('Validando cédulas...');
    try {
        const resp = await apiFetch('{{ route("admin.traslados.validar") }}', 'POST', {
            razon_social_origen_id: rsOrigenId,
            cedulas: cedulas,
        });

        if (!resp.ok) { alert('Error: ' + resp.mensaje); return; }


        contratosCargados = resp.contratos;

        // Cédulas no encontradas
        const noEnc = document.getElementById('alerta-no-encontradas');
        if (resp.no_encontradas && resp.no_encontradas.length > 0) {
            document.getElementById('lista-no-encontradas').textContent = resp.no_encontradas.join(', ');
            noEnc.style.display = 'block';
        } else {
            noEnc.style.display = 'none';
        }

        renderTablaContratos(resp.contratos);
        document.getElementById('paso2').style.display = 'block';

        // Cargar n_planos disponibles de la RS origen
        cargarNPlanos(rsOrigenId, 'b-n-plano');

        setStep(2);
        document.getElementById('paso2').scrollIntoView({behavior:'smooth', block:'start'});
    } catch(err) {
        alert('Error al validar: ' + (err.message || 'Error desconocido. Revisa la consola (F12).'));
        console.error('[validarCedulas]', err);
    } finally {
        ocultarSpinner();
    }
}

function renderTablaContratos(contratos) {
    const tbody = document.getElementById('tbody-contratos');
    if (!contratos || contratos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" style="text-align:center;color:#94a3b8;padding:1.5rem">No se encontraron contratos vigentes para las cédulas ingresadas.</td></tr>';
        return;
    }
    tbody.innerHTML = contratos.map(c => `
        <tr>
            <td class="check-col"><input type="checkbox" class="chk-contrato" value="${c.contrato_id}" data-cedula="${c.cedula}" data-aliado="${c.aliado_nombre || ''}" checked onchange="actualizarContador()"></td>
            <td style="font-family:monospace;font-size:.75rem">${c.cedula}</td>
            <td style="font-weight:600;color:#1e293b">${nombreOracion(c.nombre_completo) || '—'}</td>
            <td style="font-size:.73rem;color:#475569">${c.rs_nombre || '—'}
                ${c.aliado_nombre ? `<div style="font-size:.65rem;color:#7c3aed;font-weight:700">${c.aliado_nombre}</div>` : ''}</td>
            <td><span class="badge badge-blue">${c.plan_nombre || '—'}</span></td>
            <td style="font-size:.72rem">${c.modalidad_nombre || '—'}</td>
            <td style="font-size:.72rem">${c.eps_nombre || '—'}</td>
            <td style="font-size:.72rem">${c.pension_nombre || '—'}</td>
            <td style="font-size:.72rem">${c.arl_nombre || '—'}</td>
            <td style="font-size:.72rem">${c.caja_nombre || '—'}</td>
            <td style="font-family:monospace;font-size:.73rem">${c.salario ? Number(c.salario).toLocaleString('es-CO') : '—'}</td>
            <td style="font-size:.72rem">${c.fecha_ingreso ? c.fecha_ingreso.substring(0,10) : '—'}</td>
        </tr>
    `).join('');
    actualizarContador();
}

function actualizarContador() {
    const checks = document.querySelectorAll('.chk-contrato:checked');
    document.getElementById('contador-sel').textContent = `${checks.length} seleccionado(s)`;
    contratosSeleccionados = Array.from(checks).map(c => ({
        contrato_id: parseInt(c.value),
        cedula: c.dataset.cedula,
        aliado: c.dataset.aliado,
    }));
}

function toggleTodos() {
    const checks = document.querySelectorAll('.chk-contrato');
    const allChecked = Array.from(checks).every(c => c.checked);
    checks.forEach(c => c.checked = !allChecked);
    document.getElementById('chk-todos').checked = !allChecked;
    actualizarContador();
}

function toggleTodosCheck(el) {
    document.querySelectorAll('.chk-contrato').forEach(c => c.checked = el.checked);
    actualizarContador();
}

function volverPaso1() {
    document.getElementById('paso2').style.display = 'none';
    setStep(1);
}

// ── PASO 3: MOSTRAR CONFIRMACIÓN ───────────────────────────────────────────
function mostrarConfirmacion() {
    actualizarContador();
    if (contratosSeleccionados.length === 0) { alert('Selecciona al menos un contrato.'); return; }

    const rsDestinoSel = document.getElementById('rs-destino-sel');
    const encargadoSel = document.getElementById('encargado-sel');

    if (!rsDestinoSel.value) { alert('Selecciona la Razón Social de destino.'); return; }
    if (!encargadoSel.value) { alert('Selecciona el encargado de la afiliación.'); return; }

    const rsDestNome = rsDestinoSel.options[rsDestinoSel.selectedIndex].text;
    const encNome    = encargadoSel.options[encargadoSel.selectedIndex].text;

    document.getElementById('confirm-total').textContent = contratosSeleccionados.length;
    document.getElementById('confirm-resumen').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;font-size:.82rem;margin-bottom:.75rem">
            <div><strong style="color:#64748b;font-size:.68rem;text-transform:uppercase">RS Origen:</strong><br>${rsOrigenNome}</div>
            <div><strong style="color:#64748b;font-size:.68rem;text-transform:uppercase">RS Destino:</strong><br>${rsDestNome}</div>
            <div><strong style="color:#64748b;font-size:.68rem;text-transform:uppercase">Encargado:</strong><br>${encNome}</div>
            <div><strong style="color:#64748b;font-size:.68rem;text-transform:uppercase">Fecha ingreso:</strong><br>1° del mes actual</div>
        </div>
        ${(() => {
            const porAliado = contratosSeleccionados.reduce((a, c) => { a[c.aliado || '—'] = (a[c.aliado || '—'] || 0) + 1; return a; }, {});
            const aliados = Object.keys(porAliado);
            return aliados.length > 1 ? `<div class="alert alert-info" style="font-size:.76rem;margin-bottom:.6rem">
                Son de <strong>${aliados.length} aliados</strong>: ${aliados.map(a => `${a} (${porAliado[a]})`).join(', ')}.
                Cada contrato nuevo queda en el aliado de la persona, en su ${rsDestNome}; si ese aliado no la tiene, se crea copiando esta (plano 1).
            </div>` : '';
        })()}
        <div style="font-size:.78rem;color:#64748b;margin-bottom:.5rem"><strong>Personas a trasladar (${contratosSeleccionados.length}):</strong></div>
        <div style="max-height:160px;overflow-y:auto;background:#f8fafc;border-radius:8px;padding:.6rem">
            ${contratosSeleccionados.map(c => `<div style="font-size:.78rem;padding:.2rem 0;border-bottom:1px solid #f1f5f9">${c.cedula}${c.aliado ? ` <span style="color:#7c3aed;font-size:.68rem">· ${c.aliado}</span>` : ''}</div>`).join('')}
        </div>
    `;

    document.getElementById('modalConfirmar').style.display = 'flex';
    setStep(3);
}

// ── EJECUTAR TRASLADO ──────────────────────────────────────────────────────
async function ejecutarTraslado() {
    const rsDestinoId = document.getElementById('rs-destino-sel').value;
    const encargadoId = document.getElementById('encargado-sel').value;
    const contratoIds = contratosSeleccionados.map(c => c.contrato_id);

    cerrarModal('modalConfirmar');
    mostrarSpinner('Creando contratos y planos de afiliación...');

    try {
        const resp = await apiFetch('{{ route("admin.traslados.ejecutar") }}', 'POST', {
            contrato_ids:            contratoIds,
            razon_social_destino_id: rsDestinoId,
            encargado_id:            encargadoId,
        });

        if (!resp.ok && resp.nuevos_contratos?.length === 0) {
            alert('Error al ejecutar el traslado: ' + resp.mensaje);
            return;
        }

        // Guardar IDs de contratos origen para el retiro (los que se procesaron exitosamente)
        const contratoIdsOrigen = resp.nuevos_contratos.map(n => n.contrato_id_origen);

        // Almacenar para uso en retiro
        window._trasladoData = {
            contratoIdsOrigen: contratoIdsOrigen,
            rsOrigenId: rsOrigenId,
            rsOrigenNPlano: rsOrigenNPlano,
            cantNuevos: resp.nuevos_contratos.length,
        };

        document.getElementById('p4-msg-exito').textContent =
            `✅ ${resp.nuevos_contratos.length} contrato(s) creado(s) en la nueva RS. Ahora gestiona el retiro.`;

        if (resp.errores?.length > 0) {
            const listErr = resp.errores.map(e => `• ${e.cedula}: ${e.mensaje}`).join('\n');
            alert(`Algunos contratos tuvieron errores:\n${listErr}`);
        }

        document.getElementById('paso2').style.display = 'none';
        document.getElementById('paso4').style.display = 'block';
        setStep(4);
        document.getElementById('paso4').scrollIntoView({behavior:'smooth', block:'start'});

    } finally {
        ocultarSpinner();
    }
}

// ── OPCIÓN RETIRO ──────────────────────────────────────────────────────────
function seleccionarOpcion(op) {
    opcionRetiroActual = op;
    document.getElementById('card-opcion-a').classList.toggle('selected', op === 'A');
    document.getElementById('card-opcion-b').classList.toggle('selected', op === 'B');
    document.getElementById('form-opcion-a').style.display = op === 'A' ? 'block' : 'none';
    document.getElementById('form-opcion-b').style.display = op === 'B' ? 'block' : 'none';

    if (op === 'A') {
        previsualizarRetiroA();
    }
}

async function cargarNPlanos(rsId, selectId) {
    const sel = document.getElementById(selectId);
    sel.innerHTML = '<option value="">Cargando...</option>';
    try {
        const url = `/admin/traslados-rs/api/n-planos/${rsId}`;
        const resp = await fetch(url, { headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'} });
        const data = await resp.json();
        if (data.ok) {
            const nPlanos = data.n_planos.length > 0 ? data.n_planos : [data.n_plano_actual];
            sel.innerHTML = nPlanos.map(n => `<option value="${n}" ${n == data.n_plano_actual ? 'selected' : ''}>P${n} ${n == data.n_plano_actual ? '(actual)' : ''}</option>`).join('');
        } else {
            sel.innerHTML = `<option value="${data.n_plano_actual || 1}">P${data.n_plano_actual || 1} (actual)</option>`;
        }
    } catch(e) {
        sel.innerHTML = '<option value="1">P1</option>';
    }
}

// ── RETIRO A: previsualizar y aplicar ─────────────────────────────────────
const ESTADOS_RETIRO_A = {
    ok:           { txt: 'Se corrige',         badge: 'badge-green' },
    pendiente:    { txt: 'Plano sin pagar',    badge: 'badge-amber' },
    ya_retirado:  { txt: 'Ya tiene retiro',    badge: 'badge-amber' },
    sin_planilla: { txt: 'Sin planilla',       badge: 'badge-red' },
    no_vigente:   { txt: 'Contrato no vigente', badge: 'badge-red' },
};

function fechaCorta(iso) {
    if (!iso) return '—';
    const [y, m, d] = iso.substring(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

async function previsualizarRetiroA() {
    const tbody = document.getElementById('tbody-retiro-a');
    const btn   = document.getElementById('btn-retiro-a');
    btn.disabled = true;
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:1rem">Buscando la última planilla de cada persona...</td></tr>';

    const resp = await apiFetch('{{ route("admin.traslados.retiro_a.previsualizar") }}', 'POST', {
        contrato_ids: window._trasladoData.contratoIdsOrigen,
    });
    if (!resp.ok) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:#dc2626;padding:1rem">${resp.mensaje}</td></tr>`;
        return;
    }

    tbody.innerHTML = resp.filas.map(f => {
        const e = ESTADOS_RETIRO_A[f.estado] || { txt: f.estado, badge: 'badge-red' };
        const fondo = f.estado === 'ok' ? '' : 'background:#fffbeb';
        return `
        <tr style="${fondo}">
            <td style="font-family:monospace;font-size:.75rem">${f.cedula}</td>
            <td style="font-weight:600;color:#1e293b">${nombreOracion(f.nombre) || '—'}</td>
            <td style="font-size:.72rem">${f.razon_social || '—'}${f.aliado ? `<div style="font-size:.65rem;color:#7c3aed;font-weight:700">${f.aliado}</div>` : ''}</td>
            <td style="font-size:.75rem">${f.periodo || '—'}</td>
            <td style="font-size:.75rem">${f.n_plano ? 'P' + f.n_plano : '—'}</td>
            <td style="font-size:.75rem">${f.num_dias ?? '—'}</td>
            <td style="font-family:monospace;font-size:.75rem">${f.planilla || '—'}</td>
            <td style="font-size:.75rem">${fechaCorta(f.fecha_pago)}</td>
            <td style="font-size:.75rem;font-weight:600">${f.estado === 'ok' ? fechaCorta(f.fecha_ret) : '—'}</td>
            <td><span class="badge ${e.badge}" title="${f.mensaje || ''}">${e.txt}</span>
                ${f.mensaje ? `<div style="font-size:.65rem;color:#92400e;margin-top:.2rem;max-width:260px">${f.mensaje}</div>` : ''}</td>
        </tr>`;
    }).join('');

    const aplicables = resp.filas.filter(f => f.estado === 'ok').length;
    btn.disabled = aplicables === 0;
    btn.textContent = aplicables
        ? `📤 Aplicar retiro a ${aplicables} persona(s)`
        : 'Nadie tiene una planilla pagada que corregir';
}

async function ejecutarRetiroA() {
    const data = window._trasladoData;
    if (!confirm('Se agrega el retiro a la última planilla de cada persona marcada "Se corrige" y su contrato anterior queda retirado. ¿Continuar?')) return;

    mostrarSpinner('Aplicando retiros como corrección...');
    try {
        const resp = await apiFetch('{{ route("admin.traslados.retiro_a") }}', 'POST', {
            contrato_ids: data.contratoIdsOrigen,
        });

        if (!resp.ok && !(resp.procesados?.length)) {
            const detalle = [...(resp.omitidos || []), ...(resp.errores || [])].map(e => `• ${e.cedula}: ${e.mensaje}`).join('\n');
            alert('No se aplicó ningún retiro. ' + (resp.mensaje || '') + (detalle ? '\n' + detalle : ''));
            return;
        }

        // Quienes no tenían planilla que corregir siguen vigentes en el origen
        // (ya tienen su contrato nuevo): el usuario los retira o los anula.
        const avisos = [...(resp.omitidos || []), ...(resp.errores || [])];
        const contOmitidos = document.getElementById('lista-omitidos');
        if (avisos.length) {
            contOmitidos.style.display = 'block';
            contOmitidos.innerHTML = `<div class="alert alert-warn" style="font-size:.78rem">
                ⚠️ <strong>${avisos.length} persona(s) siguen vigentes en ${rsOrigenNome}</strong> (ya tienen su contrato nuevo).
                No tenían una planilla pagada que corregir: retira o anula su contrato de origen desde Contratos.
                <ul style="margin:.4rem 0 0 1rem;padding:0">${avisos.map(e => `<li><strong>${e.cedula}</strong> — ${e.mensaje}</li>`).join('')}</ul>
            </div>`;
        } else {
            contOmitidos.style.display = 'none';
        }

        const opIdA = document.getElementById('a-operador-id').value;
        if (opIdA) document.getElementById('final-operador-id').value = opIdA;

        renderCorrecciones(resp.correcciones || []);

        document.getElementById('final-msg').textContent =
            `🎉 ${resp.procesados.length} retiro(s) agregado(s) como corrección de su última planilla.`;
        document.getElementById('panel-descargas').style.display = 'block';
        document.getElementById('paso4').style.display = 'none';
        document.getElementById('paso-final').style.display = 'block';
        document.getElementById('paso-final').scrollIntoView({behavior:'smooth', block:'start'});
    } finally {
        ocultarSpinner();
    }
}

function renderCorrecciones(correcciones) {
    const cont = document.getElementById('lista-correcciones');
    document.getElementById('botones-descarga-periodo').style.display = 'none';
    cont.style.display = 'block';
    cont.innerHTML = `
        <div class="alert alert-info" style="margin-bottom:.75rem;font-size:.8rem">
            📌 <strong>Los retiros quedaron en Planos SS como planilla N</strong>:
            ${correcciones.map(c => `<strong>${c.aliado || ''} · ${c.razon_social || rsOrigenNome} · P${c.n_plano}</strong> (corrige la ${c.planilla})`).join('; ')}.
            Las de otro aliado se liquidan entrando a ese aliado.
            No hay que hacer nada más aquí: se liquidan cuando quieran desde Planos SS, en el
            mismo operador donde se pagó la planilla original, se envían en $0 y se confirman en $0.
        </div>
        ${correcciones.map((c, i) => `
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;padding:.55rem .7rem;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:.4rem">
                <div style="font-size:.8rem;flex:1;min-width:220px">
                    ${c.aliado ? `<span style="color:#7c3aed;font-weight:700">${c.aliado}</span> · ` : ''}<strong>Plano P${c.n_plano}</strong> · planilla N que corrige la
                    <strong style="font-family:monospace">${c.planilla}</strong>
                    · período ${c.periodo}
                    · pagada ${fechaCorta(c.fecha_pago)}
                    · <strong>${c.cantidad}</strong> retiro(s)
                    ${c.fecha_pago ? '' : '<div style="color:#dc2626;font-size:.7rem">Sin fecha de pago registrada: la N la exige. Registra el gasto de esa planilla.</div>'}
                </div>
                ${c.mismo_aliado
                    ? `<a class="btn btn-ghost" href="${c.url_planos}" target="_blank" rel="noopener">Abrir en Planos SS →</a>`
                    : `<span style="font-size:.72rem;color:#7c3aed;font-weight:700">Entrar a ${c.aliado} para liquidarla</span>`}
                <button class="btn btn-primary" onclick="descargarCorreccion(${i})">📄 TXT corrección N</button>
            </div>`).join('')}
    `;
    window._correcciones = correcciones;
}

function descargarCorreccion(i) {
    const c = window._correcciones[i];
    const opId = document.getElementById('final-operador-id').value;
    const url = new URL('{{ route("admin.traslados.descargar_plano") }}', window.location.origin);
    c.plano_ids.forEach(id => url.searchParams.append('plano_ids[]', id));
    if (opId) url.searchParams.set('operador_id', opId);
    window.open(url.toString(), '_blank');
}

// ── EJECUTAR RETIRO B ──────────────────────────────────────────────────────
async function ejecutarRetiroB() {
    const mesRetiro  = document.getElementById('b-mes-retiro').value;
    const anioRetiro = document.getElementById('b-anio-retiro').value;
    const nPlano     = document.getElementById('b-n-plano').value;

    if (!nPlano) { alert('Selecciona el N° de plano.'); return; }

    const data = window._trasladoData;
    mostrarSpinner('Creando planos de retiro...');

    try {
        const resp = await apiFetch('{{ route("admin.traslados.retiro_b") }}', 'POST', {
            contrato_ids:        data.contratoIdsOrigen,
            mes_retiro:          parseInt(mesRetiro),
            anio_retiro:         parseInt(anioRetiro),
            n_plano:             parseInt(nPlano),
            fecha_ingreso_nuevo: fechaIngresoNuevo,
        });

        if (!resp.ok && resp.procesados?.length === 0) {
            alert('Error en el retiro: ' + resp.mensaje);
            return;
        }

        // Guardar params para descargas
        descargaTxtParams = {
            razon_social_id: data.rsOrigenId,
            mes:  mesRetiro,
            anio: anioRetiro,
            n_plano: nPlano,
        };

        document.getElementById('final-msg').textContent =
            `🎉 ${resp.procesados.length} plano(s) de retiro creado(s) para el mes ${mesRetiro}/${anioRetiro}. ¡Traslado completo!`;
        document.getElementById('panel-descargas').style.display = 'block';
        document.getElementById('paso4').style.display = 'none';
        document.getElementById('paso-final').style.display = 'block';
        document.getElementById('paso-final').scrollIntoView({behavior:'smooth', block:'start'});

    } finally {
        ocultarSpinner();
    }
}

// ── DESCARGAS ──────────────────────────────────────────────────────────────
function descargarTxt() {
    if (!descargaTxtParams) { alert('No hay parámetros de descarga.'); return; }
    const opId = document.getElementById('final-operador-id').value;
    const url = new URL('{{ route("admin.traslados.descargar_plano") }}', window.location.origin);
    url.searchParams.set('razon_social_id', descargaTxtParams.razon_social_id);
    url.searchParams.set('mes',   descargaTxtParams.mes);
    url.searchParams.set('anio',  descargaTxtParams.anio);
    url.searchParams.set('n_plano', descargaTxtParams.n_plano);
    if (opId) {
        url.searchParams.set('operador_id', opId);
    }
    window.open(url.toString(), '_blank');
}

function descargarExcel() {
    if (!descargaTxtParams) { alert('No hay parámetros de descarga.'); return; }
    const opId = document.getElementById('final-operador-id').value;
    const url = new URL('{{ route("admin.traslados.descargar_excel") }}', window.location.origin);
    url.searchParams.set('razon_social_id', descargaTxtParams.razon_social_id);
    url.searchParams.set('mes',   descargaTxtParams.mes);
    url.searchParams.set('anio',  descargaTxtParams.anio);
    url.searchParams.set('n_plano', descargaTxtParams.n_plano);
    if (opId) {
        url.searchParams.set('operador_id', opId);
    }
    window.open(url.toString(), '_blank');
}

function descargarCsv() {
    if (!descargaTxtParams) { alert('No hay parámetros de descarga.'); return; }
    const opId = document.getElementById('final-operador-id').value;
    const url = new URL('{{ route("admin.traslados.descargar_excel") }}', window.location.origin);
    url.searchParams.set('razon_social_id', descargaTxtParams.razon_social_id);
    url.searchParams.set('mes',   descargaTxtParams.mes);
    url.searchParams.set('anio',  descargaTxtParams.anio);
    url.searchParams.set('n_plano', descargaTxtParams.n_plano);
    url.searchParams.set('formato', 'csv');
    if (opId) {
        url.searchParams.set('operador_id', opId);
    }
    window.open(url.toString(), '_blank');
}

function actualizarOperadorDescarga(val) {
    const selA = document.getElementById('a-operador-id');
    if (selA) selA.value = val;
}

// ── HELPERS ────────────────────────────────────────────────────────────────
async function apiFetch(url, method, body) {
    const resp = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    let data;
    try {
        data = await resp.json();
    } catch(e) {
        // La respuesta no es JSON (ej: error 500 HTML de Laravel)
        throw new Error(`Error del servidor (HTTP ${resp.status}). Revisa la consola del navegador.`);
    }

    // Normalizar errores de Laravel (422 validation, 500, etc.)
    if (!resp.ok) {
        const msg = data?.mensaje ?? data?.message ?? `Error HTTP ${resp.status}`;
        data = { ok: false, mensaje: msg, _httpStatus: resp.status, _raw: data };
    }

    return data;
}


function mostrarSpinner(msg) {
    document.getElementById('spinner-msg').textContent = msg || 'Procesando...';
    document.getElementById('spinner-overlay').style.display = 'flex';
}
function ocultarSpinner() {
    document.getElementById('spinner-overlay').style.display = 'none';
}
function cerrarModal(id) {
    document.getElementById(id).style.display = 'none';
}
document.getElementById('modalConfirmar').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal('modalConfirmar');
});
</script>

@endsection
