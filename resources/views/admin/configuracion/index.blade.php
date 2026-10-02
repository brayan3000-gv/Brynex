@extends('layouts.app')
@section('modulo', 'Configuración')

@section('contenido')
<style>
/* Selector de plan del tarifario: tarjeta con el nombre y su avance de llenado. */
.btn-plan {
    display:flex; flex-direction:column; align-items:flex-start; gap:0.15rem;
    padding:0.45rem 0.75rem; border-radius:9px; cursor:pointer; text-align:left;
    border:1.5px solid; transition:all .12s ease; min-width:130px; line-height:1.25;
}
.btn-plan-nombre { font-size:0.76rem; font-weight:700; white-space:nowrap; }
.btn-plan-meta   { font-size:0.62rem; opacity:.9; white-space:nowrap; }

.btn-plan.plan-off            { background:#fff; color:#475569; border-color:#e2e8f0; }
.btn-plan.plan-off:hover      { border-color:#93c5fd; background:#f8fafc; transform:translateY(-1px); }
.btn-plan.plan-on             { background:linear-gradient(135deg,#0369a1,#075985); color:#fff;
                                border-color:#075985; box-shadow:0 3px 10px rgba(3,105,161,.28); }
.btn-plan.plan-on .btn-plan-meta span { color:#bae6fd !important; }
</style>
<div style="max-width:1100px;margin:0 auto;">

{{-- Encabezado --}}
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
  <div>
    <a href="{{ route('admin.configuracion.hub') }}"
       style="font-size:.73rem;color:#64748b;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;margin-bottom:.35rem">
        ← Volver a Configuración
    </a>
    <h1 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0;">⚙️ Configuración del Aliado</h1>
    <p style="font-size:0.78rem;color:#64748b;margin:0;">Parámetros de tarifas, administración y ARL</p>
  </div>
</div>

@if(session('success'))
<div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;color:#166534;padding:0.65rem 1rem;margin-bottom:1rem;font-size:0.83rem;">✅ {{ session('success') }}</div>
@endif
@if($errors->any())
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;padding:0.65rem 1rem;margin-bottom:1rem;font-size:0.83rem;">
  <strong>Errores:</strong> @foreach($errors->all() as $e) · {{ $e }} @endforeach
</div>
@endif

<form method="POST" action="{{ route('admin.configuracion.store') }}" enctype="multipart/form-data">
@csrf

{{-- Los parámetros globales (salario mínimo, % de seguridad social, tarifas ARL) se fueron
     a BryNex → Parámetros BryNex: no son del aliado, son del sistema, y verlos aquí hacía
     creer que se editaban solo para este aliado. Ver BrynexController::parametros(). --}}
@if(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
<div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:0.7rem 1rem;margin-bottom:1rem;
            display:flex;align-items:center;justify-content:space-between;gap:1rem;">
  <div style="font-size:0.74rem;color:#475569;line-height:1.5;">
    🔒 El salario mínimo, los porcentajes de seguridad social y las tarifas ARL ahora se editan
    en <strong>BryNex → Parámetros BryNex</strong>, porque son iguales para todos los aliados.
  </div>
  <a href="{{ route('brynex.parametros') }}"
     style="flex-shrink:0;padding:0.4rem 0.9rem;border:1px solid #93c5fd;border-radius:8px;background:#eff6ff;
            color:#0369a1;text-decoration:none;font-size:0.76rem;font-weight:700;white-space:nowrap;">
    Ir a Parámetros BryNex →
  </a>
</div>
@endif

{{-- ══ SECCIÓN 2.5: Configuración de Mora al Cliente ══ --}}
<div style="background:#fffbeb;border-radius:12px;border:2px solid #fde68a;padding:1rem 1.25rem;margin-bottom:1rem;">
  <div style="font-size:0.72rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.35rem;">
    ⚠️ Configuración de Mora al Cliente
  </div>
  <div style="font-size:0.72rem;color:#78350f;margin-bottom:0.85rem;line-height:1.5;">
    Define cuándo y cuánto se cobra de mora a los clientes por pago tardío de su factura SS.
    La <strong>tasa de cálculo</strong> (Art. 635 ET) la configura BryNex globalmente.
    Aquí configuras el <strong>día de inicio</strong> y los <strong>montos mínimos</strong> de tu operación.
  </div>
  @php
    $globalMoraCfg = $configs['global'] ?? null;
    $globalCfg = $configs['global'] ?? null;
  @endphp
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">
    {{-- Campo 1: Día hábil de inicio --}}
    <div style="background:#fff;border-radius:9px;padding:0.85rem;border:1.5px solid #fde68a;">
      <div style="font-size:0.62rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.55rem;">🗓 Día Hábil de Inicio</div>
      <div style="font-size:0.72rem;color:#78350f;margin-bottom:0.5rem;line-height:1.4;">
        A partir de qué día hábil del mes se cobra mora a <strong>TODOS</strong> los clientes.<br>
        <em>Si lo dejas vacío, cada cliente usa el día según los 2 últimos dígitos de su RS (Decreto 1990/2016).</em>
      </div>
      <div style="display:flex;align-items:center;gap:0.35rem;">
        <input type="number" step="1" min="2" max="16"
            name="configs[global][mora_dia_habil_inicio]"
            value="{{ $globalMoraCfg?->mora_dia_habil_inicio ?? '' }}"
            placeholder="Ej: 5 (día hábil 5)"
            style="flex:1;padding:0.45rem 0.6rem;border:2px solid #fde68a;border-radius:7px;font-size:0.9rem;font-family:monospace;font-weight:700;color:#92400e;background:#fffbeb;">
        <span style="color:#92400e;font-size:0.75rem;white-space:nowrap;">d. hábil</span>
      </div>
    </div>
    {{-- Campo 2: Mora mínima --}}
    <div style="background:#fff;border-radius:9px;padding:0.85rem;border:1.5px solid #fde68a;">
      <div style="font-size:0.62rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.55rem;">💰 Mora Mínima (Tramo 1)</div>
      <div style="font-size:0.72rem;color:#78350f;margin-bottom:0.5rem;line-height:1.4;">
        Si la mora real calculada es <strong>menor</strong> a este valor, se cobra este monto fijo.
        <br><em>Ejemplo: mora real = $1.200 → se cobra $2.000</em>
      </div>
      <div style="display:flex;align-items:center;gap:0.25rem;">
        <span style="color:#92400e;font-size:0.82rem;">$</span>
        <input type="number" step="500" min="0"
            name="configs[global][mora_minimo]"
            value="{{ $globalMoraCfg?->mora_minimo ?? 2000 }}"
            style="flex:1;padding:0.45rem 0.6rem;border:2px solid #fde68a;border-radius:7px;font-size:0.9rem;font-family:monospace;font-weight:700;color:#92400e;background:#fffbeb;text-align:right;">
      </div>
    </div>
    {{-- Campo 3: Mora segundo tramo --}}
    <div style="background:#fff;border-radius:9px;padding:0.85rem;border:1.5px solid #fde68a;">
      <div style="font-size:0.62rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.55rem;">💰 Mora Segundo Tramo</div>
      <div style="font-size:0.72rem;color:#78350f;margin-bottom:0.5rem;line-height:1.4;">
        Si mora real &ge; mínima pero es <strong>menor</strong> a este valor, se cobra este monto fijo.
        <br><em>Si mora real &ge; este valor, se cobra la mora real.</em>
      </div>
      <div style="display:flex;align-items:center;gap:0.25rem;">
        <span style="color:#92400e;font-size:0.82rem;">$</span>
        <input type="number" step="500" min="0"
            name="configs[global][mora_segundo]"
            value="{{ $globalMoraCfg?->mora_segundo ?? 5000 }}"
            style="flex:1;padding:0.45rem 0.6rem;border:2px solid #fde68a;border-radius:7px;font-size:0.9rem;font-family:monospace;font-weight:700;color:#92400e;background:#fffbeb;text-align:right;">
      </div>
    </div>
  </div>
  <div style="font-size:0.7rem;color:#92400e;margin-top:0.65rem;background:#fef3c7;border-radius:6px;padding:0.4rem 0.75rem;">
    💡 <strong>Lógica de tramos:</strong>
    mora_real &lt; mínima (${{ number_format($globalMoraCfg?->mora_minimo ?? 2000, 0, ',', '.') }}) → cobrar mínima ·
    mora_real &lt; segundo (${{ number_format($globalMoraCfg?->mora_segundo ?? 5000, 0, ',', '.') }}) → cobrar segundo ·
    mora_real &ge; segundo → cobrar mora real
  </div>
</div>

{{-- ══ SECCIÓN 2.6: Parámetros Especiales ══ --}}
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.85rem;">
    <div style="font-size:0.72rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:0.06em;">
      ⚙️ Parámetros Especiales
      <span style="font-size:0.65rem;color:#94a3b8;text-transform:none;font-weight:400;margin-left:0.5rem;">Configuración por aliado</span>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">

    {{-- Campo: Día de ingreso Ingreso-Retiro --}}
    <div style="background:#f0f9ff;border-radius:9px;padding:0.85rem;border:1.5px solid #bae6fd;">
      <div style="font-size:0.62rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.55rem;">📅 Día de Ingreso (Plan Ingreso-Retiro)</div>
      <div style="font-size:0.72rem;color:#0c4a6e;margin-bottom:0.5rem;line-height:1.4;">
        Día del mes en que se afiliará el contrato al duplicar en el flujo <strong>Ingreso-Retiro</strong>.
        <br><em>Valor por defecto: 26. Rango: 1 al 28.</em>
      </div>
      <div style="display:flex;align-items:center;gap:0.35rem;">
        <input type="number" step="1" min="1" max="28"
            name="configs[global][dia_ingreso_ir]"
            value="{{ $globalMoraCfg?->dia_ingreso_ir ?? 26 }}"
            placeholder="Ej: 26"
            style="flex:1;padding:0.45rem 0.6rem;border:2px solid #bae6fd;border-radius:7px;font-size:0.9rem;font-family:monospace;font-weight:700;color:#0369a1;background:#fff;text-align:center;">
        <span style="color:#0369a1;font-size:0.75rem;white-space:nowrap;">día del mes</span>
      </div>
    </div>

    {{-- Card combinado: Seguro Único + Logo Aseguradora --}}
    <div x-data="{ preview: '{{ $globalCfg?->seguro_logo ? Storage::url($globalCfg->seguro_logo) : '' }}' }"
         style="background:#fdf4ff;border-radius:9px;padding:0.85rem;border:1.5px solid #e9d5ff;grid-column:span 2;">
      <div style="font-size:0.62rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.65rem;">
        🛡️ Seguro — Valor y Aseguradora
        <span style="font-size:0.63rem;color:#9f67f5;text-transform:none;font-weight:400;margin-left:0.4rem;">Aplica igual a todos los planes</span>
      </div>
      <div style="display:flex;gap:1rem;align-items:flex-start;">

        {{-- Valor del seguro --}}
        <div style="flex:0 0 auto;min-width:180px;">
          <label style="display:block;font-size:0.62rem;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;">Valor</label>
          <div style="display:flex;align-items:center;gap:0.35rem;">
            <span style="color:#7c3aed;font-size:0.95rem;font-weight:700;">$</span>
            <input type="text"
                name="configs[global][seguro_valor]"
                value="{{ $globalCfg ? number_format($globalCfg->seguro_valor, 0, ',', '.') : '0' }}"
                class="input-miles"
                style="flex:1;padding:0.45rem 0.6rem;border:2px solid #e9d5ff;border-radius:7px;font-size:0.95rem;font-family:monospace;font-weight:700;color:#7c3aed;background:#fff;text-align:right;"
                onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#e9d5ff'">
          </div>
          <div style="font-size:0.65rem;color:#9f67f5;margin-top:0.35rem;">Déjalo en 0 si no aplica seguro.</div>
        </div>

        {{-- Separador vertical --}}
        <div style="width:1px;background:#e9d5ff;align-self:stretch;"></div>

        {{-- Logo de la aseguradora --}}
        <div style="flex:1;">
          <label style="display:block;font-size:0.62rem;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;">🏢 Logo de la Aseguradora</label>
          <div style="display:flex;align-items:center;gap:1rem;">
            {{-- Preview --}}
            <div style="flex:0 0 auto;">
              <template x-if="preview">
                <img :src="preview" alt="Logo aseguradora"
                     style="height:52px;max-width:140px;object-fit:contain;border-radius:7px;border:1px solid #e9d5ff;background:#fff;padding:5px;">
              </template>
              <template x-if="!preview">
                <div style="height:52px;width:120px;display:flex;align-items:center;justify-content:center;background:#f5f0ff;border-radius:7px;border:1.5px dashed #c4b5fd;color:#a78bfa;font-size:0.7rem;text-align:center;padding:0.3rem;">
                  Sin logo de aseguradora
                </div>
              </template>
            </div>
            {{-- Input file --}}
            <div style="flex:1;">
              <input type="file" name="seguro_logo" accept="image/png,image/jpeg,image/jpg,image/svg+xml"
                     @change="preview = URL.createObjectURL($event.target.files[0])"
                     style="width:100%;font-size:0.72rem;color:#6d28d9;padding:0.3rem 0;cursor:pointer;">
              <div style="font-size:0.65rem;color:#9f67f5;margin-top:0.3rem;">PNG, JPG o SVG. Máx 2 MB. Aparece en documentos y reportes del seguro.</div>
            </div>
          </div>
        </div>

      </div>
    </div>

    {{-- Card: Recibo doble copia (cliente + empresa en la misma hoja) --}}
    <div x-data="{ doble: {{ $aliadoActual?->recibo_doble_copia ? 'true' : 'false' }} }"
         style="background:#f0fdf4;border-radius:9px;padding:0.85rem;border:1.5px solid #bbf7d0;grid-column:span 3;">
      <div style="font-size:0.62rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.55rem;">
        🖨️ Recibo — Doble copia por hoja
      </div>
      <div style="display:flex;align-items:flex-start;gap:1rem;">
        <label style="display:flex;align-items:center;gap:0.55rem;cursor:pointer;flex-shrink:0;">
          {{-- Hidden: un checkbox desmarcado no se envía --}}
          <input type="hidden" name="recibo_doble_copia" value="0">
          <input type="checkbox" name="recibo_doble_copia" value="1" x-model="doble"
                 style="width:18px;height:18px;accent-color:#15803d;cursor:pointer;">
          <span x-text="doble ? 'Activado' : 'Desactivado'"
                :style="doble ? 'color:#15803d;font-weight:700;font-size:0.82rem' : 'color:#94a3b8;font-weight:600;font-size:0.82rem'"></span>
        </label>
        <div style="font-size:0.72rem;color:#166534;line-height:1.45;">
          Al imprimir un recibo se generan <strong>dos copias en la misma hoja carta</strong>:
          la <strong>copia CLIENTE</strong> arriba y la <strong>copia EMPRESA</strong> abajo,
          para partir la hoja por la mitad.
          <br>
          La copia EMPRESA <em>siempre</em> sale detallada, con el desglose de administración
          (empresa y asesor), seguro, 4×1000, y los saldos (anterior, anticipo aplicado y próximo).
        </div>
      </div>
    </div>

    {{-- Card: Seguimiento de prospectos (cotizaciones) --}}
    <div x-data="{ aviso: {{ $globalMoraCfg?->prospectos_recordatorio ? 'true' : 'false' }} }"
         style="background:#fdf4ff;border-radius:9px;padding:0.85rem;border:1.5px solid #e9d5ff;grid-column:span 3;">
      <div style="font-size:0.62rem;font-weight:700;color:#7e22ce;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.55rem;">
        📞 Cotizaciones — Seguimiento automático de prospectos
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.85rem;align-items:start;">
        <div>
          <label style="display:flex;align-items:center;gap:0.55rem;cursor:pointer;margin-bottom:0.4rem;">
            <input type="hidden" name="prospectos[recordatorio]" value="0">
            <input type="checkbox" name="prospectos[recordatorio]" value="1" x-model="aviso"
                   style="width:18px;height:18px;accent-color:#7e22ce;cursor:pointer;">
            <span x-text="aviso ? 'Aviso diario activado' : 'Aviso diario desactivado'"
                  :style="aviso ? 'color:#7e22ce;font-weight:700;font-size:0.82rem' : 'color:#94a3b8;font-weight:600;font-size:0.82rem'"></span>
          </label>
          <div style="font-size:0.72rem;color:#6b21a8;line-height:1.45;">
            Cada mañana (7:30) llega por WhatsApp la lista de prospectos con <strong>llamada para hoy o vencida</strong>.
            Si el prospecto tiene asesor con celular, le llega al asesor; si no, a este número.
          </div>
          <input type="text" name="prospectos[recordatorio_celular]" inputmode="numeric" placeholder="Celular para los avisos (10 dígitos)"
                 value="{{ $globalMoraCfg?->prospectos_recordatorio_celular }}"
                 style="margin-top:0.45rem;width:100%;padding:0.45rem 0.6rem;border:2px solid #e9d5ff;border-radius:7px;font-size:0.85rem;font-family:monospace;color:#6b21a8;background:#fff;">
        </div>
        <div>
          <div style="font-size:0.72rem;color:#6b21a8;line-height:1.45;margin-bottom:0.4rem;">
            <strong>Pasar a «Sin respuesta»</strong> cuando un interesado o pendiente lleva estos días sin ninguna gestión. Vacío = no hacer nada.
          </div>
          <div style="display:flex;align-items:center;gap:0.35rem;">
            <input type="number" min="1" max="365" name="prospectos[dias_sin_respuesta]" placeholder="Ej: 5"
                   value="{{ $globalMoraCfg?->prospectos_dias_sin_respuesta }}"
                   style="width:90px;padding:0.45rem 0.6rem;border:2px solid #e9d5ff;border-radius:7px;font-size:0.9rem;font-family:monospace;font-weight:700;color:#7e22ce;background:#fff;text-align:center;">
            <span style="color:#7e22ce;font-size:0.75rem;">días sin gestión</span>
          </div>
        </div>
        <div>
          <div style="font-size:0.72rem;color:#6b21a8;line-height:1.45;margin-bottom:0.4rem;">
            <strong>Cerrar como «No interesado»</strong> cuando un prospecto abierto lleva estos días sin gestión ni respuesta. Queda una nota en su historial. Vacío = no cerrar.
          </div>
          <div style="display:flex;align-items:center;gap:0.35rem;">
            <input type="number" min="1" max="365" name="prospectos[dias_cierre]" placeholder="Ej: 30"
                   value="{{ $globalMoraCfg?->prospectos_dias_cierre }}"
                   style="width:90px;padding:0.45rem 0.6rem;border:2px solid #e9d5ff;border-radius:7px;font-size:0.9rem;font-family:monospace;font-weight:700;color:#7e22ce;background:#fff;text-align:center;">
            <span style="color:#7e22ce;font-size:0.75rem;">días sin gestión</span>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

{{-- ══ SECCIÓN 3: Valores generales (respaldo de todos los planes) ══ --}}
@php $globalCfg = $configs['global'] ?? null; @endphp
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;">
  <div style="font-size:0.72rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.35rem;">
    💰 Valores generales
  </div>
  <div style="font-size:0.72rem;color:#64748b;margin-bottom:0.85rem;line-height:1.5;">
    Se usan cuando la celda del tarifario no tiene valor propio. La
    <strong>administración</strong> de aquí es la que cotiza la página pública y la que se precarga en el contrato.
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1.3fr 1.1fr;gap:0.9rem;align-items:end;">

    {{-- Admon mensual: al cambiarla ofrece llevarla a todo el tarifario, porque es el
         respaldo de las 140 casillas y dejarla desalineada descuadra el cotizador. --}}
    <div style="background:#f8fafc;border-radius:9px;padding:0.7rem 0.8rem;border:1px solid #e2e8f0;">
      <div style="font-size:0.62rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.4rem;">Admon mensual</div>
      <div style="display:flex;align-items:center;gap:0.25rem;">
        <span style="color:#94a3b8;font-size:0.8rem;">$</span>
        <input type="text" name="configs[global][administracion]" id="inp_admon_general"
            data-admon-general="1" data-general="1"
            value="{{ $globalCfg ? number_format($globalCfg->administracion, 0, ',', '.') : '0' }}"
            class="input-miles"
            style="width:100%;padding:0.4rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.87rem;font-family:monospace;text-align:right;font-weight:700;color:#0f172a;">
      </div>
    </div>

    <div style="background:#f8fafc;border-radius:9px;padding:0.7rem 0.8rem;border:1px solid #e2e8f0;">
      <div style="font-size:0.62rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.4rem;">Costo afiliación</div>
      <div style="display:flex;align-items:center;gap:0.25rem;">
        <span style="color:#94a3b8;font-size:0.8rem;">$</span>
        <input type="text" name="configs[global][costo_afiliacion]"
            value="{{ $globalCfg ? number_format($globalCfg->costo_afiliacion, 0, ',', '.') : '0' }}"
            class="input-miles"
            style="width:100%;padding:0.4rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.87rem;font-family:monospace;text-align:right;font-weight:700;color:#0f172a;">
      </div>
    </div>

    {{-- Otros gastos. NO se guarda aquí: «otros» solo existe por celda del tarifario, así que
         esta casilla muestra el valor que más se repite hoy y sirve de atajo para cambiarlo en
         todos los planes. Por eso no lleva name y no viaja en el formulario. --}}
    <div style="background:#f8fafc;border-radius:9px;padding:0.7rem 0.8rem;border:1px solid #e2e8f0;">
      <div style="font-size:0.62rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.4rem;">Otros gastos</div>
      <div style="display:flex;align-items:center;gap:0.25rem;">
        <span style="color:#94a3b8;font-size:0.8rem;">$</span>
        <input type="text" id="inp_otros_general" data-campo="otros" data-general="1"
            value="{{ number_format($otrosGeneral, 0, ',', '.') }}"
            class="input-miles"
            style="width:100%;padding:0.4rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.87rem;font-family:monospace;text-align:right;font-weight:700;color:#0f172a;">
      </div>
    </div>

    <div>
      <div style="font-size:0.62rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;">Encargado por defecto</div>
      <select name="configs[global][encargado_default_id]"
          style="width:100%;padding:0.45rem 0.6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;background:#fff;">
        <option value="">— Ninguno —</option>
        @foreach($usuarios as $usr)
        <option value="{{ $usr->id }}" {{ ($globalCfg?->encargado_default_id == $usr->id) ? 'selected' : '' }}>{{ $usr->nombre }}</option>
        @endforeach
      </select>
    </div>

    <div>
      <div style="font-size:0.62rem;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;">🏷️ Promoción de afiliación</div>
      @include('admin.configuracion._boton_promocion', ['key' => 'global', 'cfg' => $globalCfg, 'nombre' => 'Todos los planes'])
    </div>
  </div>

  {{-- Se conservan sin editar. La admon del asesor salió de aquí: ahora sale del nivel del
       asesor (ver AsesorNivel::admon_asesor), no de un valor suelto del aliado. Los % de
       reparto los sigue usando la afiliación de los contratos sin tarifario
       (ver ConfiguracionAliado::calcularDistribucion). --}}
  <input type="hidden" name="configs[global][admon_asesor]" value="{{ intval($globalCfg?->admon_asesor ?? 0) }}">
  <input type="hidden" name="configs[global][dist_admon_pct]" value="{{ intval($globalCfg?->dist_admon_pct ?? 0) }}">
  <input type="hidden" name="configs[global][dist_retiro_pct]" value="{{ intval($globalCfg?->dist_retiro_pct ?? 0) }}">
</div>

{{-- ══ SECCIÓN 4: Tarifario por modalidad → plan → riesgo ARL ══ --}}
@php
  $resumenMod = [];
  foreach ($tarifario as $mid => $t) {
      $total = 0; $llenas = 0;
      foreach ($t['opciones'] as $o) {
          foreach ($o['niveles_arl'] as $n) {
              $total++;
              $c = $o['niveles'][$n];
              if ($c['costo_afiliacion'] !== null || $c['retiro'] !== null || $c['otros'] !== null || $c['administracion'] !== null) $llenas++;
          }
      }
      $resumenMod[$mid] = ['total' => $total, 'llenas' => $llenas];
  }

  // Tiempo Parcial va aparte: son 8 variantes de lo mismo y ahogaban la lista principal.
  $modsNormales = array_filter($tarifario, fn ($t) => ! $t['tiempo_parcial']);
  $modsParcial  = array_filter($tarifario, fn ($t) => $t['tiempo_parcial']);
@endphp

<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;"
     x-data="{ abierto: null, opcion: {} }">

  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:0.85rem;">
    <div>
      <div style="font-size:0.72rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:0.06em;">
        🦺 Tarifario por modalidad
      </div>
      <div style="font-size:0.72rem;color:#64748b;margin-top:0.25rem;line-height:1.5;">
        Elige la modalidad, luego el plan, y define el precio por cada nivel de riesgo ARL.
        Casilla vacía = usa el <strong>costo de afiliación general</strong> de arriba (aparece en gris).
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:0.5rem;white-space:nowrap;">
      @if(Auth::user()->hasRole('superadmin'))
      {{-- Recalcula el precio de afiliación de todos los planes contra lo que cuestan al mes. Solo
           superadmin: reescribe la lista de precios completa del aliado. --}}
      <button type="button" onclick="abrirPreciosSugeridos()"
          style="padding:0.4rem 0.85rem;border:1px solid #93c5fd;border-radius:8px;background:#eff6ff;
                 color:#0369a1;font-size:0.74rem;font-weight:700;cursor:pointer;">
        🧮 Calcular precios de afiliación
      </button>
      {{-- El retiro depende del salario mínimo: se recalcula cada año. --}}
      <button type="button" onclick="abrirRetirosSugeridos()"
          style="padding:0.4rem 0.85rem;border:1px solid #fde68a;border-radius:8px;background:#fffbeb;
                 color:#92400e;font-size:0.74rem;font-weight:700;cursor:pointer;">
        ♻️ Recalcular retiros
      </button>
      @endif
      <span style="font-size:0.68rem;color:#94a3b8;text-align:right;">Total mes estimado<br>sobre salario mínimo</span>
    </div>
  </div>

  @if(count($descuadradas))
  <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:0.6rem 0.85rem;margin-bottom:0.85rem;font-size:0.75rem;color:#991b1b;">
    ⚠️ Hay <strong>{{ count($descuadradas) }}</strong> celda(s) de niveles de asesor donde
    retiro + otros + lo del asesor supera el precio de afiliación. Al crear un contrato, la parte
    del aliado se ajusta a 0. Revísalas en Configuración → Niveles de asesores.
  </div>
  @endif

  @foreach($modsNormales as $modId => $t)
    @include('admin.configuracion._tarifario_modalidad', ['t' => $t, 'r' => $resumenMod[$modId]])
  @endforeach
</div>

{{-- ══ SECCIÓN 4.5: Tiempo Parcial (tarjeta aparte) ══ --}}
@if(count($modsParcial))
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;"
     x-data="{ abierto: null, opcion: {} }">
  <div style="font-size:0.72rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:0.06em;">
    ⏱️ Tiempo Parcial
  </div>
  <div style="font-size:0.72rem;color:#64748b;margin:0.25rem 0 0.85rem 0;line-height:1.5;">
    Las {{ count($modsParcial) }} variantes de Tiempo Parcial, separadas para no mezclarlas con
    las modalidades de mes completo. La ARL siempre cotiza el mes entero; lo que cambia por
    variante son los días de pensión y caja.
  </div>

  @foreach($modsParcial as $modId => $t)
    @include('admin.configuracion._tarifario_modalidad', ['t' => $t, 'r' => $resumenMod[$modId]])
  @endforeach
</div>
@endif

{{-- ══ Botón guardar ══ --}}
<div style="display:flex;justify-content:flex-end;gap:0.75rem;">
  <a href="{{ route('admin.contratos.index') }}"
     style="padding:0.6rem 1.25rem;border:1px solid #cbd5e1;border-radius:8px;color:#475569;text-decoration:none;font-size:0.85rem;">
    Cancelar
  </a>
  <button type="submit"
      style="padding:0.6rem 2rem;background:linear-gradient(135deg,#7c3aed,#5b21b6);border:none;border-radius:9px;color:#fff;font-size:0.88rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(124,58,237,0.35);">
    💾 Guardar Configuración
  </button>
</div>

</form>

{{-- ══ Modal: configurar promoción de afiliación ══ --}}
<div id="modalPromocion" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:1.5rem 1.75rem;width:100%;max-width:380px;box-shadow:0 20px 50px rgba(0,0,0,0.25);">
    <div style="font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:0.2rem;">🏷️ Promoción de afiliación</div>
    <div id="modalPromocionPlan" style="font-size:0.78rem;color:#64748b;margin-bottom:1rem;"></div>

    <label style="display:block;font-size:0.72rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Precio de afiliación promocional</label>
    <div style="display:flex;align-items:center;gap:0.25rem;margin-bottom:0.9rem;">
      <span style="color:#64748b;font-size:0.85rem;">$</span>
      <input type="text" id="modalPromoPrecio" placeholder="Ej: 80.000"
          style="flex:1;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;font-family:monospace;">
    </div>

    <label style="display:block;font-size:0.72rem;font-weight:600;color:#334155;margin-bottom:0.3rem;">Vence el</label>
    <input type="date" id="modalPromoVence"
        style="width:100%;padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;margin-bottom:1.1rem;">

    <div style="font-size:0.7rem;color:#94a3b8;margin-bottom:1.2rem;">
      Mientras esté vigente, este precio reemplaza el costo de afiliación normal en la web, el asistente de WhatsApp y el piloto de marketing — al vencer, vuelve solo al precio normal.
    </div>

    <div style="display:flex;justify-content:space-between;gap:0.5rem;">
      <button type="button" onclick="quitarPromocion()" style="font-size:0.78rem;color:#dc2626;background:none;border:none;cursor:pointer;padding:0.4rem 0;">Quitar promoción</button>
      <div style="display:flex;gap:0.5rem;">
        <button type="button" onclick="cerrarModalPromocion()" style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.8rem;cursor:pointer;">Cancelar</button>
        <button type="button" onclick="guardarModalPromocion()" style="padding:0.5rem 1.1rem;border:none;border-radius:8px;background:#7c3aed;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;">Aplicar</button>
      </div>
    </div>
  </div>
</div>

@if(Auth::user()->hasRole('superadmin'))
{{-- ══ Modal: precios de afiliación sugeridos ══
     Muestra el cálculo antes de escribir nada. El formulario va aparte del de configuración
     porque no puede haber un <form> dentro de otro. --}}
<div id="modalPrecios" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:1000;align-items:center;justify-content:center;padding:1.5rem;">
  <div style="background:#fff;border-radius:14px;padding:1.4rem 1.6rem;width:100%;max-width:640px;max-height:85vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,0.25);">
    <div style="font-size:1rem;font-weight:700;color:#0f172a;">🧮 Precios de afiliación sugeridos</div>
    <div id="preciosIntro" style="font-size:0.75rem;color:#64748b;margin:0.3rem 0 0.9rem;line-height:1.5;"></div>
    <div id="preciosTabla" style="font-size:0.78rem;"></div>
    <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.1rem;">
      <button type="button" onclick="document.getElementById('modalPrecios').style.display='none'"
          style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.8rem;cursor:pointer;">
        Cancelar
      </button>
      <button type="button" id="btnAplicarPrecios" onclick="document.getElementById('formPrecios').submit()"
          style="padding:0.5rem 1.1rem;border:none;border-radius:8px;background:#0369a1;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;">
        Aplicar estos precios
      </button>
    </div>
  </div>
</div>

<form id="formPrecios" method="POST" action="{{ route('admin.configuracion.precios_sugeridos.aplicar') }}">@csrf</form>

{{-- ══ Modal: retiros recalculados con el salario mínimo del año ══ --}}
<div id="modalRetiros" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:1000;align-items:center;justify-content:center;padding:1.5rem;">
  <div style="background:#fff;border-radius:14px;padding:1.4rem 1.6rem;width:100%;max-width:640px;max-height:85vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,0.25);">
    <div style="font-size:1rem;font-weight:700;color:#0f172a;">♻️ Retiros con el salario mínimo actual</div>
    <div id="retirosIntro" style="font-size:0.75rem;color:#64748b;margin:0.3rem 0 0.9rem;line-height:1.5;"></div>
    <div id="retirosTabla" style="font-size:0.78rem;"></div>
    <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.1rem;">
      <button type="button" onclick="document.getElementById('modalRetiros').style.display='none'"
          style="padding:0.5rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.8rem;cursor:pointer;">
        Cancelar
      </button>
      <button type="button" id="btnAplicarRetiros" onclick="document.getElementById('formRetiros').submit()"
          style="padding:0.5rem 1.1rem;border:none;border-radius:8px;background:#b45309;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;">
        Actualizar retiros
      </button>
    </div>
  </div>
</div>

<form id="formRetiros" method="POST" action="{{ route('admin.configuracion.retiros_sugeridos.aplicar') }}">@csrf</form>
@endif

{{-- ══ Modal: replicar la admon mensual a los demás planes ══
     Sale solo al cambiar una casilla de ADMON MES, porque en la práctica la admon es la
     misma en todos los planes y cambiarla una por una son 97 casillas. Solo mueve las
     casillas en pantalla; se guarda con el botón de siempre. --}}
<div id="modalAdmon" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:1.5rem 1.75rem;width:100%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,0.25);">
    <div id="modalAdmonTitulo" style="font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:0.2rem;">🏛️ Admon mensual</div>
    <div id="modalAdmonResumen" style="font-size:0.8rem;color:#64748b;margin-bottom:1rem;line-height:1.5;"></div>

    <div style="display:flex;flex-direction:column;gap:0.5rem;">
      <button type="button" id="btnAdmonMenores" onclick="admonAplicar('menores')"
          style="text-align:left;padding:0.65rem 0.85rem;border:1px solid #bbf7d0;border-radius:9px;background:#f0fdf4;cursor:pointer;font-size:0.82rem;color:#166534;font-weight:600;"></button>
      <button type="button" id="btnAdmonTodos" onclick="admonAplicar('todos')"
          style="text-align:left;padding:0.65rem 0.85rem;border:1px solid #ddd6fe;border-radius:9px;background:#faf5ff;cursor:pointer;font-size:0.82rem;color:#6d28d9;font-weight:600;"></button>
      <button type="button" onclick="cerrarModalAdmon()"
          style="text-align:left;padding:0.65rem 0.85rem;border:1px solid #e2e8f0;border-radius:9px;background:#fff;cursor:pointer;font-size:0.82rem;color:#475569;font-weight:600;">
        No cambiar ningún otro plan
      </button>
    </div>
  </div>
</div>
</div>

<script>
// Seguridad social por celda ("plan_modalidad_riesgo" → pesos), calculada en el servidor a
// salario mínimo. Alimenta el "Total mes" y el ajuste por delta de ARL.
const GRID_SS     = @json($gridSs);
const SEGURO_BASE = {{ (int) $seguroBase }};
// {id: nombre} — lo usa "copiar de otro plan" para poder nombrarlos sin recorrer el DOM.
const PLANES      = @json($planes->pluck('nombre', 'id'));

const soloDigitos = v => parseInt(String(v ?? '').replace(/\D/g, '') || '0', 10);
const conMiles    = n => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

function celdaInput(plan, mod, nivel, campo) {
    return document.querySelector(
        `input[name="tarifario[${plan}][${mod}][${nivel}][${campo}]"]`
    );
}

/** Recalcula el "Total mes" de la fila: seguridad social + admon escrita (o su respaldo) + seguro. */
function recalcTotal(input) {
    const fila = input.closest('tr');
    if (!fila) return;
    const clave = fila.dataset.celda;
    const td    = document.querySelector(`[data-total="${clave}"]`);
    if (!td) return;

    // Vacío = usa el respaldo, que es justo lo que muestra el placeholder.
    const admon = input.value.trim() === ''
        ? soloDigitos(input.placeholder)
        : soloDigitos(input.value);

    td.textContent = conMiles((GRID_SS[clave] || 0) + admon + SEGURO_BASE);
}

/** Copia los 4 valores del riesgo 1 a los riesgos 2..5 de esa modalidad. */
function replicarRiesgos(plan, mod) {
    const campos = ['costo_afiliacion', 'retiro', 'otros', 'administracion'];
    campos.forEach(campo => {
        const origen = celdaInput(plan, mod, 1, campo);
        if (!origen) return;
        for (let n = 2; n <= 5; n++) {
            const destino = celdaInput(plan, mod, n, campo);
            if (!destino) continue;
            destino.value = origen.value;
            destino.style.background = origen.value ? '#fff' : '#fafafa';
            if (campo === 'administracion') recalcTotal(destino);
        }
    });
}

/**
 * Sube la afiliación de cada riesgo según lo que sube la prima de ARL frente al riesgo 1,
 * redondeado a miles. Es la regla de "el mismo plan con ARL 2 solo cambia lo que sube la ARL".
 * La admon se replica igual en los 5, que es como se maneja en la práctica.
 */
function ajustarPorArl(plan, mod) {
    const base = celdaInput(plan, mod, 1, 'costo_afiliacion');
    if (!base) return;

    const afilBase = base.value.trim() === '' ? soloDigitos(base.placeholder) : soloDigitos(base.value);
    const ssBase   = GRID_SS[`${plan}_${mod}_1`] || 0;

    let cambios = [];
    for (let n = 2; n <= 5; n++) {
        const destino = celdaInput(plan, mod, n, 'costo_afiliacion');
        if (!destino) continue;
        const delta = Math.round(((GRID_SS[`${plan}_${mod}_${n}`] || 0) - ssBase) / 1000) * 1000;
        cambios.push({ input: destino, valor: afilBase + Math.max(0, delta), delta: Math.max(0, delta) });
    }

    if (!cambios.length) return;
    const resumen = cambios.map((c, i) => `  Riesgo ${i + 2}: $${conMiles(c.valor)}  (+$${conMiles(c.delta)})`).join('\n');
    if (!confirm(`Afiliación del riesgo 1: $${conMiles(afilBase)}\n\nSe aplicará:\n${resumen}\n\n¿Continuar?`)) return;

    cambios.forEach(c => { c.input.value = conMiles(c.valor); c.input.style.background = '#fff'; });

    // La admon no depende del riesgo: se replica tal cual.
    const admonBase = celdaInput(plan, mod, 1, 'administracion');
    if (admonBase) {
        for (let n = 2; n <= 5; n++) {
            const d = celdaInput(plan, mod, n, 'administracion');
            if (!d) continue;
            d.value = admonBase.value;
            recalcTotal(d);
        }
    }
}

// ── Precios sugeridos para los planes sin AFP ─────────────────────────
// Se pide el cálculo al servidor y se muestra ANTES de escribir: reescribe la lista de
// precios completa del aliado y eso no se hace a ciegas.
const URL_PRECIOS = '{{ route('admin.configuracion.precios_sugeridos') }}';

function abrirPreciosSugeridos() {
    const caja = document.getElementById('modalPrecios');
    const tabla = document.getElementById('preciosTabla');
    const intro = document.getElementById('preciosIntro');
    const btn = document.getElementById('btnAplicarPrecios');

    intro.textContent = 'Calculando…';
    tabla.innerHTML = '';
    btn.disabled = true;
    caja.style.display = 'flex';

    fetch(URL_PRECIOS)
        .then(r => r.json())
        .then(d => {
            intro.innerHTML =
                `Todo sale del <strong>costo mensual del plan</strong> (seguridad social + admon, a salario mínimo).`
                + `<br>· Plan <strong>sin pensión</strong>: el <strong>${d.pct}%</strong> de su propio mes.`
                + `<br>· Plan <strong>con pensión</strong>: el mes de ese mismo plan <strong>quitándole la AFP</strong>, `
                + `completo — la pensión se lleva más de la mitad de la cotización y no puede arrastrar la afiliación.`
                + `<br>Nunca menos de $${conMiles(d.piso)} ni más de lo que cuesta el mes. Los riesgos 2 al 5 conservan la escalera que ya usas.`;

            const filas = d.filas.map(f => {
                const flecha = f.hoy === 0 ? '<span style="color:#0369a1">nuevo</span>'
                    : (f.nuevo > f.hoy ? '<span style="color:#166534">↑</span>'
                    : (f.nuevo < f.hoy ? '<span style="color:#b45309">↓</span>' : '='));
                return `<tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:.25rem .4rem">${f.plan}</td>
                    <td style="padding:.25rem .4rem;text-align:center;color:#94a3b8">${f.nivel_arl || '—'}</td>
                    <td style="padding:.25rem .4rem;text-align:right;color:#94a3b8;font-family:monospace">${conMiles(f.mes)}</td>
                    <td style="padding:.25rem .4rem;text-align:right;color:#94a3b8;font-family:monospace">${f.pension ? conMiles(f.base) : '—'}</td>
                    <td style="padding:.25rem .4rem;text-align:right;color:#94a3b8;font-family:monospace">${f.hoy ? conMiles(f.hoy) : '—'}</td>
                    <td style="padding:.25rem .4rem;text-align:right;font-family:monospace;font-weight:700">${conMiles(f.nuevo)}</td>
                    <td style="padding:.25rem .4rem;text-align:center">${flecha}</td>
                </tr>`;
            }).join('');

            tabla.innerHTML = `<table style="width:100%;border-collapse:collapse">
                <thead><tr style="background:#f8fafc">
                  <th style="padding:.3rem .4rem;text-align:left;font-size:.62rem;color:#475569">PLAN</th>
                  <th style="padding:.3rem .4rem;font-size:.62rem;color:#475569">ARL</th>
                  <th style="padding:.3rem .4rem;text-align:right;font-size:.62rem;color:#475569">CUESTA AL MES</th>
                  <th style="padding:.3rem .4rem;text-align:right;font-size:.62rem;color:#475569">SIN AFP</th>
                  <th style="padding:.3rem .4rem;text-align:right;font-size:.62rem;color:#475569">HOY</th>
                  <th style="padding:.3rem .4rem;text-align:right;font-size:.62rem;color:#475569">NUEVO</th>
                  <th></th>
                </tr></thead><tbody>${filas}</tbody></table>
                <div style="font-size:.7rem;color:#64748b;margin-top:.6rem">
                  Cambian <strong>${d.cambian}</strong> precios y se escriben <strong>${d.celdas}</strong>
                  casillas (el precio del plan se replica a todas sus modalidades).
                  Solo se toca el costo de afiliación: retiro, otros y admon quedan igual.
                </div>`
                // El precio se calcula contra el plan como dependiente, pero el retiro es de
                // la modalidad: en tiempo parcial puede costar más que toda la afiliación.
                + (d.subidas && d.subidas.length ? `<div style="margin-top:.6rem;padding:.6rem .8rem;
                    background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:.7rem;color:#78350f;line-height:1.5">
                    En <strong>${d.subidas.length}</strong> casillas el precio del plan no alcanzaba ni para pagar el
                    retiro de esa modalidad, así que se suben hasta el retiro. Ahí al asesor no le queda comisión:
                    si quieres que gane algo, sube esa casilla a mano.<br>
                    ${d.subidas.slice(0, 6).map(s => `· ${s.plan} · ${s.modalidad} · riesgo ${s.nivel_arl}: `
                      + `${conMiles(s.del_plan)} → <strong>${conMiles(s.valor)}</strong> (retiro ${conMiles(s.retiro)})`).join('<br>')}
                    ${d.subidas.length > 6 ? `<br>… y ${d.subidas.length - 6} más` : ''}
                  </div>` : '')
                // Las que ya valen más de lo propuesto no se bajan: son ajustes del aliado.
                + (d.conservadas && d.conservadas.length ? `<div style="margin-top:.6rem;padding:.6rem .8rem;
                    background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:.7rem;color:#166534;line-height:1.5">
                    <strong>${d.conservadas.length}</strong> casillas se quedan como están porque ya valen más de lo
                    que propone el cálculo. Para bajarlas hay que editarlas a mano.<br>
                    ${d.conservadas.slice(0, 6).map(s => `· ${s.plan} · ${s.modalidad} · riesgo ${s.nivel_arl}: `
                      + `sigue en <strong>${conMiles(s.actual)}</strong> (proponía ${conMiles(s.propuesto)})`).join('<br>')}
                    ${d.conservadas.length > 6 ? `<br>… y ${d.conservadas.length - 6} más` : ''}
                  </div>` : '');
            btn.disabled = false;
        })
        .catch(() => { intro.textContent = 'No se pudo calcular. Intenta de nuevo.'; });
}

// ── Retiros recalculados con el salario mínimo del año ────────────────
const URL_RETIROS = '{{ route('admin.configuracion.retiros_sugeridos') }}';

function abrirRetirosSugeridos() {
    const caja = document.getElementById('modalRetiros');
    const tabla = document.getElementById('retirosTabla');
    const intro = document.getElementById('retirosIntro');
    const btn = document.getElementById('btnAplicarRetiros');

    intro.textContent = 'Calculando…';
    tabla.innerHTML = '';
    btn.disabled = true;
    caja.style.display = 'flex';

    fetch(URL_RETIROS)
        .then(r => r.json())
        .then(d => {
            intro.innerHTML =
                `El retiro es lo que cuesta sacar a la persona: <strong>un día</strong> de seguridad social, `
                + `y en <strong>tiempo parcial el bloque mínimo de ${d.dias_tp} días</strong> (ahí la planilla no admite `
                + `menos, y la ARL siempre cotiza el mes entero).`
                + `<br>Solo se actualizan los que quedaron por debajo del cálculo — los que están más altos `
                + `son ajustes tuyos y no se tocan.`;

            if (!d.suben) {
                tabla.innerHTML = '<div style="padding:.8rem;background:#f0fdf4;border:1px solid #bbf7d0;'
                    + 'border-radius:8px;color:#166534">Todos los retiros están al día. No hay nada que cambiar.</div>';
                return;
            }

            const filas = d.filas.map(f => `<tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:.25rem .4rem">${f.plan}</td>
                    <td style="padding:.25rem .4rem;color:#64748b">${f.modalidad}</td>
                    <td style="padding:.25rem .4rem;text-align:center;color:#94a3b8">${f.nivel_arl}</td>
                    <td style="padding:.25rem .4rem;text-align:right;color:#94a3b8;font-family:monospace">${f.hoy === null ? '—' : conMiles(f.hoy)}</td>
                    <td style="padding:.25rem .4rem;text-align:right;font-family:monospace;font-weight:700">${conMiles(f.calculado)}</td>
                </tr>`).join('');

            tabla.innerHTML = `<table style="width:100%;border-collapse:collapse">
                <thead><tr style="background:#f8fafc">
                  <th style="padding:.3rem .4rem;text-align:left;font-size:.62rem;color:#475569">PLAN</th>
                  <th style="padding:.3rem .4rem;text-align:left;font-size:.62rem;color:#475569">MODALIDAD</th>
                  <th style="padding:.3rem .4rem;font-size:.62rem;color:#475569">ARL</th>
                  <th style="padding:.3rem .4rem;text-align:right;font-size:.62rem;color:#475569">HOY</th>
                  <th style="padding:.3rem .4rem;text-align:right;font-size:.62rem;color:#475569">NUEVO</th>
                </tr></thead><tbody>${filas}</tbody></table>
                <div style="font-size:.7rem;color:#64748b;margin-top:.6rem">
                  Suben <strong>${d.suben}</strong> de ${d.total} casillas. Solo se toca el retiro.
                </div>`;
            btn.disabled = false;
        })
        .catch(() => { intro.textContent = 'No se pudo calcular. Intenta de nuevo.'; });
}

// ── Replicar la admon mensual a los demás planes ──────────────────────
// La admon es la misma en casi todos los planes, y el tarifario tiene ~97 casillas: al
// cambiar una se ofrece llevarla al resto. Todo pasa en pantalla; guarda el botón de siempre.

/** Valor efectivo de una casilla: lo escrito, o el respaldo que muestra el placeholder. */
function valorEfectivo(input) {
    return input.value.trim() === '' ? soloDigitos(input.placeholder) : soloDigitos(input.value);
}

let admonPendiente = null;

const COLUMNAS_REPLICABLES = {
    administracion: { titulo: '🏛️ Admon mensual', etiqueta: 'la admon' },
    otros: { titulo: '🧾 Otros', etiqueta: 'este gasto' },
};

/**
 * Casillas de una columna del tarifario. La admon suma además la de Valores generales, que
 * es su respaldo: dejar una desalineada de las otras descuadra el cotizador.
 */
function casillasDe(campo) {
    const celdas = [...document.querySelectorAll(`input[data-campo="${campo}"]`)];

    return campo === 'administracion'
        ? [...celdas, ...document.querySelectorAll('input[data-admon-general]')]
        : celdas;
}

function casillasAdmon() {
    return casillasDe('administracion');
}

function propagarAdmon(input) {
    // La casilla de Valores generales no tiene data-campo: es la admon del aliado.
    const campo = input.dataset.campo || 'administracion';
    const cfg = COLUMNAS_REPLICABLES[campo];
    if (!cfg) return;

    const nuevo = valorEfectivo(input);
    const antes = parseInt(input.dataset.antes || '0', 10);
    // Solo la admon entra en el "Total mes" de la fila; "otros" no se cotiza al cliente.
    if (campo === 'administracion') recalcTotal(input);
    if (nuevo === antes) return;

    const otras = casillasDe(campo)
        .filter(i => i !== input)
        .map(i => ({ input: i, valor: valorEfectivo(i) }));

    const menores = otras.filter(o => o.valor < nuevo);
    const mayores = otras.filter(o => o.valor > nuevo);
    if (!menores.length && !mayores.length) return; // el resto ya está en ese valor

    admonPendiente = { nuevo, menores, otras, campo };

    const rangoMayores = mayores.length
        ? ` y ${mayores.length} por encima (hasta $${conMiles(Math.max(...mayores.map(m => m.valor)))})`
        : '';
    const donde = input.dataset.general ? `${cfg.etiqueta} de Valores generales` : 'esta casilla';
    document.getElementById('modalAdmonTitulo').textContent = cfg.titulo;
    document.getElementById('modalAdmonResumen').innerHTML =
        `Cambiaste ${donde} de <strong>$${conMiles(antes)}</strong> a <strong>$${conMiles(nuevo)}</strong>.<br>`
        + `Quedan ${menores.length} casillas por debajo${rangoMayores}.`;

    // Sin casillas por encima, "subir las de abajo" y "poner en todas" hacen lo mismo:
    // se muestra un solo botón para no ofrecer dos veces la misma acción.
    const bMenores = document.getElementById('btnAdmonMenores');
    const bTodos = document.getElementById('btnAdmonTodos');

    bMenores.style.display = (menores.length && mayores.length) ? 'block' : 'none';
    bMenores.textContent = `Poner $${conMiles(nuevo)} solo en las ${menores.length} que están por debajo`;

    bTodos.style.display = 'block';
    const altas = mayores.length === 1
        ? 'incluida la que está más alta'
        : `incluidas las ${mayores.length} que están más altas`;
    bTodos.textContent = mayores.length
        ? `Poner $${conMiles(nuevo)} en las ${otras.length}, ${altas}`
        : `Poner $${conMiles(nuevo)} en las ${menores.length} casillas restantes`;

    document.getElementById('modalAdmon').style.display = 'flex';
}

function admonAplicar(alcance) {
    if (!admonPendiente) return;
    const { nuevo, menores, otras, campo } = admonPendiente;

    (alcance === 'menores' ? menores : otras).forEach(({ input }) => {
        input.value = conMiles(nuevo);
        input.dataset.antes = nuevo;
        input.style.background = '#fff';
        if (campo === 'administracion') recalcTotal(input);
    });

    cerrarModalAdmon();
}

function cerrarModalAdmon() {
    document.getElementById('modalAdmon').style.display = 'none';
    admonPendiente = null;
}

/** Copia esta misma modalidad desde otro plan que ya esté tarifado. */
function copiarDePlan(planDestino, mod) {
    const disponibles = [];
    document.querySelectorAll(`input[data-mod="${mod}"][data-campo="costo_afiliacion"]`).forEach(i => {
        const p = parseInt(i.dataset.plan, 10);
        if (p !== planDestino && !disponibles.includes(p)) disponibles.push(p);
    });

    if (!disponibles.length) {
        alert('Ningún otro plan tiene esta modalidad para copiar.');
        return;
    }

    const nombres = disponibles.map(p => `${p} = ${PLANES[p] ?? ('Plan ' + p)}`).join('\n');

    const elegido = prompt(`Copiar esta modalidad desde qué plan?\n\n${nombres}\n\nEscribe el número:`);
    const origen  = parseInt(elegido, 10);
    if (!origen || !disponibles.includes(origen)) return;

    ['costo_afiliacion', 'retiro', 'otros', 'administracion'].forEach(campo => {
        for (let n = 1; n <= 5; n++) {
            const o = celdaInput(origen, mod, n, campo);
            const d = celdaInput(planDestino, mod, n, campo);
            if (!o || !d) continue;
            d.value = o.value;
            d.style.background = o.value ? '#fff' : '#fafafa';
            if (campo === 'administracion') recalcTotal(d);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.input-miles');
    
    function formatNumber(val) {
        val = val.toString().replace(/\D/g, '');
        return val.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }
    
    inputs.forEach(input => {
        // Al escribir, formatear con puntos de miles
        input.addEventListener('input', function(e) {
            let cursorPosition = e.target.selectionStart;
            let originalLength = e.target.value.length;
            
            let valClean = e.target.value.replace(/\D/g, '');
            if (valClean === '') {
                e.target.value = '';
                return;
            }
            
            let formatted = formatNumber(valClean);
            e.target.value = formatted;
            
            // Ajustar posición del cursor tras formatear
            let newLength = formatted.length;
            let diff = newLength - originalLength;
            e.target.setSelectionRange(cursorPosition + diff, cursorPosition + diff);
        });
    });
    
    // La admon mensual y "otros" ofrecen replicarse al resto del tarifario: son valores que
    // en la práctica se repiten en todos los planes y son ~140 casillas. Se guarda el valor
    // con el que entró al foco para poder decir "de X a Y", y el aviso sale al salir de la
    // casilla (change), no en cada tecla.
    [...casillasDe('administracion'), ...casillasDe('otros')].forEach(el => {
        el.dataset.antes = valorEfectivo(el);
        el.addEventListener('focus', () => { el.dataset.antes = valorEfectivo(el); });
        el.addEventListener('change', () => propagarAdmon(el));
    });

    // Antes de enviar el formulario, limpiar los puntos para que a Laravel le lleguen números
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            inputs.forEach(input => {
                input.value = input.value.replace(/\D/g, '');
            });
        });
    }
});

// ── Modal de promoción de afiliación ──────────────────────────────────
let promoKeyActual = null;

function abrirModalPromocion(btn) {
    promoKeyActual = btn.dataset.key;
    document.getElementById('modalPromocionPlan').textContent =
        btn.dataset.nombre || btn.closest('tr')?.querySelector('td')?.textContent.trim() || '';
    document.getElementById('modalPromoPrecio').value = btn.dataset.precio ? Number(btn.dataset.precio).toLocaleString('es-CO') : '';
    document.getElementById('modalPromoVence').value = btn.dataset.vence || '';
    document.getElementById('modalPromocion').style.display = 'flex';
}

function cerrarModalPromocion() {
    document.getElementById('modalPromocion').style.display = 'none';
    promoKeyActual = null;
}

function actualizarBoton(key, precio, vence) {
    const btn = document.querySelector('.btn-promocion[data-key="' + key + '"]');
    const vigente = precio && vence && new Date(vence + 'T00:00:00') >= new Date(new Date().toDateString());
    if (vigente) {
        const [y, m, d] = vence.split('-');
        btn.textContent = '🏷️ Hasta ' + d + '/' + m + '/' + y;
        btn.style.background = '#fef9c3'; btn.style.color = '#92400e'; btn.style.border = '1px solid #fde047';
    } else {
        btn.textContent = '+ Configurar';
        btn.style.background = '#f8fafc'; btn.style.color = '#94a3b8'; btn.style.border = '1px dashed #cbd5e1';
    }
    btn.dataset.precio = precio || '';
    btn.dataset.vence = vence || '';
}

function guardarModalPromocion() {
    const precio = document.getElementById('modalPromoPrecio').value.replace(/\D/g, '');
    const vence = document.getElementById('modalPromoVence').value;
    if (!precio || !vence) { alert('Completa el precio promocional y la fecha de vencimiento.'); return; }

    document.querySelector('.promo-input-precio[data-key="' + promoKeyActual + '"]').value = precio;
    document.querySelector('.promo-input-vence[data-key="' + promoKeyActual + '"]').value = vence;
    actualizarBoton(promoKeyActual, precio, vence);
    cerrarModalPromocion();
}

function quitarPromocion() {
    document.querySelector('.promo-input-precio[data-key="' + promoKeyActual + '"]').value = '';
    document.querySelector('.promo-input-vence[data-key="' + promoKeyActual + '"]').value = '';
    actualizarBoton(promoKeyActual, null, null);
    cerrarModalPromocion();
}
</script>
@endsection
