@extends('layouts.app')
@section('modulo', isset($contrato->id) ? 'Contrato #'.$contrato->id : 'Nuevo Contrato')

@section('contenido')
@php
  $esEdicion      = isset($contrato->id) && $contrato->id;
  // En edición: usar SOLO el valor guardado en el contrato (sin fallback al cliente).
  // En creación: usar el valor del cliente como sugerencia inicial.
  $epsDefault     = old('eps_id',     $esEdicion ? ($contrato->eps_id     ?? '') : ($contrato->eps_id     ?? $clienteEpsId      ?? ''));
  $pensionDefault = old('pension_id', $esEdicion ? ($contrato->pension_id ?? '') : ($contrato->pension_id ?? $clientePensionId  ?? ''));
  $arlDefault     = old('arl_id',     $contrato->arl_id ?: $arlIdRazonSocial ?: '');

  // ── Botón del certificado ARL ──────────────────────────────────────────
  // Solo Sura tiene API, y la póliza de la razón social es lo que abre la
  // sesión del portal: sin ella el botón sería un callejón sin salida, y
  // cargarla se hace desde Gestión ARL. La descarga cuelga de
  // `gestion_arl.ver`, que no es el permiso de esta pantalla, así que se
  // pregunta antes en vez de dejar que el clic termine en un 403 mudo.
  // `arlEfectivaNombre` lo resuelve el controlador con ResuelveArlEfectiva:
  // en los independientes manda el `arl_id` del contrato y en las empresas
  // el `arl_nit` de la razón social.
  $puedeCertificadoArl = $esEdicion
      && str_contains(mb_strtoupper($arlEfectivaNombre ?? ''), 'SURA')
      && $contrato->razonSocial?->arl_poliza
      && auth()->user()?->can('gestion_arl.ver')
      && auth()->user()?->can('automatizar-arl');
  // intval evita que PHP emita "1750905.00" que rompe el JS de Alpine
  $defAdmon       = (int) old('administracion',   $contrato->administracion  ?? $defaultTarifas['administracion']    ?? 0);
  $defAdmonAsesor = (int) old('admon_asesor',     $contrato->admon_asesor    ?? $defaultTarifas['admon_asesor']      ?? 0);
  $defCosto       = (int) old('costo_afiliacion', $contrato->costo_afiliacion ?? $defaultTarifas['costo_afiliacion'] ?? 0);
  $defSeguro      = (int) old('seguro',           $contrato->seguro           ?? $defaultTarifas['seguro']           ?? 0);
  $defEncargado   = old('encargado_id',     $contrato->encargado_id     ?? $defaultTarifas['encargado_id']     ?? auth()->id());

  // ── Reparto de la afiliación: empresa + asesor = costo_afiliacion ──
  // Vacío (no 0) en la parte del asesor significa "contrato sin tarifario": la facturación
  // lo reparte con la lógica anterior. Hay que conservar la diferencia entre '' y 0.
  $defAfilAsesor = old('afiliacion_asesor', $contrato->afiliacion_asesor ?? null);
  $defAfilAsesor = ($defAfilAsesor === null || $defAfilAsesor === '') ? '' : (int) $defAfilAsesor;
  $defAfilEmpresa = max(0, $defCosto - ($defAfilAsesor === '' ? 0 : $defAfilAsesor));
  // Sin asesor la casilla va en 0; con asesor y sin valor guardado se deja vacía (ver el campo).
  $defAfilAsesorVista = $defAfilAsesor !== ''
    ? number_format($defAfilAsesor, 0, '', '.')
    : (old('asesor_id', $contrato->asesor_id ?? null) ? '' : '0');
  $defSalario     = (int) old('salario',          $contrato->salario          ?? $salarioMinimo);
  $defIbc         = (int) old('ibc',              $contrato->ibc              ?? $defSalario);
  $S = 'width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;background:#fff;box-sizing:border-box;';
  $I = 'width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;box-sizing:border-box;';
  $M = 'width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;font-family:monospace;box-sizing:border-box;';

  // ── Bloqueo por afiliaciones en trámite u OK ──────────────────────
  // $rsLock: campos deshabilitados (usuario normal).
  // $rsWarn: mismos campos editables porque es superadmin, marcados en ámbar
  //          y con confirmación antes de guardar (ver RS_WARN en el JS).
  $rsLock = $esEdicion && ($rsBloquedaPorAfiliacion ?? false);
  $rsWarn = $esEdicion && ($rsDesbloqueoSuperadmin  ?? false);
  // Con $rsWarn el formulario se ve exactamente igual que uno sin bloqueo.
  // El aviso y el resaltado ámbar solo aparecen cuando el superadmin toca uno
  // de los campos protegidos (marcados con data-prot, ver activarAvisoProtegidos).
  $prot = $rsWarn ? 'data-prot="1"' : '';
  $tipLock = $rsLock
    ? '🔒 Bloqueado — hay afiliaciones en trámite u OK'
    : '⚠️ Hay afiliaciones en trámite u OK — desbloqueado por ser superadmin';
  // $ecoLock: salario, IBC y encargado. No viajan en el radicado, así que el
  // admin los mueve aunque el contrato ya esté radicado (ver
  // ContratoController::puedeEditarEconomico). Para el resto siguen bloqueados,
  // y bloqueados de verdad: el valor original viaja en un hidden para que el
  // recálculo automático del IBC no haga rebotar el guardado completo.
  $ecoLock = $rsLock && ! ($economicoDesbloqueado ?? false);
  $tipEco  = '🔒 Bloqueado — hay afiliaciones en trámite u OK. Lo puede cambiar un administrador.';
  // Los hidden llevan el valor de la BD, no el de $defSalario/$defIbc: esos dos
  // arrastran el old() de un intento rechazado, y el IBC en blanco lo rellena el
  // cotizador con el salario. Enviar el valor guardado es lo único que garantiza
  // que el campo bloqueado no cuente como cambio.
  $ecoSalarioBd = $esEdicion ? (int) ($contrato->salario ?? 0) : 0;
  $ecoIbcBd     = ($esEdicion && $contrato->ibc !== null) ? (int) $contrato->ibc : '';
@endphp

<div style="max-width:1240px;margin:0 auto;" x-data="cotizador()">

{{-- ENCABEZADO --}}
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.85rem;">
  <div style="display:flex;align-items:center;gap:0.75rem;">
    <div style="width:36px;height:36px;background:linear-gradient(135deg,#2563eb,#1d4ed8);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 4px 12px rgba(37,99,235,0.35);">&#128203;</div>
    <div>
      <h1 style="font-size:1.05rem;font-weight:700;color:#0f172a;margin:0;">
        {{ $esEdicion ? 'Contrato #'.$contrato->id : 'Nuevo Contrato' }}
      </h1>
      @if($cliente)
      <div style="font-size:0.76rem;color:#475569;">
        <strong>{{ nombre_oracion(trim(($cliente->primer_nombre ?? '').' '.($cliente->segundo_nombre ?? ''))) }} {{ nombre_oracion(trim(($cliente->primer_apellido ?? '').' '.($cliente->segundo_apellido ?? ''))) }}</strong>
        &middot; {{ $cliente->tipo_doc ?: 'CC' }} {{ $cliente->cedula }}
        @if($cliente->iva === 'SI')<span style="background:#fef3c7;color:#92400e;padding:0.1rem 0.4rem;border-radius:999px;font-size:0.68rem;margin-left:4px;">IVA</span>@endif
      </div>
      @endif
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:0.6rem;">
    @if($esEdicion)
    <span style="padding:0.28rem 0.9rem;border-radius:999px;font-size:0.78rem;font-weight:700;
        background:{{ $contrato->estado === 'vigente' ? '#dcfce7' : ($contrato->estado === 'retirado' ? '#fee2e2' : '#f1f5f9') }};
        color:{{ $contrato->estado === 'vigente' ? '#15803d' : ($contrato->estado === 'retirado' ? '#dc2626' : '#475569') }};">
      {{ strtoupper($contrato->estado) }}
    </span>
    @endif
    <a href="{{ $backUrl ?? route('admin.contratos.index') }}"
       style="padding:0.38rem 0.9rem;border:1px solid #cbd5e1;border-radius:7px;color:#475569;text-decoration:none;font-size:0.8rem;">&larr; Volver</a>
  </div>
</div>

@if($errors->any())
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;padding:0.55rem 1rem;margin-bottom:0.65rem;font-size:0.8rem;">
  <strong>Errores:</strong> @foreach($errors->all() as $e) &middot; {{ $e }} @endforeach
</div>
@endif

@if($rsWarn)
{{-- Oculto al cargar: lo revela activarAvisoProtegidos() cuando el superadmin
     toca Razón Social, Modalidad, Plan, F. Ingreso o Encargado. --}}
<div id="aviso-protegidos" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;border-radius:8px;color:#92400e;padding:0.55rem 1rem;margin-bottom:0.65rem;font-size:0.8rem;line-height:1.45;">
  <strong>&#9888;&#65039; Este contrato ya tiene afiliaciones en trámite u OK.</strong>
  Para cualquier otro usuario estos campos estarían bloqueados. Puede modificarlos por ser superadmin,
  pero el cambio no se sincroniza con lo ya radicado ante las entidades: los radicados existentes debe
  gestionarlos desde Afiliaciones. Se le pedirá confirmación al guardar y quedará registrado en la bitácora.
</div>
@endif

<form method="POST"
      action="{{ $esEdicion ? route('admin.contratos.update', $contrato->id) : route('admin.contratos.store') }}"
      id="form-contrato">
  @csrf
  @if($esEdicion) @method('PUT') @endif
  <input type="hidden" name="cedula" value="{{ old('cedula', $cliente->cedula ?? $contrato->cedula ?? '') }}">
  <input type="hidden" name="back_url" value="{{ $backUrl ?? '' }}">
  @if(!empty($solicitudPortal))
    {{-- Ingreso pedido por la empresa desde su portal: al guardar queda aprobado. --}}
    <input type="hidden" name="empresa_solicitud_id" value="{{ $solicitudPortal->id }}">
    <div style="background:linear-gradient(135deg,#eff6ff,#e0f2fe);border:1px solid #93c5fd;border-radius:12px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#1e3a8a;">
      <div style="font-weight:800;margin-bottom:.25rem;">🌐 Ingreso pedido por {{ $solicitudPortal->empresa?->empresa }} desde su portal</div>
      Plan pedido: <b>{{ $solicitudPortal->datos['plan_nombre'] ?? '—' }}</b>
      · Ingreso: <b>{{ !empty($solicitudPortal->datos['fecha_ingreso']) ? \Illuminate\Support\Carbon::parse($solicitudPortal->datos['fecha_ingreso'])->format('d/m/Y') : '—' }}</b>
      · Cargo: <b>{{ $solicitudPortal->datos['cargo'] ?? '—' }}</b>
      <div style="font-size:.74rem;color:#475569;margin-top:.25rem;">Escoge razón social, modalidad y plan, y pon salario y nivel de riesgo. Al guardar, la empresa ve el ingreso aprobado.</div>
    </div>
  @endif
  @if(request()->has('iframe'))
  <input type="hidden" name="iframe" value="1">
  @endif

<div style="display:grid;grid-template-columns:1fr 300px;gap:1.1rem;align-items:start;">

{{-- ══ COLUMNA IZQUIERDA ══ --}}
<div style="display:flex;flex-direction:column;gap:0.7rem;">

  {{-- Panel 1: RS + Modalidad + Plan + F.Ingreso --}}
  <div class="cp">
    <div class="pt" style="color:#2563eb;">&#127970; Contrato</div>
    {{-- La columna del período solo existe para las modalidades que la admiten:
         el grid pasa de cuatro a cinco y reparte el ancho, en vez de dejar un
         hueco fijo que la mayoría de contratos nunca usa. --}}
    {{-- El binding va como objeto y no como cadena: Alpine, con una cadena,
         reescribe el atributo entero y se lleva por delante el `display:grid`
         —la fila se apila—. Con objeto toca solo la propiedad que nombra. Las
         columnas también van en el style estático para que la fila ya esté
         armada antes de que Alpine arranque. --}}
    {{-- Con Tiempo Parcial Independiente la fila llega a seis columnas (se suma
         "Días TP"), y repartirla como si fueran cinco dejaba el nombre del plan
         cortado. Los dos selectores nuevos son cortos y van a ancho fijo: lo que
         necesita espacio es la razón social y el plan. --}}
    <div style="display:grid;gap:0.5rem;grid-template-columns:1.6fr 1.2fr 1.2fr 120px;"
         :style="{ gridTemplateColumns:
             esTpDiasContrato && MODALIDADES_MES_ACTUAL.includes(parseInt(tipoModalidadId))
                 ? '1.25fr 1.1fr 95px 115px 1.3fr 118px'
             : esTpDiasContrato
                 ? '1.45fr 1.2fr 95px 1.35fr 118px'
             : MODALIDADES_MES_ACTUAL.includes(parseInt(tipoModalidadId))
                 ? '1.35fr 1fr 0.85fr 1.45fr 120px'
                 : '1.6fr 1.2fr 1.2fr 120px' }">
      <div>
        <label class="lb">Razon Social <span id="badge-rs-nit" style="font-weight:400;color:#64748b;font-size:0.63rem;background:#f1f5f9;padding:0.1rem 0.45rem;border-radius:6px;margin-left:4px;font-family:monospace;letter-spacing:0.02em;display:none;"></span></label>
        <div class="{{ $rsLock ? 'tip-lock' : '' }}" data-tip="{{ $tipLock }}">
        <select name="razon_social_id" id="sel_rs" style="{{ $S }}{{ $rsLock ? 'background:#f1f5f9;color:#1e293b;cursor:not-allowed;opacity:1;' : '' }}"
            onchange="onRazonSocialChange(this)" {!! $prot !!}
            {{ $rsLock ? 'disabled' : '' }}>
          <option value="">-- Ninguna --</option>
          @foreach($razonesSociales as $rs)
          @php
            $rsOcupada  = in_array($rs->id, $rsOcupadasIds ?? []);
            $rsInactiva = (strtolower(trim($rs->estado ?? 'Activa')) !== 'activa');
            $rsDisabled = $rsLock || $rsOcupada || $rsInactiva;
            $rsSufijo   = $rsOcupada ? ' — contrato vigente' : ($rsInactiva ? ' (Inactiva)' : '');
            $rsEstilo   = $rsOcupada ? 'color:#94a3b8;background:#f8fafc;font-style:italic;'
                        : ($rsInactiva ? 'color:#cbd5e1;background:#f8fafc;font-style:italic;' : '');
          @endphp
          <option value="{{ $rs->id }}"
              data-arl="{{ $rs->arl_nit ?? '' }}"
              data-independiente="{{ $rs->es_independiente ? '1' : '0' }}"
              data-nit="{{ $rs->nit ?? '' }}"
              {{ old('razon_social_id', $contrato->razon_social_id ?? '') == $rs->id ? 'selected' : '' }}
              {{ $rsDisabled ? 'disabled' : '' }}
              style="{{ $rsEstilo }}">
            {{ $rs->razon_social }}{{ $rsSufijo }}
          </option>
          @endforeach
        </select>
        @if($rsLock)
        {{-- Campo oculto para mantener el valor al hacer submit --}}
        <input type="hidden" name="razon_social_id" value="{{ old('razon_social_id', $contrato->razon_social_id ?? '') }}">
        @endif
        </div>

      </div>
      <div>
        <label class="lb">Modalidad</label>
        {{-- Modalidad NO se bloquea con rsLock: cambiarla (ej. IR → Dependiente) no afecta los radicados existentes --}}
        <select name="tipo_modalidad_id" id="sel_modalidad" x-model="tipoModalidadId" @change="onModalidadChange" {!! $prot !!}
            style="{{ $S }}">
          <option value="">-- Modalidad --</option>
          @foreach($tiposModalidad as $tm)
          <option value="{{ $tm->id }}"
              data-independiente="{{ in_array($tm->id, $modalidadesIndependientes) ? '1' : '0' }}"
              {{ old('tipo_modalidad_id', $contrato->tipo_modalidad_id ?? '') == $tm->id ? 'selected' : '' }}>
            {{ $tm->observacion ?: $tm->tipo_modalidad }}
          </option>
          @endforeach
        </select>
        <div id="nota-modalidad-rs" style="display:none;font-size:.6rem;color:#94a3b8;margin-top:.15rem;">Seleccione primero la razón social</div>
      </div>
      {{-- Semanas de tiempo parcial: solo en las modalidades donde los días son
           del contrato (cotizante 76). En el TP de dependientes los días son la
           modalidad misma y este selector no aparece.
           Va aquí, pegado a Modalidad, porque es lo que define el IBC: elegirlo
           después de escribir el salario haría que el salario se reescriba solo. --}}
      <div x-show="esTpDiasContrato" id="div-dias-tp" style="display:none;">
        <label class="lb">Días TP</label>
        {{-- Etiqueta corta para que quepa: el rango real de cada bloque
             (1 a 7, 8 a 14, 15 a 21, más de 21) va en el title. --}}
        <select name="dias_tp_afp" x-model="diasTp" @change="onDiasTpChange" style="{{ $S }}"
            title="Días trabajados en el mes → semanas que se cotizan a pensión:&#10;1 a 7 días = 1 semana&#10;8 a 14 días = 2 semanas&#10;15 a 21 días = 3 semanas&#10;más de 21 días = 4 semanas">
          @php $diasTpSel = old('dias_tp_afp', $contrato->dias_tp_afp ?? 7); @endphp
          <option value="7"  {{ $diasTpSel == 7  ? 'selected' : '' }}>7 días</option>
          <option value="14" {{ $diasTpSel == 14 ? 'selected' : '' }}>14 días</option>
          <option value="21" {{ $diasTpSel == 21 ? 'selected' : '' }}>21 días</option>
          <option value="30" {{ $diasTpSel == 30 ? 'selected' : '' }}>30 días</option>
        </select>
      </div>
      {{-- Independientes, ARL Tipo Y y Exterior pueden cotizar el mes en curso
           en vez del vencido. No es otra modalidad: es cuándo se paga, y por eso
           va como campo propio y no como una modalidad más del catálogo. --}}
      <div x-show="MODALIDADES_MES_ACTUAL.includes(parseInt(tipoModalidadId))" x-cloak>
        <label class="lb">Período</label>
        <select name="paga_mes_actual" x-model="pagaMesActual" style="{{ $S }}" {!! $prot !!}
            title="Mes vencido: en agosto cotiza la planilla de julio.&#10;Mes actual: en agosto cotiza la planilla de agosto.">
          <option value="0">Mes vencido</option>
          <option value="1">Mes actual</option>
        </select>
      </div>
      <div>
        <label class="lb">Plan
          {{-- Badge AFP cuando el cliente puede omitirlo --}}
          @if(!empty($clienteExentoAfp))
          @php
            $motivoExencion = !empty($clientePensionado)
              ? 'Ya está pensionado'
              : ($clienteTipoDoc && in_array($clienteTipoDoc, ['CE','PT','PP','PE','PA'])
                  ? 'Documento: '.$clienteTipoDoc
                  : 'Edad: '.$clienteEdad.' años ('.($clienteGenero === 'M' ? 'hombre' : 'mujer').')');
          @endphp
          {{-- En Seguros no hay pensión de por medio, así que la exención no dice nada. --}}
          <span id="badge-exento-afp" x-show="!esSeguros"
              title="{{ $motivoExencion }}"
              style="background:#ede9fe;color:#7c3aed;font-size:.6rem;font-weight:700;padding:.12rem .45rem;border-radius:20px;margin-left:.4rem;cursor:help;letter-spacing:.02em;">
            {{ !empty($clientePensionado) ? '🚫 Pensionado: sin AFP' : '📌 Puede omitir AFP' }}
          </span>
          @endif
        </label>
        <div class="{{ $rsLock ? 'tip-lock' : '' }}" data-tip="{{ $tipLock }}">
        <select name="plan_id" id="sel_plan" x-model="planId" @change="onPlanChange" {!! $prot !!}
            style="{{ $S }}{{ $rsLock ? 'background:#f1f5f9;color:#1e293b;cursor:not-allowed;opacity:1;' : '' }}"
            {{ $rsLock ? 'disabled' : '' }}>
          <option value="">-- Plan --</option>
          @foreach($planes as $plan)
          {{-- Cliente pensionado: los planes con AFP no se pintan, ni siquiera el que
               trae guardado el contrato — para eso está el aviso de abajo. Con rsLock
               sí se pintan: ahí el plan no se puede cambiar y quitarlo de la lista
               solo lograría que se guardara vacío. --}}
          @continue(!empty($clientePensionado) && !$rsLock && $plan->incluye_pension)
          <option value="{{ $plan->id }}"
              data-eps="{{ $plan->incluye_eps ? '1':'0' }}"
              data-arl="{{ $plan->incluye_arl ? '1':'0' }}"
              data-pen="{{ $plan->incluye_pension ? '1':'0' }}"
              data-caja="{{ $plan->incluye_caja ? '1':'0' }}"
              {{ ($esEdicion && old('plan_id', $contrato->plan_id ?? '') == $plan->id) ? 'selected' : '' }}>
            {{ $plan->nombre }}
          </option>
          @endforeach
        </select>
        @if($rsLock)
        <input type="hidden" name="plan_id" value="{{ old('plan_id', $contrato->plan_id ?? '') }}">
        @endif
        </div>
        {{-- Aviso: solo aparece cuando rsLock y la modalidad nueva es incompatible con el plan --}}
        @if($rsLock)
        <div id="aviso-plan-incompatible"
             style="display:none;margin-top:0.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:0.3rem 0.55rem;font-size:0.68rem;color:#dc2626;font-weight:600;line-height:1.35;">
          ⚠️ La modalidad elegida no incluye el plan actual. Cambie la modalidad a una compatible o contacte al administrador.
        </div>
        @endif
        {{-- Cliente pensionado cuyo contrato quedó con un plan de AFP: la opción
             sigue en la lista para no perderla, pero se avisa que hay que cambiarla. --}}
        @if(!empty($clientePensionado))
        <div id="aviso-plan-pensionado"
             style="display:none;margin-top:0.25rem;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:0.3rem 0.55rem;font-size:0.68rem;color:#b45309;font-weight:600;line-height:1.35;">
          {{-- El texto lo escribe actualizarAvisoPensionado() según el caso. --}}
        </div>
        @endif
        <div id="nota-plan-modalidad" style="display:none;font-size:.6rem;color:#94a3b8;margin-top:.15rem;">Seleccione primero la modalidad</div>

      </div>
      <div>
        <label class="lb">F. Ingreso</label>
        <div class="{{ $rsLock ? 'tip-lock' : '' }}" data-tip="{{ $tipLock }}">
        <input type="date" name="fecha_ingreso" {!! $prot !!}
            value="{{ old('fecha_ingreso', isset($contrato->fecha_ingreso) ? $contrato->fecha_ingreso->format('Y-m-d') : now()->format('Y-m-d')) }}"
            @change="calcularDiasDesde($event.target.value); recalcular()"
            style="{{ $I }}{{ $rsLock ? 'background:#f1f5f9;color:#1e293b;cursor:not-allowed;opacity:1;' : '' }}"
            {{ $rsLock ? 'readonly' : '' }}>
        @if($rsLock)
        <input type="hidden" name="fecha_ingreso" value="{{ old('fecha_ingreso', isset($contrato->fecha_ingreso) ? $contrato->fecha_ingreso->format('Y-m-d') : now()->format('Y-m-d')) }}">
        @endif
        </div>
      </div>

    </div>

    {{-- Fondo de Solidaridad (PSAP, cotizante 33). El grupo decide la tarifa de
         pensión y el formulario muestra lo que se puede revisar desde aquí. La
         inscripción la vuelve a validar Enlace cada mes al liquidar
         (eo.val.2.504), así que el certificado es la prueba inicial, no la única. --}}
    @php
      $fsp = $fondoSolidaridad ?? null;
      $fspEdad = $clienteEdad ?? null;
      $fspEdadOk = $fspEdad !== null && $fsp && $fspEdad >= $fsp['edadMin'] && $fspEdad < $fsp['edadMax'];
      $fspGrupoSel = old('grupo_fondo_solidaridad', $contrato->grupo_fondo_solidaridad ?? '');
    @endphp
    @if($fsp)
    {{-- x-show y la cuadrícula van en elementos distintos: al mostrar, Alpine
         borra el display del estilo y el grid se volvía block (una columna). --}}
    <div x-show="esFondoSolidaridad" x-cloak>
    <div style="margin-top:0.5rem;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:0.55rem 0.7rem;display:grid;grid-template-columns:1fr 1.15fr;gap:0.8rem;">
      <div>
        <label class="lb" style="color:#1d4ed8;">Grupo del Fondo de Solidaridad</label>
        <select name="grupo_fondo_solidaridad" id="sel_grupo_fsp" x-model="grupoFsp" @change="onGrupoFspChange" {!! $prot !!} style="{{ $S }}"
            :required="esFondoSolidaridad">
          <option value="">-- Grupo --</option>
          @foreach($fsp['grupos'] as $clave => $g)
          <option value="{{ $clave }}" {{ $fspGrupoSel === $clave ? 'selected' : '' }}>
            {{ $g['nombre'] }} — pensión {{ rtrim(rtrim(number_format($g['pct_pension'], 1, ',', ''), '0'), ',') }} %
          </option>
          @endforeach
        </select>
        <template x-if="grupoFspDatos">
          <div style="margin-top:0.35rem;font-size:0.7rem;color:#1e3a8a;line-height:1.45;">
            Pensión <b x-text="grupoFspDatos.pct_pension.toString().replace('.', ',') + ' %'"></b> <span x-text="fmt(grupoFspDatos.pension)"></span>
            <span x-show="!esPlanSoloAfp">+ salud 12,5 % <span x-text="fmt(grupoFspDatos.salud)"></span></span>
            = <b x-text="fmt(esPlanSoloAfp ? grupoFspDatos.pension : grupoFspDatos.total)"></b> al mes.
            <span style="color:#64748b;">El Estado subsidia el <span x-text="grupoFspDatos.subsidio"></span> % de la pensión, sobre un salario mínimo.</span>
          </div>
        </template>
        <div x-show="esPlanSoloAfp" x-cloak
             style="margin-top:0.35rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:0.3rem 0.5rem;font-size:0.66rem;color:#b91c1c;font-weight:600;line-height:1.35;">
          ⚠️ Solo AFP: hoy el operador rechaza al cotizante 33 sin salud («está obligado cotizar a Salud»). Este contrato no va a liquidar por planilla.
        </div>
      </div>
      <div style="font-size:0.7rem;line-height:1.6;color:#334155;">
        <div style="font-weight:700;color:#1d4ed8;font-size:0.66rem;letter-spacing:0.03em;text-transform:uppercase;">Requisitos (Decreto 543 de 2026)</div>
        <div>
          @if($fspEdad === null)
            <span style="color:#b45309;">⚠️</span> Edad: el cliente no tiene fecha de nacimiento. Debe tener entre {{ $fsp['edadMin'] }} y {{ $fsp['edadMax'] }} años.
          @elseif($fspEdadOk)
            <span style="color:#16a34a;">✔</span> Edad: {{ $fspEdad }} años (entre {{ $fsp['edadMin'] }} y {{ $fsp['edadMax'] }}).
          @else
            <span style="color:#dc2626;">✖</span> Edad: {{ $fspEdad }} años. El programa exige entre {{ $fsp['edadMin'] }} y {{ $fsp['edadMax'] }}.
          @endif
        </div>
        <div>
          <span x-show="pensionSel == {{ $fsp['colpensionesId'] }}" style="color:#16a34a;">✔ Pensión en Colpensiones.</span>
          <span x-show="pensionSel != {{ $fsp['colpensionesId'] }}" style="color:#dc2626;">✖ La pensión tiene que ser Colpensiones: el programa no subsidia fondos privados.</span>
        </div>
        <div>
          <template x-if="certificadoFsp">
            <span><span style="color:#16a34a;">✔</span> Certificado de inscripción subido el <span x-text="certificadoFsp.fecha"></span>
              <a x-show="certificadoFsp.url" :href="certificadoFsp.url" target="_blank" style="color:#1d4ed8;">(ver)</a>.</span>
          </template>
          <template x-if="!certificadoFsp">
            <span><span style="color:#b45309;">⚠️</span> Falta el certificado de inscripción.
              {{-- Con la extensión BryNex Portales la consulta se abre con la cédula
                   escrita y el PDF llega solo después del captcha; sin ella, el enlace
                   abre la página vacía como siempre. --}}
              <a href="{{ $fsp['urlConsultaCertificado'] }}" target="_blank" rel="noopener" style="color:#1d4ed8;"
                 @click="traerCertificadoFsp($event)"
                 x-text="fspTrayendo ? 'Esperando el captcha…' : 'Traer del Fondo ↗'"></a>
              @if($fsp['urlSubirCertificado'])
              · <label style="color:#1d4ed8;cursor:pointer;text-decoration:underline;">
                  <span x-text="subiendoCertificadoFsp ? 'Subiendo…' : 'Subir PDF'"></span>
                  <input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" @change="subirCertificadoFsp($event)">
                </label>
              @endif
            </span>
          </template>
        </div>
        <div x-show="fspTrayendo" x-cloak style="color:#1d4ed8;">
          ⏳ Marca «No soy un robot» en la pestaña del Fondo: el certificado se guarda solo.
          <a href="#" @click.prevent="fspTrayendo = false" style="color:#64748b;">cancelar</a>
        </div>
        <div x-show="fspMensaje" x-cloak style="color:#b91c1c;" x-text="fspMensaje"></div>
        <div style="color:#64748b;">ℹ️ Semanas: mínimo 300, se ven en la historia laboral de Colpensiones. Enlace confirma la inscripción cada mes al liquidar.</div>
      </div>
    </div>
    </div>
    @endif

    <div style="display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:0.5rem;margin-top:0.5rem;align-items:end;">
      <div>
        <label class="lb">Motivo Afiliacion</label>
        <select name="motivo_afiliacion_id" style="{{ $S }}">
          <option value="">--</option>
          @foreach($motivosAfiliacion as $m)
          <option value="{{ $m->id }}" {{ old('motivo_afiliacion_id', $contrato->motivo_afiliacion_id ?? '') == $m->id ? 'selected' : '' }}>{{ $m->nombre }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="lb">Cargo / Ocupacion</label>
        {{-- Sigue siendo un campo de texto —se puede escribir un cargo nuevo—
             pero sugiere los de la razón social. Al elegir uno del catálogo se
             ajusta solo el nivel de riesgo, que es de donde sale el centro de
             trabajo al afiliar en ARL Sura. --}}
        <div style="position:relative">
            <input type="text" name="cargo" id="inp_cargo" autocomplete="off" role="combobox"
                   aria-expanded="false" aria-controls="lista_cargos"
                   value="{{ old('cargo', $contrato->cargo ?? '') }}" style="{{ $I }}"
                   placeholder="Escribe o elige uno de la lista">
            <div id="lista_cargos" role="listbox" style="display:none;position:absolute;z-index:60;left:0;right:0;
                 max-height:260px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:8px;
                 box-shadow:0 8px 24px rgba(15,23,42,.14);margin-top:2px;"></div>
        </div>
      </div>
      {{-- Operador Planilla: visible SOLO cuando la RS es independiente, sin importar si hay ARL --}}
      <div id="panel-operador-planilla" style="display:none;">
        <label class="lb" style="color:#1d4ed8;">&#x1F4B3; Operador Planilla</label>
        <select name="operador_planilla_id"
                style="{{ $S }}border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8;font-weight:600;">
          <option value="">&mdash; Seleccione &mdash;</option>
          @foreach($operadoresPlanilla ?? [] as $op)
          <option value="{{ $op->id }}"
              {{ old('operador_planilla_id', $clienteOperadorId ?? '') == $op->id ? 'selected' : '' }}>
            {{ $op->nombre }}{{ $op->codigo_ni ? ' ('.$op->codigo_ni.')' : '' }}
          </option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="lb">Envio Planilla</label>
        <select name="envio_planilla" style="{{ $S }}">
          <option value="">--</option>
          @foreach(['Correo','WhatsApp','Fisica','Web','Otro'] as $ep)
          <option value="{{ $ep }}" {{ old('envio_planilla', $contrato->envio_planilla ?? '') === $ep ? 'selected' : '' }}>{{ $ep }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </div>

  {{-- Panel 2: Entidades — no existe en la modalidad Seguros: a esa persona no se le
       afilia a EPS, ARL, pensión ni caja, solo se le vende el seguro. --}}
  <div class="cp" x-show="!esSeguros">
    <div class="pt" style="color:#16a34a;">&#127963; Entidades</div>
    @php
      // Radicados indexados por tipo para mostrar estado en cada entidad
      $rPT = $radicadosPorTipo ?? collect();
      // Helper: badge HTML según estado del radicado
      // $conNumero = false deja el número del radicado solo en el tooltip: en la
      // EPS es un código largo (SAT 014CC…) que estiraba el campo.
      $badgeEstado = function(?object $rad, bool $conNumero = true): string {
        if (!$rad) return '';
        $cfg = [
          'pendiente' => ['bg'=>'#fef3c7','txt'=>'#92400e','icono'=>'⏳','label'=>'Pendiente'],
          'tramite'   => ['bg'=>'#dbeafe','txt'=>'#1e40af','icono'=>'🔵','label'=>'En Trámite'],
          'traslado'  => ['bg'=>'#ffedd5','txt'=>'#9a3412','icono'=>'🔄','label'=>'Traslado'],
          'error'     => ['bg'=>'#fee2e2','txt'=>'#991b1b','icono'=>'❌','label'=>'Error'],
          'ok'        => ['bg'=>'#dcfce7','txt'=>'#166534','icono'=>'✅','label'=>'Afiliado OK'],
        ];
        $c = $cfg[$rad->estado] ?? ['bg'=>'#f1f5f9','txt'=>'#475569','icono'=>'❓','label'=>$rad->estado];
        // OK confirmado por la entidad (portal/API): verde fuerte, como en Afiliaciones.
        if ($rad instanceof \App\Models\Radicado && $rad->esConfirmadoPorEntidad()) {
          $c = ['bg'=>'#15803d','txt'=>'#fff','icono'=>'✔✔','label'=>'Confirmado '.(\App\Models\Radicado::CONFIRMADORES[$rad->confirmado_por] ?? '')];
        }
        $num = $rad->numero_radicado ? $rad->numero_radicado : '';
        return '<span class="chip-ss" style="background:'.$c['bg'].';color:'.$c['txt'].';"'
          .($num && ! $conNumero ? ' title="'.e($num).'"' : '').'>'
          .$c['icono'].' '.$c['label'].($num && $conNumero ? ' · '.e($num) : '').'</span>';
      };
    @endphp
    <div style="display:grid;grid-template-columns:1.3fr 1.3fr 1.4fr 60px 1fr;gap:0.5rem;align-items:start;">
      <div>
        <label class="lb">EPS</label>
        <select name="eps_id" id="sel_eps" style="{{ $S }}">
          <option value="">-- Ninguna --</option>
          @foreach($epsList as $eps)
          <option value="{{ $eps->id }}" {{ $epsDefault == $eps->id ? 'selected' : '' }}>{{ $eps->nombre }}</option>
          @endforeach
        </select>
        <div id="badge-area-eps" data-saved="{{ ($esEdicion && $contrato->eps_id) ? '1' : '0' }}">
        @if($esEdicion && $contrato->eps_id && collect($epsList)->contains('id', (int)$contrato->eps_id))
        {!! $badgeEstado($rPT->get('eps'), false) !!}
        @endif
        </div>
      </div>
      <div>
        <label class="lb">AFP / Pension</label>
        <select name="pension_id" id="sel_pen" style="{{ $S }}">
          <option value="">-- Ninguna --</option>
          @foreach($pensiones as $pen)
          <option value="{{ $pen->id }}" {{ $pensionDefault == $pen->id ? 'selected' : '' }}>{{ $pen->razon_social }}</option>
          @endforeach
        </select>
        <div id="badge-area-pen" data-saved="{{ ($esEdicion && $contrato->pension_id) ? '1' : '0' }}">
        @if($esEdicion && $contrato->pension_id && collect($pensiones)->contains('id', (int)$contrato->pension_id))
        {!! $badgeEstado($rPT->get('pension')) !!}
        @elseif($esEdicion && !$contrato->pension_id && ($planContrato?->incluye_pension ?? false))
        <span class="chip-ss" style="background:#fef3c7;color:#92400e;">
          ⚠️ Sin AFP guardada — seleccione y guarde
        </span>
        @endif
        </div>
      </div>
      <div>
        <label class="lb">ARL <span id="lbl_arl_lock" style="color:#94a3b8;font-weight:400;font-size:0.63rem;">(de la R.Social)</span></label>
        <select name="arl_id" id="sel_arl" style="{{ $S }}">
          <option value="">-- Ninguna --</option>
          @foreach($arlList as $arl)
          <option value="{{ $arl->id }}" data-nit="{{ $arl->nit ?? '' }}"
              {{ $arlDefault == $arl->id ? 'selected' : '' }}>{{ $arl->nombre_arl }}</option>
          @endforeach
        </select>
        @if($esEdicion && $arlDefault && collect($arlList)->contains('id', (int)$arlDefault))
        {!! $badgeEstado($rPT->get('arl')) !!}
        @endif
      </div>
      <div>
        <label class="lb">N.ARL</label>
        <select name="n_arl" x-model="nivelArl" @change="recalcular" style="{{ $S }}text-align:center;">
          @for($i=1;$i<=5;$i++)
          <option value="{{ $i }}" {{ old('n_arl', $contrato->n_arl ?? 1) == $i ? 'selected' : '' }}>{{ $i }}</option>
          @endfor
        </select>
        @if($puedeCertificadoArl)
        {{-- Pegado al nivel de riesgo porque el certificado es justo lo que lo
             acredita: dice con qué clase y qué tasa quedó afiliada la persona. --}}
        {{-- Solo el ícono: la columna del nivel es angosta y cualquier rótulo se
             desborda. El contador de segundos aparece dentro mientras baja, que
             es cuando de verdad hace falta texto.

             Las medidas salen de `.chip-ss`, la misma clase del badge
             "Afiliado OK" que queda a su izquierda: van uno al lado del otro en
             la misma fila y cualquier diferencia de alto tuerce el renglón.
             Aquí solo se pone el color. --}}
        <button type="button" id="btn-cert-arl" class="chip-ss" onclick="descargarCertificadoArl(this)"
            title="Bajar del portal de Sura el certificado de afiliación y el carné al día"
            style="background:#dcfce7;color:#166534;cursor:pointer;transition:background .15s;"
            onmouseover="this.style.background='#bbf7d0'" onmouseout="this.style.background='#dcfce7'">
          <svg width="10" height="12" viewBox="0 0 12 14" fill="none" style="flex:none" aria-hidden="true">
            <path d="M1 1.5A.5.5 0 0 1 1.5 1H7l4 4v7.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5v-11Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
            <path d="M7 1v4h4" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
            <path d="M6 7.6v3.2m0 0L4.7 9.6M6 10.8l1.3-1.2" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span id="btn-cert-arl-txt"></span>
        </button>
        @endif
      </div>
      <div>
        <label class="lb">Caja Compensacion</label>
        <select name="caja_id" id="sel_caja" style="{{ $S }}">
          <option value="">-- Ninguna --</option>
          @php
            $cajasLocales = $cajas->where('es_local', true)->values();
            $cajasResto   = $cajas->where('es_local', false)->values();
          @endphp
          @if($cajasLocales->isNotEmpty())
          <optgroup label="📍 Tu departamento">
            @foreach($cajasLocales as $caja)
            <option value="{{ $caja->id }}"
                {{ old('caja_id', $contrato->caja_id ?? '') == $caja->id ? 'selected' : '' }}
                style="font-weight:600;">
              ★ {{ $caja->nombre }}
            </option>
            @endforeach
          </optgroup>
          <optgroup label="─── Otras cajas ───────────────">
          @else
          @php $cajasResto = $cajas; @endphp
          @endif
          @foreach($cajasResto as $caja)
          <option value="{{ $caja->id }}"
              {{ old('caja_id', $contrato->caja_id ?? '') == $caja->id ? 'selected' : '' }}>
            {{ $caja->nombre }}
          </option>
          @endforeach
          @if($cajasLocales->isNotEmpty())
          </optgroup>
          @endif
        </select>
        @if($esEdicion && $contrato->caja_id && collect($cajas)->contains('id', (int)$contrato->caja_id))
        {!! $badgeEstado($rPT->get('caja')) !!}
        @endif
        <div id="hint-caja-rs" style="display:none;font-size:0.66rem;color:#475569;margin-top:0.2rem;line-height:1.35;"></div>
      </div>
    </div>
    <div x-show="mostrarModoArl" id="panel-modo-arl" style="display:none;margin-top:0.5rem;">
      <div style="display:grid;grid-template-columns:200px 1fr;gap:0.5rem;align-items:end;max-width:560px;">
        {{-- Col 1: Modo ARL --}}
        <div>
          <label class="lb">Modo ARL</label>
          <select name="arl_modo" id="sel_arl_modo" style="{{ $S }}" onchange="onArlModoChange(this)">
            <option value="">--</option>
            <option value="razon_social"  {{ old('arl_modo', $contrato->arl_modo ?? '') === 'razon_social'  ? 'selected' : '' }}>Por Razon Social</option>
            <option value="independiente" {{ old('arl_modo', $contrato->arl_modo ?? '') === 'independiente' ? 'selected' : '' }}>Independiente</option>
          </select>
        </div>

        {{-- Col 2: Selector de RS (cuando razon_social) o cédula readonly (cuando independiente) --}}
        <div>
          {{-- Panel "Por Razón Social": select de razones sociales --}}
          <div id="panel_arl_rs" style="display:none;">
            <label class="lb">Razón Social ARL
              <span style="font-weight:400;color:#64748b;font-size:0.6rem;margin-left:3px;">bajo cuya RS se cotiza la ARL</span>
            </label>
            <select id="sel_arl_rs_cotizante" style="{{ $S }}" onchange="onArlRsChange(this)">
              <option value="">-- Seleccione RS --</option>
              @foreach($razonesSociales as $rs)
              <option value="{{ $rs->id }}"
                  data-nombre="{{ $rs->razon_social }}"
                  {{ old('arl_nit_cotizante', $contrato->arl_nit_cotizante ?? '') == $rs->id ? 'selected' : '' }}>
                {{ $rs->razon_social }} ({{ number_format($rs->id, 0, '', '.') }})
              </option>
              @endforeach
            </select>
          </div>

          {{-- Panel "Independiente": solo cédula cotizante (el Operador Planilla está en su propio panel) --}}
          <div id="panel_arl_cedula" style="display:none;">
            <div>
              <label class="lb">Cédula Cotizante
                <span style="font-weight:400;color:#64748b;font-size:0.6rem;margin-left:3px;">el cliente cotiza por sí mismo</span>
              </label>
              {{-- La columna la dimensiona el selector de razón social, que
                   necesita el ancho para nombres largos. Una cédula son diez
                   dígitos: sin tope se estiraba a todo lo ancho del panel. --}}
              <input type="text" id="disp_arl_cedula" readonly
                  style="{{ $I }}max-width:190px;background:#f8fafc;color:#475569;font-family:monospace;cursor:not-allowed;"
                  value="{{ old('cedula', $cliente->cedula ?? $contrato->cedula ?? '') }}">
            </div>
          </div>
        </div>
      </div>

      {{-- Campo oculto que guarda el bigInteger en BD --}}
      <input type="hidden" name="arl_nit_cotizante" id="inp_arl_nit"
          value="{{ old('arl_nit_cotizante', $contrato->arl_nit_cotizante ?? '') }}">
    </div>
  </div>

  {{-- Panel 3: Salario + Asesor + Tarifas --}}
  <div class="cp">
    <div class="pt" style="color:#7c3aed;">&#128176; Salario y Tarifas</div>

    {{-- Fila 1: Salario + IBC(indep) + Asesor + Encargado --}}
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1.4fr;gap:0.5rem;margin-bottom:0.5rem;">
      <div x-show="!esSeguros">
        <label class="lb">Salario Mensual</label>
        <div class="{{ $ecoLock ? 'tip-lock' : '' }}" data-tip="{{ $tipEco }}">
        <input type="text" inputmode="numeric" name="salario" id="inp_salario" class="campo-money"
            @input="onSalarioChange"
            value="{{ number_format($defSalario, 0, '', '.') }}"
            style="{{ $M }}{{ $ecoLock ? 'background:#f1f5f9;color:#1e293b;cursor:not-allowed;' : '' }}"
            data-raw="{{ $defSalario }}" {{ $ecoLock ? 'readonly' : '' }}
            {{-- Fondo de Solidaridad: el subsidio es sobre un mínimo, ni más ni menos. --}}
            :readonly="esFondoSolidaridad || {{ $ecoLock ? 'true' : 'false' }}">
        @if($ecoLock)
        <input type="hidden" name="salario" value="{{ $ecoSalarioBd }}">
        @endif
        </div>
      </div>
      {{-- En Tiempo Parcial Independiente el IBC no se escribe: lo fija el bloque
           de semanas (¼, ½, ¾ o un mínimo completo), así que el campo solo
           mostraría un número que el usuario no puede cambiar. Se oculta con
           x-show —no se quita del DOM— para que siga viajando en el submit. --}}
      <div x-show="esIndependiente && !esTpDiasContrato && !esFondoSolidaridad" style="display:none;">
        <label class="lb">IBC <span style="color:#f59e0b;font-size:0.63rem;" x-text="ibcSugFmt ? 'sug:'+ibcSugFmt : ''"></span></label>
        {{-- type=text y no number: un <input type=number> no acepta los puntos de miles.
             El valor real vive en dataset.raw, y el submit de .campo-money limpia el formato. --}}
        <div class="{{ $ecoLock ? 'tip-lock' : '' }}" data-tip="{{ $tipEco }}">
        <input type="text" inputmode="numeric" name="ibc" id="inp_ibc" class="campo-money"
            @input="onIbcInput" @change="onIbcChange"
            value="{{ number_format($defIbc, 0, '', '.') }}"
            data-raw="{{ $defIbc }}" {{ $ecoLock ? 'readonly' : '' }}
            style="width:100%;padding:0.38rem 0.5rem;border:1px solid #f59e0b;border-radius:6px;font-size:0.82rem;font-family:monospace;background:{{ $ecoLock ? '#f1f5f9;cursor:not-allowed' : '#fffbeb' }};box-sizing:border-box;">
        @if($ecoLock)
        <input type="hidden" name="ibc" value="{{ $ecoIbcBd }}">
        @endif
        </div>
      </div>
      <div x-show="esIndependiente" id="div-pct-caja" style="display:none;">
        <label class="lb">% Caja</label>
        <select name="porcentaje_caja" x-model="pctCaja" @change="recalcular" style="{{ $S }}">
          <option value="2" {{ old('porcentaje_caja', $contrato->porcentaje_caja ?? 2) == 2 ? 'selected' : '' }}>2% Normal</option>
          <option value="0.6" {{ old('porcentaje_caja', $contrato->porcentaje_caja ?? 2) == 0.6 ? 'selected' : '' }}>0.6% Reducido</option>
        </select>
      </div>
      <div>
        <label class="lb">Asesor</label>
        <select name="asesor_id" id="sel_asesor" style="{{ $S }}" onchange="onAsesorChange(this)">
          <option value="">-- Sin asesor --</option>
          @foreach($asesores as $as)
          <option value="{{ $as->id }}"
              data-admon="{{ $as->comision_admon_valor ?? 0 }}"
              {{ old('asesor_id', $contrato->asesor_id ?? '') == $as->id ? 'selected' : '' }}>
            {{ $as->nombre }}
          </option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="lb">Encargado Afiliacion</label>
        <div class="{{ $ecoLock ? 'tip-lock' : '' }}" data-tip="{{ $tipEco }}">
        <select name="encargado_id" {!! $prot !!} style="{{ $S }}{{ $ecoLock ? 'background:#f1f5f9;color:#1e293b;cursor:not-allowed;opacity:1;' : '' }}"
            {{ $ecoLock ? 'disabled' : '' }}>
          <option value="">-- Responsable --</option>
          @foreach($usuarios as $usr)
          <option value="{{ $usr->id }}" {{ $defEncargado == $usr->id ? 'selected' : '' }}>{{ $usr->nombre }}</option>
          @endforeach
        </select>
        @if($ecoLock)
        <input type="hidden" name="encargado_id" value="{{ $defEncargado }}">
        @endif
        </div>
      </div>
    </div>

    {{-- Fila 2: Admon + Admon Asesor + Afiliación (empresa | asesor) + Seguro.
         El costo de afiliación ya no se escribe directo: se arma sumando las dos partes,
         y viaja en el campo oculto costo_afiliacion, que es el que sigue guardando la BD. --}}
    {{-- En Seguros solo quedan las dos casillas del seguro: administración y afiliación
         no se cobran, y dejarlas vacías empujaba el campo Seguro a una segunda fila. --}}
    {{-- Y al escoger un plan de seguro aparece "Seguro $", que es el sexto campo:
         con cinco columnas se caía solo a una segunda fila. --}}
    <div :style="esSeguros
            ? 'display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;'
            : (seguroId || !HAY_CATALOGO_SEGUROS || seguro > 0
                ? 'display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1.25fr 0.85fr;gap:0.5rem;'
                : 'display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr;gap:0.5rem;')"
         style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr;gap:0.5rem;">
      <div x-show="!esSeguros">
        <label class="lb">Admon Mensual $</label>
        <input type="text" inputmode="numeric" name="administracion" id="inp_admon" class="campo-money"
            @input="recalcular"
            value="{{ number_format($defAdmon, 0, '', '.') }}" style="{{ $M }}"
            data-raw="{{ $defAdmon }}">
      </div>
      <div x-show="!esSeguros">
        <label class="lb">Admon Asesor $</label>
        <input type="text" inputmode="numeric" name="admon_asesor" id="inp_admon_asesor" class="campo-money"
            value="{{ number_format($defAdmonAsesor, 0, '', '.') }}" style="{{ $M }}"
            data-raw="{{ $defAdmonAsesor }}">
      </div>
      <div x-show="!esSeguros">
        <label class="lb">Afiliacion Empresa $</label>
        <input type="text" inputmode="numeric" id="inp_afiliacion_empresa"
            oninput="repartoAfiliacion('empresa')"
            value="{{ number_format($defAfilEmpresa, 0, '', '.') }}" style="{{ $M }}">
      </div>
      <div x-show="!esSeguros">
        <label class="lb">Afiliacion Asesor $</label>
        {{-- Sin asesor va en 0. Solo queda vacío en un contrato viejo que SÍ tiene asesor y
             nunca pasó por el tarifario: ese vacío es lo que mantiene su factura en la regla
             anterior, y convertirlo en 0 le quitaría la comisión sin que nadie lo note.
             Por eso el campo no es .campo-money, que fuerza el 0. --}}
        <input type="text" inputmode="numeric" name="afiliacion_asesor" id="inp_afiliacion_asesor"
            oninput="repartoAfiliacion('asesor')" placeholder="sin tarifario"
            value="{{ $defAfilAsesorVista }}" style="{{ $M }}">
      </div>
      @if(($segurosCatalogo ?? collect())->isNotEmpty())
      {{-- Cuál seguro se le vendió. El valor del catálogo se copia al campo de al lado,
           que sigue siendo el que manda: el contrato guarda el precio con el que se vendió. --}}
      <div>
        <label class="lb">Plan de seguro</label>
        <select name="seguro_id" id="sel_seguro" style="{{ $S }}" {!! $prot !!}
            x-model="seguroId" onchange="aplicarSeguroCatalogo(this)">
          <option value="">-- Ninguno --</option>
          @foreach($segurosCatalogo as $sg)
          {{-- data-nombre / data-full: en la lista se ve el precio para poder
               escoger, pero al quedar seleccionado el campo muestra solo el
               nombre — el valor ya está en la casilla de al lado y con los dos
               juntos el texto no cabe. Ver etiquetaSeguroSeleccionado(). --}}
          <option value="{{ $sg->id }}" data-valor="{{ (int) $sg->valor }}"
              data-nombre="{{ $sg->nombre }}"
              data-full="{{ $sg->nombre }} · ${{ number_format($sg->valor, 0, ',', '.') }}"
              {{ (int) old('seguro_id', $contrato->seguro_id ?? 0) === (int) $sg->id ? 'selected' : '' }}>
            {{ $sg->nombre }} · ${{ number_format($sg->valor, 0, ',', '.') }}
          </option>
          @endforeach
        </select>
      </div>
      @endif
      {{-- El valor solo aparece cuando ya se escogió un plan de seguro: antes de
           eso es una casilla en cero que no significa nada.
           Se mantiene visible si el aliado no tiene catálogo (no hay plan que
           escoger, el valor se escribe a mano) o si el contrato ya trae un
           seguro cobrado, para no esconderle a nadie una plata que ya existe. --}}
      <div x-show="seguroId || !HAY_CATALOGO_SEGUROS || seguro > 0">
        <label class="lb">Seguro $</label>
        <input type="text" inputmode="numeric" name="seguro" id="inp_seguro" class="campo-money"
            @input="recalcular"
            value="{{ number_format($defSeguro, 0, '', '.') }}" style="{{ $M }}"
            data-raw="{{ $defSeguro }}">
      </div>
    </div>

    {{-- Costo total de la afiliación = empresa + asesor. Lo mantiene repartoAfiliacion();
         media vista y el cotizador leen #inp_costo, por eso conserva id, name y clase. --}}
    <input type="hidden" name="costo_afiliacion" id="inp_costo" class="campo-money"
        value="{{ $defCosto }}" data-raw="{{ $defCosto }}">

    {{-- Único aviso que queda: renovación de Ingreso-Retiro / Gestión ARL, que no se
         deduce de ninguna casilla. Lo pinta pintarAvisoTarifa(). --}}
    <div id="aviso_tarifa_asesor" style="display:none;margin-top:0.5rem;padding:0.45rem 0.65rem;
         background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:0.72rem;color:#78350f;line-height:1.5;"></div>
  </div>

  {{-- Panel 4: Observacion --}}
  <div class="cp" style="padding:0.65rem 0.9rem;">
    <label class="lb">Observacion</label>
    <input type="text" name="observacion" value="{{ old('observacion', $contrato->observacion ?? '') }}"
        style="{{ $I }}" placeholder="Nota general del contrato...">
  </div>

  {{-- Guardar --}}
  <div style="display:flex;justify-content:flex-end;">
    <button type="submit" id="btn-guardar-contrato"
        style="padding:0.6rem 2.2rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;border-radius:9px;color:#fff;font-size:0.9rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,0.4);transition:opacity .2s;display:inline-flex;align-items:center;gap:.5rem;">
        <span id="btn-guardar-ico">&#128190;</span>
        <span id="btn-guardar-txt">{{ $esEdicion ? 'Actualizar Contrato' : 'Crear Contrato' }}</span>
    </button>
  </div>

</div>{{-- /col izq --}}

{{-- ══ COLUMNA DERECHA: Cotizador + Botones + Radicados ══ --}}
<div style="position:sticky;top:1rem;display:flex;flex-direction:column;gap:0.7rem;">

  {{-- Cotizador --}}
  <div style="background:linear-gradient(160deg,#0f172a,#1e3a5f);border-radius:14px;padding:1rem 1.15rem;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,0.35);">
    <div style="font-weight:700;font-size:0.82rem;margin-bottom:0.6rem;display:flex;align-items:center;gap:0.5rem;">
      &#129518; Cotizacion
      <span style="font-size:0.63rem;background:rgba(255,255,255,0.1);padding:0.1rem 0.45rem;border-radius:999px;font-weight:400;" x-text="planNombre || 'Sin plan'"></span>
    </div>
    {{-- IBC y días: no existen cuando lo único que se cobra es el seguro. --}}
    <div x-show="!esSeguros" style="background:rgba(255,255,255,0.06);border-radius:6px;padding:0.4rem 0.6rem;margin-bottom:0.55rem;font-size:0.72rem;display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
      <div style="display:flex;align-items:center;gap:0.35rem;">
        <span style="color:#94a3b8;">IBC: <strong x-text="fmt(ibc)">$0</strong></span>
        {{-- Badge Tiempo Parcial --}}
        <span x-show="esTiempoParcial" style="display:none;background:#f59e0b;color:#000;font-size:0.58rem;font-weight:800;padding:.1rem .38rem;border-radius:999px;">⏱ T.PARCIAL</span>
      </div>
      {{-- Selector de días: solo visible en modalidades normales --}}
      <div style="display:flex;align-items:center;gap:0.3rem;" x-show="!esTiempoParcial">
        <span style="color:#94a3b8;font-size:0.67rem;white-space:nowrap;">Días</span>
        <select id="sel_dias_cotizar" x-model="diasCotizar"
            @change="recalcular()"
            style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.25);border-radius:5px;color:#fff;font-size:0.7rem;padding:0.15rem 0.25rem;cursor:pointer;width:52px;">
          {{-- Desde 0: es lo que cotiza un mes de afiliación pura o un contrato de
               Gestión ARL. Sin esa opción el select quedaba en blanco al ponerle 0. --}}
          @for($d = 0; $d <= 30; $d++)
          <option value="{{ $d }}" style="color:#000;">{{ $d }}</option>
          @endfor
        </select>
      </div>
    </div>
    <div style="font-size:0.75rem;">
      {{-- EPS: oculta en Tiempo Parcial --}}
      <div class="cr" x-show="!esTiempoParcial && !esSeguros"><span style="color:#93c5fd;">EPS <span class="cp2" x-text="'('+pctEps+'%)'"></span></span><strong x-text="fmt(result.eps)">$0</strong></div>
      {{-- ARL --}}
      <div class="cr" x-show="!esSeguros">
        <span style="color:#6ee7b7;">ARL <span class="cp2" x-text="'N'+nivelArl+' ('+pctArl+'%)'"></span>
          <template x-if="esTiempoParcial">
            <span style="color:#f59e0b;font-size:.58rem;font-weight:700;"> · 30d</span>
          </template>
        </span>
        <strong x-text="fmt(result.arl)">$0</strong>
      </div>
      {{-- PENSIÓN --}}
      <div class="cr" x-show="!esSeguros">
        <span style="color:#c4b5fd;">PENSIÓN <span class="cp2" x-text="'('+pctPen+'%)'"></span>
          <template x-if="esTiempoParcial && diasAfp">
            <span style="color:#f59e0b;font-size:.58rem;font-weight:700;" x-text="' · '+diasAfp+'d'"></span>
          </template>
        </span>
        <strong x-text="fmt(result.pen)">$0</strong>
      </div>
      {{-- CAJA --}}
      <div class="cr" x-show="!esSeguros">
        <span style="color:#fcd34d;">CAJA <span class="cp2" x-text="'('+pctCajaCalc+'%)'"></span>
          <template x-if="esTiempoParcial && diasCaja">
            <span style="color:#f59e0b;font-size:.58rem;font-weight:700;" x-text="' · '+diasCaja+'d'"></span>
          </template>
        </span>
        <strong x-text="fmt(result.caja)">$0</strong>
      </div>
      {{-- S. Social --}}
      <div class="cr" x-show="!esSeguros" style="border-bottom:2px solid rgba(255,255,255,0.18);padding-bottom:0.35rem;margin-bottom:0.1rem;font-weight:700;">
        <span>S. Social
          <span class="cp2" x-show="!esTiempoParcial && diasCotizar < 30" x-text="'('+diasCotizar+'d)'"></span>
        </span>
        <span x-text="fmt(result.ss)">$0</span>
      </div>
      <div class="cr"><span style="color:#94a3b8;">Seguro <span class="cp2" style="color:#34d399;" x-show="!esTiempoParcial && diasCotizar < 30">(completo)</span></span><strong x-text="fmt(result.seguro)">$0</strong></div>
      <div class="cr" x-show="!esSeguros"><span style="color:#94a3b8;">Admon <span class="cp2" style="color:#34d399;" x-show="!esTiempoParcial && diasCotizar < 30">(completo)</span></span><strong x-text="fmt(result.admon)">$0</strong></div>
      <div x-show="result.iva > 0" class="cr"><span style="color:#fca5a5;">IVA 19%</span><strong x-text="fmt(result.iva)">$0</strong></div>
      <div class="cr" style="font-size:0.95rem;font-weight:800;padding-top:0.4rem;"><span>TOTAL</span><span style="color:#34d399;" x-text="fmt(result.total)">$0</span></div>
    </div>
  </div>

  {{-- Botones de Accion --}}
  @if($esEdicion)
  <div class="cp" style="padding:0.7rem 0.85rem;">
    <div class="pt" style="color:#475569;">&#9889; Acciones</div>
    <div style="display:flex;flex-direction:column;gap:0.4rem;">
      <a onclick="abrirModalFacturarContrato()" style="display:flex;align-items:center;gap:0.55rem;padding:0.5rem 0.75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;color:#15803d;text-decoration:none;font-size:0.8rem;font-weight:600;cursor:pointer;">
        &#129534; Facturar
      </a>
      {{-- Botón Ingreso-Retiro: solo para tipo_modalidad_id=12, vigente, y fecha_ingreso del MES ANTERIOR --}}
      @php
        $esMesAnteriorIR = $contrato->fecha_ingreso &&
            !($contrato->fecha_ingreso->month === now()->month && $contrato->fecha_ingreso->year === now()->year);
      @endphp
      @if($contrato->estaVigente() && (int)$contrato->tipo_modalidad_id === 12 && $esMesAnteriorIR)
      <button type="button" onclick="abrirModalDuplicarIR()"
          id="btn-duplicar-ir"
          style="display:flex;align-items:center;gap:0.55rem;padding:0.5rem 0.75rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#c2410c;font-size:0.8rem;font-weight:600;cursor:pointer;width:100%;text-align:left;">
        &#128260; Duplicar Contrato (I.Retiro)
      </button>
      @endif
      <button type="button" onclick="abrirHistorialPagos()" style="display:flex;align-items:center;gap:0.55rem;padding:0.5rem 0.75rem;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;color:#6d28d9;text-decoration:none;font-size:0.8rem;font-weight:600;cursor:pointer;width:100%;text-align:left;">
        &#128202; Historial Pagos
      </button>
      <button type="button" onclick="document.getElementById('modal-radicados').style.display='flex'"
          style="display:flex;align-items:center;gap:0.55rem;padding:0.5rem 0.75rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;color:#0369a1;font-size:0.8rem;font-weight:600;cursor:pointer;width:100%;text-align:left;">
        &#128193; Ver Radicados
        @if($esEdicion && $contrato->radicados->count() > 0)
        <span style="margin-left:auto;background:#0369a1;color:#fff;border-radius:999px;padding:0 0.45rem;font-size:0.65rem;">{{ $contrato->radicados->count() }}</span>
        @endif
      </button>
      @if($contrato->estaVigente())
      <button type="button"
          onclick="document.getElementById('modal-retiro').style.display='flex';mrInitSelects();mrSetDefault();"
          style="display:flex;align-items:center;gap:0.55rem;padding:0.5rem 0.75rem;background:#fff5f5;border:1px solid #fecaca;border-radius:8px;color:#dc2626;font-size:0.8rem;font-weight:600;cursor:pointer;width:100%;text-align:left;">
        &#128683; Marcar Retiro
      </button>
      @endif
    </div>
  </div>
  @endif


</div>{{-- /col der --}}
</div>{{-- /grid --}}
</form>

{{-- ══ MODAL RADICADOS ══ --}}
@if($esEdicion)
<div id="modal-radicados" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:14px;padding:1.4rem;width:100%;max-width:520px;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <h3 style="margin:0;font-size:0.95rem;color:#0f172a;">&#128193; Radicados — Contrato #{{ $contrato->id }}</h3>
      <button onclick="document.getElementById('modal-radicados').style.display='none'" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#94a3b8;line-height:1;">&times;</button>
    </div>
    {{-- Nota informativa --}}
    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:0.5rem 0.75rem;margin-bottom:0.85rem;font-size:0.73rem;color:#0369a1;display:flex;align-items:center;gap:0.4rem;">
      &#128270; Los radicados se gestionan desde el módulo <strong>Afiliaciones</strong>. Aquí solo se visualizan.
    </div>
    @if($contrato->radicados->count() === 0)
    <p style="text-align:center;color:#94a3b8;font-size:0.82rem;padding:1.5rem 0;">No hay radicados generados para este contrato.</p>
    @else
    @php
      // Determinar qué tipos incluye el plan del contrato
      $planContrato = $contrato->plan;
      $tiposIncluidos = [
        'eps'     => $planContrato?->incluye_eps     ?? true,
        'pension' => $planContrato?->incluye_pension ?? true,
        'arl'     => $planContrato?->incluye_arl     ?? true,
        'caja'    => $planContrato?->incluye_caja    ?? true,
      ];
    @endphp
    @foreach($contrato->radicados as $rad)
    @php
      // Saltar si el tipo de este radicado no está incluido en el plan
      $tipoRad = strtolower($rad->tipo ?? '');
      if (isset($tiposIncluidos[$tipoRad]) && !$tiposIncluidos[$tipoRad]) continue;

      $estadoCfg = [
        'pendiente' => ['bg'=>'#fef3c7','txt'=>'#92400e','icono'=>'⏳','label'=>'Pendiente'],
        'tramite'   => ['bg'=>'#dbeafe','txt'=>'#1e40af','icono'=>'🔵','label'=>'En Trámite'],
        'traslado'  => ['bg'=>'#ffedd5','txt'=>'#9a3412','icono'=>'🔄','label'=>'Traslado'],
        'error'     => ['bg'=>'#fee2e2','txt'=>'#991b1b','icono'=>'❌','label'=>'Error'],
        'ok'        => ['bg'=>'#dcfce7','txt'=>'#166534','icono'=>'✅','label'=>'OK — Afiliado'],
      ];
      $ec = $estadoCfg[$rad->estado] ?? ['bg'=>'#f1f5f9','txt'=>'#475569','icono'=>'❓','label'=>$rad->estado];
      $canalLabel = [
        'web'=>'Web','correo'=>'Correo','asesor'=>'Asesor',
        'presencial'=>'Presencial','otro'=>'Otro',
      ][$rad->canal_envio ?? ''] ?? ($rad->canal_envio ? ucfirst($rad->canal_envio) : '—');
    @endphp
    <div style="border:1px solid #e2e8f0;border-radius:9px;padding:0.65rem 0.8rem;margin-bottom:0.55rem;">
      {{-- Tipo + Badge estado --}}
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.45rem;">
        <span style="font-weight:700;font-size:0.82rem;color:#1e293b;">{{ $rad->tipoLabel() }}</span>
        <span style="display:inline-flex;align-items:center;gap:0.25rem;background:{{ $ec['bg'] }};color:{{ $ec['txt'] }};font-size:0.7rem;font-weight:700;padding:0.18rem 0.6rem;border-radius:999px;">
          {{ $ec['icono'] }} {{ $ec['label'] }}
        </span>
      </div>
      {{-- N° Radicado + Canal --}}
      <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;font-size:0.73rem;color:#475569;margin-bottom:0.3rem;">
        @if($rad->numero_radicado)
        <span style="background:#f1f5f9;border-radius:6px;padding:0.15rem 0.5rem;font-family:monospace;font-weight:600;color:#0f172a;">
          # {{ $rad->numero_radicado }}
        </span>
        @else
        <span style="color:#94a3b8;font-style:italic;">Sin número de radicado</span>
        @endif
        <span style="color:#94a3b8;">|</span>
        <span>Canal: <strong>{{ $canalLabel }}</strong></span>
      </div>
      {{-- Observación --}}
      @if($rad->observacion)
      <div style="font-size:0.7rem;color:#64748b;background:#f8fafc;border-radius:5px;padding:0.25rem 0.5rem;margin-top:0.25rem;">
        {{ $rad->observacion }}
      </div>
      @endif
      {{-- Documento subido desde Afiliaciones --}}
      @if($rad->ruta_pdf)
      <div style="margin-top:0.45rem;">
        <a href="{{ route('admin.radicados.pdf.download', $rad->id) }}" target="_blank"
           style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.28rem 0.7rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;color:#1d4ed8;font-size:0.72rem;font-weight:600;text-decoration:none;">
          &#128196; Ver Documento
        </a>
      </div>
      @else
      <div style="margin-top:0.35rem;font-size:0.68rem;color:#94a3b8;font-style:italic;">Sin documento adjunto</div>
      @endif
    </div>
    @endforeach
    @endif
    <div style="text-align:right;margin-top:0.75rem;">
      <button onclick="document.getElementById('modal-radicados').style.display='none'"
          style="padding:0.42rem 1.2rem;border:1px solid #cbd5e1;border-radius:7px;background:#fff;cursor:pointer;font-size:0.82rem;">Cerrar</button>
    </div>
  </div>
</div>
@endif

{{-- MODAL RETIRO --}}
@if($esEdicion && $contrato->estaVigente())
<div id="modal-retiro" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:1.5rem;max-width:720px;width:95%;box-shadow:0 20px 60px rgba(0,0,0,0.3);transition: max-width 0.2s ease;">
    <h3 style="margin:0 0 1rem;color:#dc2626;font-size:0.95rem;">&#128683; Retirar Contrato #{{ $contrato->id }}</h3>

    {{-- Selector de tipo de retiro --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:1rem;">
      <label id="mr-lbl-real" onclick="mrTipo('real')"
        style="cursor:pointer;border:2px solid #ef4444;border-radius:10px;padding:0.6rem 0.8rem;background:#fff5f5;transition:.15s;">
        <div style="display:flex;align-items:center;gap:0.4rem;">
          <input type="radio" name="_tipo_retiro_ui" value="real" checked onclick="event.stopPropagation();mrTipo('real')">
          <strong style="font-size:0.78rem;color:#dc2626;">Retiro Real</strong>
        </div>
        <p style="font-size:0.65rem;color:#6b7280;margin:.25rem 0 0 1.2rem;line-height:1.3;">
          Genera plano con días cotizados. EPS, ARL, Pensión y Caja se calculan automáticamente (registro de costo, no ingreso).
        </p>
      </label>
      @if($retiroInfoBloqueado ?? ($tienePlanillaConDias ?? false))
      <label id="mr-lbl-info"
        style="cursor:not-allowed;border:2px solid #e2e8f0;border-radius:10px;padding:0.6rem 0.8rem;background:#f8fafc;opacity:0.5;transition:.15s;"
        title="No disponible: El contrato ya tiene planillas pagadas con días cotizados > 0">
        <div style="display:flex;align-items:center;gap:0.4rem;">
          <input type="radio" name="_tipo_retiro_ui" value="informativo" disabled>
          <strong style="font-size:0.78rem;color:#94a3b8;">Retiro Informativo</strong>
        </div>
        <p style="font-size:0.65rem;color:#94a3b8;margin:.25rem 0 0 1.2rem;line-height:1.3;">
          No disponible: El contrato ya tiene planillas pagadas con días cotizados.
        </p>
      </label>
      @elseif($retiroInfoForzado ?? false)
      {{-- Superadmin: hay planillas con días cotizados, pero se permite forzarlo
           (contratos migrados o retirados en la planilla fuera del sistema). --}}
      <label id="mr-lbl-info" onclick="mrTipo('informativo')"
        style="cursor:pointer;border:2px solid #f59e0b;border-radius:10px;padding:0.6rem 0.8rem;background:#fffbeb;transition:.15s;">
        <div style="display:flex;align-items:center;gap:0.4rem;">
          <input type="radio" name="_tipo_retiro_ui" value="informativo" onclick="event.stopPropagation();mrTipo('informativo')">
          <strong style="font-size:0.78rem;color:#b45309;">Retiro Informativo</strong>
          <span style="background:#fef3c7;color:#92400e;font-size:0.55rem;font-weight:700;padding:.1rem .35rem;border-radius:20px;letter-spacing:.02em;">SUPERADMIN</span>
        </div>
        <p style="font-size:0.65rem;color:#92400e;margin:.25rem 0 0 1.2rem;line-height:1.3;">
          &#9888;&#65039; El contrato ya tiene planillas pagadas con días cotizados. Se pedirá
          confirmación y quedará en la bitácora.
        </p>
      </label>
      @else
      <label id="mr-lbl-info" onclick="mrTipo('informativo')"
        style="cursor:pointer;border:2px solid #e2e8f0;border-radius:10px;padding:0.6rem 0.8rem;background:#f8fafc;transition:.15s;">
        <div style="display:flex;align-items:center;gap:0.4rem;">
          <input type="radio" name="_tipo_retiro_ui" value="informativo" onclick="event.stopPropagation();mrTipo('informativo')">
          <strong style="font-size:0.78rem;color:#475569;">Retiro Informativo</strong>
        </div>
        <p style="font-size:0.65rem;color:#6b7280;margin:.25rem 0 0 1.2rem;line-height:1.3;">
          Solo marca el retiro (0 días). El cliente paga por sus propios medios.
        </p>
      </label>
      @endif
    </div>

    <form method="POST" action="{{ route('admin.contratos.retirar', $contrato->id) }}" onsubmit="return mrOnSubmit()">
      @csrf @method('PATCH')
      <input type="hidden" name="back_url" value="{{ $backUrl ?? '' }}">
      <input type="hidden" name="tipo_retiro" id="mr-tipo-hidden" value="real">
      <input type="hidden" name="valor_ss" id="mr-valor-ss" value="0">
      <input type="hidden" name="mora" id="mr-mora" value="0">
      @if(request()->has('iframe'))
      <input type="hidden" name="iframe" value="1">
      @endif

      {{-- Layout de Dos Columnas --}}
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1rem;align-items:start;">
        
        {{-- Columna Izquierda: Entradas (Motivo, Fechas/Días, Mes/Año, Observación) --}}
        <div style="display:flex;flex-direction:column;gap:0.7rem;">
          <div>
            <label class="lb" style="display:flex;justify-content:space-between;align-items:center;">
              <span>Motivo *</span>
              <span id="mr-motivo-alert" style="font-size:0.65rem;color:#dc2626;font-weight:600;display:inline;">(Selección obligatoria)</span>
            </label>
            <select name="motivo_retiro_id" id="mr-motivo" required onchange="mrValidarMotivoStyle(this)" style="{{ $S }};border:1.5px solid #fca5a5;background:#fffafb;transition:all 0.2s ease;">
              <option value="">-- Seleccione --</option>
              @foreach($motivosRetiro as $mr)
              <option value="{{ $mr->id }}">{{ $mr->nombre }}</option>
              @endforeach
            </select>
          </div>

          {{-- Sección Fechas y Días --}}
          <div style="display:grid;grid-template-columns:1.1fr 1.2fr 0.7fr;gap:0.5rem;">
            <div>
              <label class="lb" style="color:#64748b;">Fecha Ingreso</label>
              <div style="padding:0.45rem 0.5rem;background:#f8fafc;border:1px solid #cbd5e1;border-radius:7px;font-size:0.78rem;color:#475569;font-weight:600;height:32px;box-sizing:border-box;display:flex;align-items:center;white-space:nowrap;overflow:hidden;">
                {{ $contrato->fecha_ingreso ? sqldate($contrato->fecha_ingreso)->format('d/m/Y') : 'No registra' }}
              </div>
            </div>
            <div>
              <label class="lb">Fecha Retiro *</label>
              <input type="date" name="fecha_retiro" id="mr-fecha" required
                     oninput="mrCalcDias()" style="{{ $I }};height:32px;box-sizing:border-box;">
            </div>
            <div id="mr-dias-wrap">
              <label class="lb" style="white-space:nowrap;">Días pagar</label>
              <input type="number" name="num_dias" id="mr-num-dias"
                     value="1" oninput="mrCalcFecha()" style="{{ $I }};height:32px;box-sizing:border-box;width:100%;font-weight:700;text-align:center;">
            </div>
          </div>

          {{-- Mes/Año del plano --}}
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
            <div>
              <label class="lb">Mes del Plano *</label>
              <select name="mes_plano" id="mr-mes" onchange="mrSetDefault(); mrActualizarCostos(); mrActualizarPeriodoInfo(); mrValidarPeriodoConsecutivo();" style="{{ $S }}">
                <option value="1">Enero</option>
                <option value="2">Febrero</option>
                <option value="3">Marzo</option>
                <option value="4">Abril</option>
                <option value="5">Mayo</option>
                <option value="6">Junio</option>
                <option value="7">Julio</option>
                <option value="8">Agosto</option>
                <option value="9">Septiembre</option>
                <option value="10">Octubre</option>
                <option value="11">Noviembre</option>
                <option value="12">Diciembre</option>
              </select>
            </div>
            <div>
              <label class="lb">Año del Plano *</label>
              <select name="anio_plano" id="mr-anio" onchange="mrOnAnioChange(); mrActualizarCostos(); mrActualizarPeriodoInfo(); mrValidarPeriodoConsecutivo();" style="{{ $S }}">
                @for($y = now()->year - 2; $y <= now()->year + 1; $y++)
                <option value="{{ $y }}">{{ $y }}</option>
                @endfor
              </select>
            </div>
          </div>

          {{-- Error periodo no consecutivo --}}
          <div id="mr-periodo-error" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:7px;padding:0.45rem 0.65rem;font-size:0.72rem;color:#dc2626;line-height:1.4;">
          </div>

          {{-- Error mes de ingreso --}}
          <div id="mr-mes-error" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:7px;padding:0.35rem 0.65rem;font-size:0.72rem;color:#dc2626;">
            &#9888; El mes del plano no puede ser anterior al mes de ingreso del contrato.
          </div>

          <div>
            <label class="lb">Observación</label>
            <textarea name="observacion" style="width:100%;height:64px;padding:0.5rem;border:1px solid #cbd5e1;border-radius:7px;font-size:0.8rem;resize:none;box-sizing:border-box;font-family:inherit;"></textarea>
          </div>
        </div>

        {{-- Columna Derecha: Salidas (Notas de Periodo, Desglose o Explicación) --}}
        <div style="display:flex;flex-direction:column;gap:0.7rem;">
          
          {{-- Campo informativo de aplicación de retiro --}}
          <div id="mr-periodo-aplicar-box" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:0.45rem 0.65rem;font-size:0.72rem;color:#166534;display:flex;flex-direction:column;gap:0.15rem;transition: all 0.2s ease;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span><strong>Mes del Plano:</strong> <span id="mr-info-mes-plano">-</span></span>
              <span id="mr-info-tipo-periodo" style="font-size:0.62rem;font-weight:700;background:#dcfce7;padding:0.08rem 0.35rem;border-radius:4px;color:#166534;text-transform:uppercase;letter-spacing:0.3px;">Mes Vencido</span>
            </div>
            <div style="margin-top:0.1rem;border-top:1px dashed rgba(22, 101, 52, 0.15);padding-top:0.15rem;display:flex;justify-content:space-between;align-items:center;">
              <span><strong>Mes a aplicar retiro:</strong></span>
              <span id="mr-info-mes-aplicar" style="font-weight:700;font-size:0.78rem;color:#15803d;">-</span>
            </div>
          </div>

          {{-- Tarjeta Desglose Premium (Retiro Real) --}}
          <div id="mr-desglose-box" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:0.6rem 0.8rem;font-size:0.72rem;color:#334155;box-shadow:inset 0 1px 3px rgba(0,0,0,0.02);display:none;">
            <div style="font-weight:700;margin-bottom:0.4rem;color:#475569;display:flex;align-items:center;justify-content:space-between;">
              <span>Desglose Estimado por Entidad</span>
              <span style="font-size:0.6rem;font-weight:normal;color:#94a3b8;">Valores informativos</span>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:0.72rem;">
              <thead>
                <tr style="border-bottom:1px solid #e2e8f0;text-align:left;color:#64748b;font-weight:600;font-size:0.68rem;">
                  <th style="padding:0.2rem 0;text-transform:uppercase;letter-spacing:0.2px;">Entidad</th>
                  <th style="padding:0.2rem 0;text-align:right;text-transform:uppercase;letter-spacing:0.2px;">Aporte</th>
                  <th style="padding:0.2rem 0;text-align:right;text-transform:uppercase;letter-spacing:0.2px;">Mora</th>
                  <th style="padding:0.2rem 0;text-align:right;text-transform:uppercase;letter-spacing:0.2px;">Total</th>
                </tr>
              </thead>
              <tbody style="line-height:1.4;">
                <tr style="border-bottom:1px dashed #e2e8f0;">
                  <td style="padding:0.3rem 0;font-weight:500;display:flex;align-items:center;gap:0.3rem;"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#3b82f6;"></span> EPS</td>
                  <td style="padding:0.3rem 0;text-align:right;" id="mr-desglose-eps-val">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;color:#ef4444;" id="mr-desglose-eps-mora">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;font-weight:600;" id="mr-desglose-eps-total">$0</td>
                </tr>
                <tr style="border-bottom:1px dashed #e2e8f0;">
                  <td style="padding:0.3rem 0;font-weight:500;display:flex;align-items:center;gap:0.3rem;"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#f59e0b;"></span> ARL</td>
                  <td style="padding:0.3rem 0;text-align:right;" id="mr-desglose-arl-val">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;color:#ef4444;" id="mr-desglose-arl-mora">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;font-weight:600;" id="mr-desglose-arl-total">$0</td>
                </tr>
                <tr style="border-bottom:1px dashed #e2e8f0;">
                  <td style="padding:0.3rem 0;font-weight:500;display:flex;align-items:center;gap:0.3rem;"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#10b981;"></span> Pensión</td>
                  <td style="padding:0.3rem 0;text-align:right;" id="mr-desglose-pen-val">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;color:#ef4444;" id="mr-desglose-pen-mora">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;font-weight:600;" id="mr-desglose-pen-total">$0</td>
                </tr>
                <tr style="border-bottom:1px dashed #cbd5e1;">
                  <td style="padding:0.3rem 0;font-weight:500;display:flex;align-items:center;gap:0.3rem;"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#8b5cf6;"></span> Caja</td>
                  <td style="padding:0.3rem 0;text-align:right;" id="mr-desglose-caja-val">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;color:#ef4444;" id="mr-desglose-caja-mora">$0</td>
                  <td style="padding:0.3rem 0;text-align:right;font-weight:600;" id="mr-desglose-caja-total">$0</td>
                </tr>
                <tr style="font-weight:700;color:#1e293b;background:#f1f5f9;font-size:0.74rem;">
                  <td style="padding:0.35rem 0.4rem;border-radius:4px 0 0 4px;">TOTAL RETIRO</td>
                  <td style="padding:0.35rem 0.2rem;text-align:right;" id="mr-desglose-tot-val">$0</td>
                  <td style="padding:0.35rem 0.2rem;text-align:right;color:#ef4444;" id="mr-desglose-tot-mora">$0</td>
                  <td style="padding:0.35rem 0.4rem;text-align:right;color:#dc2626;border-radius:0 4px 4px 0;" id="mr-desglose-tot-total">$0</td>
                </tr>
              </tbody>
            </table>
          </div>

          {{-- Explicacion Retiro Informativo --}}
          <div id="mr-info-explicacion-box" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:0.8rem;font-size:0.72rem;color:#0369a1;line-height:1.45;box-shadow:inset 0 1px 2px rgba(0,0,0,0.01);">
            <div style="display:flex;align-items:center;gap:0.4rem;color:#0284c7;font-weight:700;margin-bottom:0.35rem;">
              <svg style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              <span>Retiro Informativo</span>
            </div>
            <p style="margin:0 0 0.4rem;">
              Este tipo de retiro se utiliza principalmente para <strong>corrección de errores</strong> (por ejemplo, si se afilió a la persona por equivocación).
            </p>
            <p style="margin:0;">
              Se registrará con el periodo de plano seleccionado. Dado que cotiza <strong>0 días</strong>, no generará valor de seguridad social (gasto) en la factura ni se incluirán días cotizados en el plano.
            </p>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:0.6rem;justify-content:flex-end;border-top:1px solid #f1f5f9;padding-top:0.8rem;">
        <button type="button" onclick="document.getElementById('modal-retiro').style.display='none'"
            style="padding:0.45rem 1rem;border:1px solid #cbd5e1;border-radius:7px;background:#fff;cursor:pointer;font-size:0.82rem;">Cancelar</button>
        <button type="submit" id="mr-submit-btn"
            style="padding:0.45rem 1.1rem;background:#dc2626;border:none;border-radius:7px;color:#fff;font-size:0.82rem;font-weight:700;cursor:pointer;min-width:130px;display:flex;align-items:center;justify-content:center;gap:0.4rem;">Confirmar Retiro</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Modal Retiro: datos del contrato ──────────────────────────────────────
const MR = {
    // Fecha ingreso del contrato (ISO string o null)
    fechaIngreso: @if($contrato->fecha_ingreso)'{{ sqldate($contrato->fecha_ingreso)->format('Y-m-d') }}'@else null @endif,
    // ¿El contrato paga el mes en curso?
    esMesActual: {{ (bool) ($contrato->paga_mes_actual ?? false) ? 'true' : 'false' }},
    // ¿Tiene planillas pagadas con días cotizados > 0?
    tienePlanillaConDias: {{ ($tienePlanillaConDias ?? false) ? 'true' : 'false' }},
};

// Planos ya generados para este contrato
const PLANOS_EXISTENTES = @json($planosExistentes ?? []);

// Desglose de cotización en memoria
window._mrUltimoDesglose = null;

// ── Formatear moneda en pesos colombianos ─────────────────────────────────
function mrFormatearMoneda(val) {
    return '$' + Math.round(val).toLocaleString('es-CO');
}

// ── Renderizar la tabla de desglose por entidad ───────────────────────────
function mrRenderizarDesglose(desglose) {
    const box = document.getElementById('mr-desglose-box');
    if (!box) return;
    if (!desglose) {
        box.style.display = 'none';
        return;
    }
    box.style.display = 'block';

    // EPS
    document.getElementById('mr-desglose-eps-val').textContent = mrFormatearMoneda(desglose.eps.valor);
    document.getElementById('mr-desglose-eps-mora').textContent = mrFormatearMoneda(desglose.eps.mora);
    document.getElementById('mr-desglose-eps-total').textContent = mrFormatearMoneda(desglose.eps.valor + desglose.eps.mora);
    
    // ARL
    document.getElementById('mr-desglose-arl-val').textContent = mrFormatearMoneda(desglose.arl.valor);
    document.getElementById('mr-desglose-arl-mora').textContent = mrFormatearMoneda(desglose.arl.mora);
    document.getElementById('mr-desglose-arl-total').textContent = mrFormatearMoneda(desglose.arl.valor + desglose.arl.mora);
    
    // Pensión
    document.getElementById('mr-desglose-pen-val').textContent = mrFormatearMoneda(desglose.pen.valor);
    document.getElementById('mr-desglose-pen-mora').textContent = mrFormatearMoneda(desglose.pen.mora);
    document.getElementById('mr-desglose-pen-total').textContent = mrFormatearMoneda(desglose.pen.valor + desglose.pen.mora);
    
    // Caja
    document.getElementById('mr-desglose-caja-val').textContent = mrFormatearMoneda(desglose.caja.valor);
    document.getElementById('mr-desglose-caja-mora').textContent = mrFormatearMoneda(desglose.caja.mora);
    document.getElementById('mr-desglose-caja-total').textContent = mrFormatearMoneda(desglose.caja.valor + desglose.caja.mora);
    
    // Totales
    const totVal = desglose.eps.valor + desglose.arl.valor + desglose.pen.valor + desglose.caja.valor;
    const totMora = desglose.eps.mora + desglose.arl.mora + desglose.pen.mora + desglose.caja.mora;
    document.getElementById('mr-desglose-tot-val').textContent = mrFormatearMoneda(totVal);
    document.getElementById('mr-desglose-tot-mora').textContent = mrFormatearMoneda(totMora);
    document.getElementById('mr-desglose-tot-total').textContent = mrFormatearMoneda(totVal + totMora);
}

// ── Redistribución proporcional manual al editar inputs ───────────────────
function mrRedistribuirManual() {
    const valorManual = parseFloat(document.getElementById('mr-valor-ss')?.value || 0);
    const moraManual = parseFloat(document.getElementById('mr-mora')?.value || 0);
    
    const desgloseBase = window._mrUltimoDesglose;
    if (!desgloseBase) return;
    
    const vEpsBase = desgloseBase.eps.valor;
    const vArlBase = desgloseBase.arl.valor;
    const vPenBase = desgloseBase.pen.valor;
    const vCajaBase = desgloseBase.caja.valor;
    const totalSsBase = vEpsBase + vArlBase + vPenBase + vCajaBase;
    
    let vEps = 0, vArl = 0, vPen = 0, vCaja = 0;
    if (totalSsBase > 0) {
        const factorSs = valorManual / totalSsBase;
        vEps  = Math.round(vEpsBase * factorSs);
        vArl  = Math.round(vArlBase * factorSs);
        vPen  = Math.round(vPenBase * factorSs);
        vCaja = Math.round(vCajaBase * factorSs);
        
        // Ajustar remanente de SS
        const sumaSs = vEps + vArl + vPen + vCaja;
        const diffSs = valorManual - sumaSs;
        if (diffSs !== 0) {
            if (vPen >= vEps && vPen >= vArl && vPen >= vCaja) vPen += diffSs;
            else if (vEps >= vArl && vEps >= vCaja) vEps += diffSs;
            else vArl += diffSs;
        }
    } else {
        vEps = valorManual;
    }
    
    let mEps = 0, mArl = 0, mPen = 0, mCaja = 0;
    if (totalSsBase > 0) {
        const factorMora = moraManual / totalSsBase;
        mEps  = Math.round(vEpsBase * factorMora);
        mArl  = Math.round(vArlBase * factorMora);
        mPen  = Math.round(vPenBase * factorMora);
        mCaja = Math.round(vCajaBase * factorMora);
        
        // Ajustar remanente de Mora
        const sumaMora = mEps + mArl + mPen + mCaja;
        const diffMora = moraManual - sumaMora;
        if (diffMora !== 0) {
            if (vPen >= vEps && vPen >= vArl && vPen >= vCaja) mPen += diffMora;
            else if (vEps >= vArl && vEps >= vCaja) mEps += diffMora;
            else mArl += diffMora;
        }
    } else {
        mEps = moraManual;
    }
    
    const desgloseRedistribuido = {
        eps: { valor: vEps, mora: mEps },
        arl: { valor: vArl, mora: mArl },
        pen: { valor: vPen, mora: mPen },
        caja: { valor: vCaja, mora: mCaja }
    };
    
    mrRenderizarDesglose(desgloseRedistribuido);
}

// ── Leer mes/año seleccionados (fuente de verdad) ─────────────────────────
function mrMesPlan() {
    const mesEl  = document.getElementById('mr-mes');
    const anioEl = document.getElementById('mr-anio');
    if (mesEl && anioEl && mesEl.value && anioEl.value) {
        return { anio: parseInt(anioEl.value, 10), mes: parseInt(mesEl.value, 10) };
    }
    // Fallback si los selects no existen todavía
    const hoy = new Date();
    if (MR.esMesActual) return { anio: hoy.getFullYear(), mes: hoy.getMonth() + 1 };
    const m = hoy.getMonth(); // 0-based; 0 = enero
    return { anio: m === 0 ? hoy.getFullYear() - 1 : hoy.getFullYear(), mes: m === 0 ? 12 : m };
}

// ── Actualizar campo informativo del período de aplicación ───────────────
function mrActualizarPeriodoInfo() {
    const { anio, mes } = mrMesPlan();
    const meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
    
    const mesEl = document.getElementById('mr-info-mes-plano');
    const aplicarEl = document.getElementById('mr-info-mes-aplicar');
    const tipoEl = document.getElementById('mr-info-tipo-periodo');
    const boxEl = document.getElementById('mr-periodo-aplicar-box');
    
    if (!mesEl || !aplicarEl || !tipoEl || !boxEl) return;
    
    mesEl.textContent = `${meses[mes - 1]} ${anio}`;
    
    let mesAplicar = mes;
    let anioAplicar = anio;
    
    if (MR.esMesActual) {
        tipoEl.textContent = "Mes Actual";
        tipoEl.style.background = "#dbeafe";
        tipoEl.style.color = "#1e40af";
        boxEl.style.background = "#eff6ff";
        boxEl.style.borderColor = "#bfdbfe";
        boxEl.style.color = "#1e3a8a";
    } else {
        mesAplicar++;
        if (mesAplicar > 12) {
            mesAplicar = 1;
            anioAplicar++;
        }
        tipoEl.textContent = "Mes Vencido";
        tipoEl.style.background = "#dcfce7";
        tipoEl.style.color = "#166534";
        boxEl.style.background = "#f0fdf4";
        boxEl.style.borderColor = "#bbf7d0";
        boxEl.style.color = "#14532d";
    }
    
    aplicarEl.textContent = `${meses[mesAplicar - 1]} ${anioAplicar}`;
}

// ── Inhabilitar meses que ya tienen planilla o menores al ingreso ──────────
function mrActualizarMesesDisponibles() {
    const anioEl = document.getElementById('mr-anio');
    const mesEl  = document.getElementById('mr-mes');
    if (!anioEl || !mesEl) return;
    const anioSel = parseInt(anioEl.value, 10);

    let ingrAnio = null;
    let ingrMes = null;
    if (MR.fechaIngreso) {
        const parts = MR.fechaIngreso.split('-');
        ingrAnio = parseInt(parts[0], 10);
        ingrMes  = parseInt(parts[1], 10);
    }

    Array.from(mesEl.options).forEach(opt => {
        const mesVal = parseInt(opt.value, 10);
        
        // 1. Ya tiene planilla generada
        const yaTiene = PLANOS_EXISTENTES.some(p => p.mes === mesVal && p.anio === anioSel);
        
        // 2. Es anterior a la fecha de ingreso
        let esAnteriorIngreso = false;
        if (ingrAnio !== null && ingrMes !== null) {
            const refSel = anioSel * 100 + mesVal;
            const refIngr = ingrAnio * 100 + ingrMes;
            if (refSel < refIngr) {
                esAnteriorIngreso = true;
            }
        }

        const deshabilitar = yaTiene || esAnteriorIngreso;
        opt.disabled = deshabilitar;

        if (deshabilitar) {
            opt.style.color = '#94a3b8';
            opt.style.textDecoration = 'line-through';
        } else {
            opt.style.color = '';
            opt.style.textDecoration = '';
        }
    });
}

// ── Inhabilitar años menores al de ingreso del contrato ───────────────────
function mrActualizarAniosDisponibles() {
    const anioEl = document.getElementById('mr-anio');
    if (!anioEl || !MR.fechaIngreso) return;
    const [ingrAnio] = MR.fechaIngreso.split('-').map(Number);

    Array.from(anioEl.options).forEach(opt => {
        const anioVal = parseInt(opt.value, 10);
        if (anioVal < ingrAnio) {
            opt.disabled = true;
            opt.style.color = '#94a3b8';
            opt.style.textDecoration = 'line-through';
        } else {
            opt.disabled = false;
            opt.style.color = '';
            opt.style.textDecoration = '';
        }
    });
}

// ── Evento al cambiar el año del plano ──────────────────────────────────
function mrOnAnioChange() {
    mrActualizarMesesDisponibles();
    const mesEl = document.getElementById('mr-mes');
    if (mesEl && mesEl.selectedOptions[0]?.disabled) {
        // Si el mes seleccionado queda inhabilitado, buscar el primer mes disponible
        const primerDisponible = Array.from(mesEl.options).find(opt => !opt.disabled);
        if (primerDisponible) {
            mesEl.value = primerDisponible.value;
        }
    }
    mrSetDefault();
}

// ── Inicializar selects al abrir el modal ────────────────────────────────
function mrInitSelects() {
    const esperado = mrGetPeriodoEsperado();
    const mesDef = esperado.mes;
    const anioDef = esperado.anio;

    const mesEl  = document.getElementById('mr-mes');
    const anioEl = document.getElementById('mr-anio');
    
    // Inhabilitar años menores al de ingreso
    mrActualizarAniosDisponibles();
    
    if (anioEl) anioEl.value = anioDef;
    
    // Actualizar deshabilitados antes de asignar el valor del mes
    mrActualizarMesesDisponibles();

    if (mesEl)  mesEl.value  = mesDef;
    mrActualizarPeriodoInfo();

    // Inicializar el estilo del motivo como obligatorio
    const motivoEl = document.getElementById('mr-motivo');
    if (motivoEl) {
        motivoEl.value = "";
        mrValidarMotivoStyle(motivoEl);
    }

    // Validar consecutividad
    mrValidarPeriodoConsecutivo();
}

// ── Validar estilo del select de Motivo obligatoriedad ───────────────────
function mrValidarMotivoStyle(el) {
    const alertEl = document.getElementById('mr-motivo-alert');
    if (el.value) {
        el.style.borderColor = '#cbd5e1';
        el.style.background = '#ffffff';
        if (alertEl) alertEl.style.display = 'none';
    } else {
        el.style.borderColor = '#fca5a5';
        el.style.background = '#fffafb';
        if (alertEl) alertEl.style.display = 'inline';
    }
}

// ── Validar que mes_plano no sea anterior al mes de ingreso ───────────────
function mrValidarMes() {
    if (!MR.fechaIngreso) return true; // sin ingreso: siempre válido
    const { anio, mes } = mrMesPlan();
    const [fAnio, fMes] = MR.fechaIngreso.split('-').map(Number);
    const errEl = document.getElementById('mr-mes-error');
    const esInvalido = (anio * 100 + mes) < (fAnio * 100 + fMes);
    if (errEl) errEl.style.display = esInvalido ? 'block' : 'none';
    if (esInvalido) return false; // bloquea el submit
    return true;
}

// ── Obtener la fecha base para los cálculos del retiro ────────────────────
function mrGetBaseDate() {
    const { anio, mes } = mrMesPlan();
    if (MR.fechaIngreso) {
        const parts = MR.fechaIngreso.split('-');
        if (parts.length === 3) {
            const fAnio = parseInt(parts[0], 10);
            const fMes  = parseInt(parts[1], 10);
            const fDia  = parseInt(parts[2], 10);
            if (fAnio === anio && fMes === mes) {
                // Ingreso en el mismo mes del plano → la base es la fecha de ingreso
                return new Date(fAnio, fMes - 1, fDia);
            }
        }
    }
    // Ingreso en otro mes (o no definido) → la base es el día 1 del mes del plano
    return new Date(anio, mes - 1, 1);
}

// ── Calcular Costo de Retiro y Mora en Tiempo Real ────────────────────────
async function mrActualizarCostos() {
    const valorSsEl = document.getElementById('mr-valor-ss');
    const moraEl = document.getElementById('mr-mora');
    if (!valorSsEl || !moraEl) return;

    const tipoRetiro = document.getElementById('mr-tipo-hidden')?.value || 'real';
    if (tipoRetiro === 'informativo') {
        valorSsEl.value = 0;
        moraEl.value = 0;
        window._mrUltimoDesglose = null;
        mrRenderizarDesglose(null);
        return;
    }

    const { anio, mes } = mrMesPlan();
    const dias = parseInt(document.getElementById('mr-num-dias')?.value || 1, 10);
    const url = `/admin/contratos/api/calcular-retiro/{{ $contrato->id ?? 0 }}?dias=${dias}&mes_plano=${mes}&anio_plano=${anio}&tipo_retiro=${tipoRetiro}`;

    // Atenuar el desglose mientras se realiza la consulta para indicar carga
    const desgloseBox = document.getElementById('mr-desglose-box');
    if (desgloseBox) desgloseBox.style.opacity = '0.5';

    try {
        const resp = await fetch(url);
        const data = await resp.json();
        if (data.ok) {
            valorSsEl.value = data.costo_ss;
            moraEl.value = data.mora;
            window._mrUltimoDesglose = data.desglose;
            mrRenderizarDesglose(data.desglose);
        }
    } catch (e) {
        console.error('Error al calcular costos del retiro:', e);
    } finally {
        if (desgloseBox) desgloseBox.style.opacity = '1';
    }
}

// ── Establecer fecha/días según mes/año seleccionado ─────────────────────
function mrSetDefault() {
    // Ocultar error previo al cambiar el select
    const errEl = document.getElementById('mr-mes-error');
    if (errEl) errEl.style.display = 'none';

    const baseDate = mrGetBaseDate();
    const yB = baseDate.getFullYear();
    const mB = String(baseDate.getMonth() + 1).padStart(2, '0');
    const dB = String(baseDate.getDate()).padStart(2, '0');

    const fechaEl = document.getElementById('mr-fecha');
    const diasEl  = document.getElementById('mr-num-dias');
    if (fechaEl) fechaEl.value = `${yB}-${mB}-${dB}`;
    if (diasEl)  diasEl.value  = 1;
    mrActualizarCostos();
}

// ── Fecha → Días (diferencia con la fecha base, máx 30) ───────────────────
function mrCalcDias() {
    const val = document.getElementById('mr-fecha')?.value;
    if (!val) return;

    const parts = val.split('-');
    if (parts.length !== 3) return;
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);

    // Sincronizar selectores de mes y año con la fecha seleccionada
    const mesEl = document.getElementById('mr-mes');
    const anioEl = document.getElementById('mr-anio');
    if (mesEl && anioEl) {
        let cambioDetectado = false;
        if (parseInt(mesEl.value, 10) !== m) {
            mesEl.value = m;
            cambioDetectado = true;
        }
        if (parseInt(anioEl.value, 10) !== y) {
            anioEl.value = y;
            cambioDetectado = true;
        }
        if (cambioDetectado) {
            mrActualizarMesesDisponibles();
            mrActualizarPeriodoInfo();
        }
    }

    const selectedDate = new Date(y, m - 1, d);
    const baseDate = mrGetBaseDate();

    // Si la fecha seleccionada es menor que la base, la ajustamos a la base
    if (selectedDate < baseDate) {
        const yB = baseDate.getFullYear();
        const mB = String(baseDate.getMonth() + 1).padStart(2, '0');
        const dB = String(baseDate.getDate()).padStart(2, '0');
        document.getElementById('mr-fecha').value = `${yB}-${mB}-${dB}`;
        const input = document.getElementById('mr-num-dias');
        if (input) input.value = 1;
        mrActualizarCostos();
        return;
    }

    // Diferencia en días
    const diffTime = selectedDate - baseDate;
    const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24)) + 1;
    const dias = Math.min(diffDays, 30);

    const input = document.getElementById('mr-num-dias');
    if (input) input.value = dias;
    mrActualizarCostos();
}

// ── Días → Fecha (suma días a la fecha base en el mes seleccionado) ───────
function mrCalcFecha() {
    const diasInput = document.getElementById('mr-num-dias');
    const fechaEl   = document.getElementById('mr-fecha');
    if (!diasInput || !fechaEl) return;
    let dias = parseInt(diasInput.value, 10);
    if (isNaN(dias) || dias < 1) { dias = 1; diasInput.value = 1; }
    if (dias > 30)               { dias = 30; diasInput.value = 30; }

    const baseDate = mrGetBaseDate();
    const { anio, mes } = mrMesPlan();

    // Calcular nueva fecha sumando (dias - 1) a la base
    const targetDate = new Date(baseDate.getTime());
    targetDate.setDate(baseDate.getDate() + (dias - 1));

    // Si la fecha resultante se pasa del mes seleccionado, limitar al último día real del mes
    const ultimoDiaReal = new Date(anio, mes, 0).getDate();
    if (targetDate.getMonth() + 1 !== mes || targetDate.getFullYear() !== anio) {
        fechaEl.value = `${anio}-${String(mes).padStart(2,'0')}-${String(ultimoDiaReal).padStart(2,'0')}`;
    } else {
        const y = targetDate.getFullYear();
        const m = String(targetDate.getMonth() + 1).padStart(2, '0');
        const d = String(targetDate.getDate()).padStart(2, '0');
        fechaEl.value = `${y}-${m}-${d}`;
    }
    mrActualizarCostos();
}

// ¿El superadmin está forzando un retiro informativo sobre un contrato con
// planillas pagadas con días cotizados? (para el resto la opción va disabled)
const RETIRO_INFO_FORZADO = {{ ($retiroInfoForzado ?? false) ? 'true' : 'false' }};

// ── Toggle tipo retiro ────────────────────────────────────────────────────
function mrTipo(tipo) {
    document.getElementById('mr-tipo-hidden').value = tipo;
    const lblReal  = document.getElementById('mr-lbl-real');
    const lblInfo  = document.getElementById('mr-lbl-info');
    const numDias  = document.getElementById('mr-num-dias');
    const diasWrap = document.getElementById('mr-dias-wrap');
    const desgloseBox = document.getElementById('mr-desglose-box');
    const explicacionBox = document.getElementById('mr-info-explicacion-box');

    if (tipo === 'real') {
        lblReal.style.borderColor = '#ef4444';
        lblReal.style.background  = '#fff5f5';
        lblInfo.style.borderColor = '#e2e8f0';
        lblInfo.style.background  = '#f8fafc';
        
        if (diasWrap) diasWrap.style.display = 'block';
        if (explicacionBox) explicacionBox.style.display = 'none';
        
        numDias.disabled = false;
        numDias.required = true;
        if (!numDias.value || numDias.value == 0) numDias.value = 1;
        mrSetDefault();
    } else {
        // Si el superadmin lo está forzando, se mantiene el ámbar de advertencia
        // en vez del azul de selección normal.
        lblInfo.style.borderColor = RETIRO_INFO_FORZADO ? '#d97706' : '#0284c7';
        lblInfo.style.background  = RETIRO_INFO_FORZADO ? '#fef3c7' : '#f0f9ff';
        lblReal.style.borderColor = '#e2e8f0';
        lblReal.style.background  = '#f8fafc';

        if (diasWrap) diasWrap.style.display = 'none';
        if (desgloseBox) desgloseBox.style.display = 'none';
        if (explicacionBox) explicacionBox.style.display = 'block';
        
        numDias.disabled = true;
        numDias.required = false;
        numDias.value    = 0;
        mrActualizarCostos();
    }
}

// ── Obtener Periodo Consecutivo Esperado ──────────────────────────────────
function mrGetPeriodoEsperado() {
    let mesEsp = null;
    let anioEsp = null;

    if (PLANOS_EXISTENTES && PLANOS_EXISTENTES.length > 0) {
        let maxVal = 0;
        let ultimoPlano = null;
        PLANOS_EXISTENTES.forEach(p => {
            const val = p.anio * 100 + p.mes;
            if (val > maxVal) {
                maxVal = val;
                ultimoPlano = p;
            }
        });
        if (ultimoPlano) {
            mesEsp = ultimoPlano.mes + 1;
            anioEsp = ultimoPlano.anio;
            if (mesEsp > 12) {
                mesEsp = 1;
                anioEsp++;
            }
        }
    } else {
        if (MR.fechaIngreso) {
            const parts = MR.fechaIngreso.split('-');
            anioEsp = parseInt(parts[0], 10);
            mesEsp  = parseInt(parts[1], 10);
        } else {
            const hoy = new Date();
            anioEsp = hoy.getFullYear();
            mesEsp = hoy.getMonth() + 1;
        }
    }
    return { mes: mesEsp, anio: anioEsp };
}

// ── Validar que el periodo seleccionado sea consecutivo ───────────────────
function mrValidarPeriodoConsecutivo() {
    const esperado = mrGetPeriodoEsperado();
    const { anio, mes } = mrMesPlan();
    
    const errEl = document.getElementById('mr-periodo-error');
    const btn = document.getElementById('mr-submit-btn');
    
    const esInvalido = (anio !== esperado.anio || mes !== esperado.mes);
    
    if (errEl) {
        if (esInvalido) {
            const meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
            errEl.innerHTML = `&#9888; Debe seleccionar <strong>${meses[esperado.mes - 1]} de ${esperado.anio}</strong> para el retiro, ya que es el periodo consecutivo correspondiente (o generar la planilla del mes anterior primero).`;
            errEl.style.display = 'block';
        } else {
            errEl.style.display = 'none';
        }
    }
    
    if (btn) {
        btn.disabled = esInvalido;
        if (esInvalido) {
            btn.style.background = '#94a3b8';
            btn.style.cursor = 'not-allowed';
            btn.style.opacity = '0.6';
        } else {
            btn.style.background = '#dc2626';
            btn.style.cursor = 'pointer';
            btn.style.opacity = '1';
        }
    }
    
    return !esInvalido;
}


document.addEventListener('DOMContentLoaded', function() { mrInitSelects(); });

// ── Submit con loading state ──────────────────────────────────────────────
function mrOnSubmit() {
    if (!mrValidarMes()) return false;
    if (!mrValidarPeriodoConsecutivo()) return false; // Bloquea si no es consecutivo

    // Confirmación del superadmin: el retiro informativo no genera plano ni días
    // cotizados, así que sobre un contrato que ya cotizó es una decisión manual.
    if (RETIRO_INFO_FORZADO && document.getElementById('mr-tipo-hidden')?.value === 'informativo') {
        const fecha = document.getElementById('mr-fecha')?.value || '(sin fecha)';
        const ok = confirm(
            '⚠️ Este contrato ya tiene planillas pagadas con días cotizados.\n\n'
          + 'Para cualquier otro usuario el retiro informativo estaría bloqueado.\n\n'
          + 'Se marcará el retiro con 0 días y NO se generará plano ni cobro de\n'
          + 'seguridad social. Úselo solo si el retiro ya se aplicó en la planilla\n'
          + 'por fuera del sistema, o si el contrato viene migrado.\n\n'
          + 'Fecha de retiro: ' + fecha + '\n\n'
          + 'Quedará registrado en la bitácora a su nombre.\n\n¿Confirma el retiro informativo?'
        );
        if (!ok) return false;
    }

    const btn = document.getElementById('mr-submit-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<svg style="width:14px;height:14px;animation:mr-spin 0.8s linear infinite;flex-shrink:0;" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="rgba(255,255,255,0.35)" stroke-width="3"/><path d="M12 2a10 10 0 0 1 10 10" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg> Procesando...';
        btn.style.background = '#b91c1c';
        btn.style.cursor = 'not-allowed';
    }
    return true;
}
</script>

<style>
@keyframes mr-spin { to { transform: rotate(360deg); } }
</style>

@endif

</div>{{-- /x-data --}}

{{-- ══ MODAL FACTURAR PLANILLA (unificado, individual) ══ --}}
@if($esEdicion)
@include('admin.facturacion._modal_facturar', ['bancos' => $bancos])
@endif

{{-- ══ MODAL HISTORIAL DE PAGOS (iframe, sin layout) ══ --}}
@if($esEdicion)
<div id="modal-historial"
     onclick="if(event.target.id==='modal-historial')cerrarHistorial()"
     style="display:none;position:fixed;inset:0;background:rgba(10,16,30,.72);backdrop-filter:blur(5px);z-index:10500;align-items:center;justify-content:center;padding:.5rem">
  <div style="position:relative;width:min(1180px,97vw);height:94vh;background:#f0f4f8;border-radius:14px;overflow:hidden;box-shadow:0 28px 80px rgba(0,0,0,.55);display:flex;flex-direction:column">
    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#0f172a,#4c1d95);padding:.55rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <div style="display:flex;align-items:center;gap:.55rem">
        <span style="font-size:1.1rem">📊</span>
        <span style="color:#fff;font-size:.92rem;font-weight:700;letter-spacing:.02em">Historial de Pagos</span>
        <span style="color:rgba(255,255,255,.55);font-size:.78rem">— {{ $cliente?->tipo_doc ?: 'CC' }} {{ $contrato->cedula }}</span>
      </div>
      <button type="button" onclick="cerrarHistorial()"
              style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:7px;width:30px;height:30px;font-size:1rem;cursor:pointer;font-weight:700;line-height:1;transition:background .15s"
              onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">&#x2715;</button>
    </div>
    {{-- iframe --}}
    <div style="flex:1;overflow:hidden">
      <iframe id="historial-frame" src="" style="width:100%;height:100%;border:none;display:block"></iframe>
    </div>
  </div>
</div>
@endif

{{-- ══ MODAL RECIBO (iframe) ══ --}}
@if($esEdicion)
<div id="recibo-modal-ov"
     onclick="if(event.target.id==='recibo-modal-ov')cerrarRecibo()"
     style="display:none;position:fixed;inset:0;background:rgba(10,16,30,.72);backdrop-filter:blur(5px);z-index:10600;align-items:center;justify-content:center;padding:.5rem">
  <div style="position:relative;width:min(1100px,97vw);height:93vh;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 28px 80px rgba(0,0,0,.55);display:flex;flex-direction:column">
    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:.55rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <div style="display:flex;align-items:center;gap:.55rem">
        <span style="font-size:1.1rem">🧾</span>
        <span style="color:#fff;font-size:.92rem;font-weight:700;letter-spacing:.02em">Recibo de Pago</span>
      </div>
      <button type="button" onclick="cerrarRecibo()"
              style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:7px;width:30px;height:30px;font-size:1rem;cursor:pointer;font-weight:700;line-height:1;transition:background .15s"
              onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">&#x2715;</button>
    </div>
    {{-- iframe --}}
    <div style="flex:1;overflow:hidden;background:#e8edf2;padding:.35rem 0 0;">
      <iframe id="recibo-frame" src="" style="width:100%;height:100%;border:none;display:block"></iframe>
    </div>
  </div>
</div>
@endif

<style>
/* line-height fijo: un label con emoji (💳 Operador Planilla) hereda las métricas de la
   fuente de emoji y crece más que los de solo texto, empujando su input hacia abajo y
   desalineando la fila del grid. */
.lb  { display:block;font-size:0.67rem;font-weight:700;color:#475569;margin-bottom:0.15rem;text-transform:uppercase;letter-spacing:0.03em;line-height:1.35; }

/* Chip bajo un select de entidad: el badge del radicado y el botón del
   certificado ARL van uno al lado del otro en la misma fila del grid, así que
   comparten medidas. La altura va fija a propósito: el badge la sacaba del
   texto (Inter) y el botón de su ícono de 12px, y eso dejaba al botón 2,5px
   más bajo, con el renglón torcido. Solo el color cambia en cada uso. */
.chip-ss {
  display:flex;align-items:center;justify-content:center;gap:0.2rem;
  width:100%;box-sizing:border-box;height:20px;margin-top:0.22rem;
  padding:0 0.4rem;border:none;border-radius:5px;
  font-family:inherit;font-size:0.62rem;font-weight:700;line-height:1;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}

.cp  { background:#fff;border-radius:11px;border:1px solid #e2e8f0;padding:0.8rem 0.95rem; }
.pt  { font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.5rem; }
.cr  { display:flex;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid rgba(255,255,255,0.06); }
.cp2 { font-size:0.63rem;opacity:0.6; }
select,input[type="text"],input[type="number"],input[type="date"],textarea { font-family:inherit; }
input:focus,select:focus { outline:none;border-color:#3b82f6 !important;box-shadow:0 0 0 2px rgba(59,130,246,0.15); }
select:disabled { background:#f1f5f9;color:#1e293b;cursor:not-allowed;opacity:1; }
/* ── Tooltip on-hover para campos bloqueados ─────────────── */
.tip-lock { position:relative; }
.tip-lock::after {
  content: attr(data-tip);
  position:absolute; bottom:calc(100% + 5px); left:50%; transform:translateX(-50%);
  background:#1e293b; color:#f1f5f9; font-size:0.65rem; font-weight:500;
  padding:0.3rem 0.65rem; border-radius:6px; white-space:nowrap;
  pointer-events:none; opacity:0; transition:opacity 0.15s ease;
  z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.25);
}
.tip-lock:hover::after { opacity:1; }
/* ── Campos protegidos que el superadmin decidió tocar ────── */
.prot-activo { border-color:#f59e0b !important;background:#fffbeb !important; }
#aviso-protegidos { animation: aviso-in 0.25s ease; }
@keyframes aviso-in { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:none; } }
@keyframes spin-btn { to { transform: rotate(360deg); } }
</style>


@push('scripts')
<script>
/**
 * Baja del portal de Sura el certificado de afiliación al día.
 *
 * Es el mismo botón de Gestión ARL. Aquí hace falta porque al abrir un
 * contrato para revisarlo no hay razón para irse a otro módulo solo por el
 * PDF, y encontrar de nuevo a la persona allá cuesta más que el trámite.
 *
 * Puede tardar: si el certificado no se bajó ya hoy, el servidor abre una
 * sesión en el portal. Por eso el contador, que sin él el botón parece
 * congelado.
 */
async function descargarCertificadoArl(btn) {
    const texto = document.getElementById('btn-cert-arl-txt');
    const desde = Date.now();

    btn.disabled = true;
    const pintar = () => texto.textContent = `${Math.round((Date.now() - desde) / 1000)}s`;
    pintar();
    const reloj = setInterval(pintar, 1000);

    // El portal a veces tarda; se corta antes de que el navegador lo haga solo
    // y deje al usuario sin saber qué pasó.
    const corte  = new AbortController();
    const alarma = setTimeout(() => corte.abort(), 280000);

    try {
        const res = await fetch(`/admin/gestion-arl/{{ $contrato->id }}/certificado`, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            signal: corte.signal,
        });

        // El error viene en JSON; el certificado, en PDF.
        if (!res.ok || (res.headers.get('content-type') || '').includes('json')) {
            const datos = await res.json().catch(() => ({}));
            alert(datos.mensaje || 'No se pudo bajar el certificado.');
            return;
        }

        // El PDF vive en el disco `local` del servidor —lleva cédula, cargo y
        // salario—, así que llega en la respuesta y se guarda desde aquí.
        const nombre = (res.headers.get('content-disposition') || '')
            .match(/filename="?([^"]+)"?/)?.[1] || `certificado_arl_{{ $contrato->cedula }}.pdf`;
        const url = URL.createObjectURL(await res.blob());
        const a = document.createElement('a');
        a.href = url; a.download = nombre;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);
    } catch (e) {
        alert(e.name === 'AbortError'
            ? 'El portal de Sura no respondió a tiempo. Vuelve a intentarlo.'
            : 'Se perdió la conexión mientras se bajaba el certificado.');
    } finally {
        clearTimeout(alarma);
        clearInterval(reloj);
        texto.textContent = '';
        btn.disabled = false;
    }
}
</script>
@endpush

@push('scripts')
<script src="/js/modal_facturar_v2.js?v={{ time() }}"></script>
<script>
const MODALIDADES_MODO_ARL  = @json($modalidadesModoArl ?? []);
const MODALIDADES_ARL_LIBRE = @json($modalidadesArlLibre ?? []);
// {modalidad_id: [niveles]} — Estudiante K solo 1-3, ARL Tipo Y solo 4-5. Viene de
// TarifaAsesorService::NIVELES_ARL_POR_MODALIDAD, la misma que restringe el tarifario.
const NIVELES_ARL_POR_MODALIDAD = @json($nivelesArlPorModalidad ?? []);
const ARL_ID_RS             = {{ $arlIdRazonSocial ?? 'null' }};
// Cajas de cada razón social por departamento: {rs_id: [{departamento_id,
// departamento, caja_id, caja, principal}]}, la principal primero.
const CAJAS_RS      = @json((object) ($cajasRazonSocial ?? []));
const DEPT_CLIENTE  = {{ $deptCliente ?? 'null' }};
const SALARIO_MINIMO        = {{ $salarioMinimo ?? 0 }};
const PLAN_DATA             = {};
// URLs generadas por Laravel (incluyen subdirectorio correcto)
const URL_COTIZAR  = '{{ route('admin.contratos.cotizar') }}';
const URL_TARIFAS  = '{{ route('admin.contratos.tarifas') }}';
const URL_RADICADO = '{{ url('admin/contratos/api/radicado') }}';
// Datos del cliente para auto-selección de entidades
const CLIENTE_EPS_ID     = {{ $clienteEpsId ?? 'null' }};
const CLIENTE_PENSION_ID = {{ $clientePensionId ?? 'null' }};
// ── Filtrado inteligente: modalidades → planes ─────────────────────
const MODALIDAD_PLANES   = @json($planesPermitidos ?? []);
const MODALIDADES_INDEP          = @json($modalidadesIndependientes ?? [10, 13, 14]);
// Modalidades que no dependen de la razón social: a quien solo se le vende un seguro
// le da igual si la RS es de independientes o de empresa.
const MODALIDAD_SEGUROS          = {{ \App\Models\Contrato::MODALIDAD_SEGUROS }};
const MODALIDADES_SIN_FILTRO_RS  = [MODALIDAD_SEGUROS];
const CLIENTE_EXENTO_AFP         = {{ ($clienteExentoAfp ?? false) ? 'true' : 'false' }};
// Ya pensionado: no es que PUEDA omitir la AFP, es que no debe cotizarla.
// Por eso este caso no solo desactiva la regla de AFP obligatorio (como la edad
// o el documento extranjero), sino que además saca del selector los planes con AFP.
const CLIENTE_PENSIONADO         = {{ ($clientePensionado ?? false) ? 'true' : 'false' }};
@php
    $_planBd        = ($esEdicion ?? false) ? (int) old('plan_id', $contrato->plan_id ?? 0) : 0;
    $_planBdConAfp  = $_planBd && optional($planes->firstWhere('id', $_planBd))->incluye_pension;
@endphp
// El plan que trae guardado el contrato lleva AFP: con el cliente ya pensionado
// ese plan desaparece del selector, así que hay que avisar por qué quedó vacío.
const PLAN_BD_CON_AFP            = {{ $_planBdConAfp ? 'true' : 'false' }};
const CLIENTE_TIPO_DOC           = @json($clienteTipoDoc ?? null);
const ES_EDICION                 = {{ ($esEdicion ?? false) ? 'true' : 'false' }};
const RS_LOCK                    = {{ ($rsLock ?? false) ? 'true' : 'false' }};  // hay radicados → plan bloqueado
const PLAN_ORIGINAL_ID           = {{ ($rsLock && $esEdicion) ? (int)old('plan_id', $contrato->plan_id ?? 0) : 0 }};  // plan guardado en BD (inmutable)
// ── Superadmin sobre contrato con afiliaciones en trámite u OK ─────
// Los campos NO se bloquean, pero se confirma antes de guardar.
const RS_WARN                    = {{ ($rsWarn ?? false) ? 'true' : 'false' }};
@php
// Valores guardados en BD de los campos protegidos, para comparar en el submit
$_protegidos = [];
if ($esEdicion) {
    $_protegidos = [
        ['name' => 'razon_social_id',   'label' => 'Razón Social', 'valor' => (string) ($contrato->razon_social_id ?? '')],
        ['name' => 'plan_id',           'label' => 'Plan',         'valor' => (string) ($contrato->plan_id ?? '')],
        ['name' => 'tipo_modalidad_id', 'label' => 'Modalidad',    'valor' => (string) ($contrato->tipo_modalidad_id ?? '')],
        ['name' => 'fecha_ingreso',     'label' => 'F. Ingreso',   'valor' => $contrato->fecha_ingreso ? $contrato->fecha_ingreso->format('Y-m-d') : ''],
        ['name' => 'encargado_id',      'label' => 'Encargado',    'valor' => (string) ($contrato->encargado_id ?? '')],
    ];
}
@endphp
const VALORES_PROTEGIDOS = @json($_protegidos);
// 🔍 Debug AFP — revisar en consola del navegador
console.log('[AFP] tipo_doc en BD:', CLIENTE_TIPO_DOC, '| exento AFP:', CLIENTE_EXENTO_AFP);
// ── Regla AFP obligatorio ──────────────────────────────────────────────────
// Activa: planes sin AFP quedan deshabilitados para las modalidades indicadas
// (a menos que el cliente esté exento por tipo_doc o edad)
const REGLA_AFP_ACTIVA           = {{ ($reglaAfpActiva ?? false) ? 'true' : 'false' }};
const MODALIDADES_AFP_OBLIGATORIO = @json($modalidadesAfpObligatorio ?? [0, 10]);
@php
// Construir mapa de días TP antes de inyectarlo como JS
// factor_salario: fraccion del salario mínimo a usar como salario mensual.
// El factor sale de TipoModalidad::factorSalario() — misma fuente que usa la
// validación del backend, para que el piso del form y el del servidor coincidan.
$_modalidadesTPData = [];
foreach ($tiposModalidad as $_tm) {
    if ($_tm->esTiempoParcial()) {
        $_modalidadesTPData[$_tm->id] = [
            'dias_arl'       => 30,                // ARL siempre 30 días
            'dias_afp'       => $_tm->dias_afp,
            'dias_caja'      => $_tm->dias_caja,
            'factor_salario' => $_tm->factorSalario(),
        ];
    }
}
@endphp
const MODALIDADES_TP = {!! json_encode($_modalidadesTPData) !!};
@php
// Tipo E - Extras: aquí los días y el piso de salario los dice el PLAN, no la
// modalidad — "Solo CCF 14" vende media jornada del mes, así que su piso es
// medio mínimo. Misma fuente que TipoModalidad::factorSalario() en el backend.
$_planesExtras = [];
foreach ($planes as $_pl) {
    $_datos = \App\Models\TipoModalidad::PLANES_EXTRAS[$_pl->codigo] ?? null;
    if ($_datos) {
        $_planesExtras[$_pl->id] = [
            'codigo' => $_pl->codigo,
            'dias'   => $_datos['dias'],
            'factor' => \App\Models\TipoModalidad::FACTOR_SALARIO_POR_DIAS[$_datos['dias']] ?? 1.0,
            'api'    => $_datos['api'],
        ];
    }
}
@endphp
const MODALIDAD_EXTRAS = {{ \App\Models\TipoModalidad::ID_EXTRAS }};
const PLANES_EXTRAS    = {!! json_encode($_planesExtras) !!};
// Modalidades de tiempo parcial cuyos días NO están en el catálogo: los elige el
// contrato mes a mes (Tiempo Parcial Independiente, cotizante 76). En estas,
// dias_afp/dias_caja/factor_salario de MODALIDADES_TP vienen en null y salen del
// selector de semanas.
const MODALIDADES_DIAS_CONTRATO = @json($modalidadesDiasContrato ?? [18]);
// Fondo de Solidaridad (PSAP, cotizante 33): id de la modalidad, grupos con lo
// que paga cada uno, Colpensiones y el certificado de inscripción del cliente.
const FONDO_SOLIDARIDAD = @json($fondoSolidaridad ?? null);
// Pedido a la extensión BryNex Portales, portal del Fondo de Solidaridad (ver puente.js).
function fspExt(accion, datos = {}, limiteSeg = 60) {
    return new Promise((resolve) => {
        if (!document.documentElement.dataset.brynexPortales) {
            resolve({ ok: false, sinExtension: true, error: 'La extensión BryNex Portales no está instalada en este navegador.' });
            return;
        }
        const id = Date.now() + '-' + Math.random().toString(36).slice(2);
        const oyente = (ev) => {
            if (ev.source !== window || ev.data?.canal !== 'brynex-portales' || ev.data.tipo !== 'respuesta' || ev.data.id !== id) return;
            window.removeEventListener('message', oyente); clearTimeout(alarma);
            resolve(ev.data.respuesta || { ok: false, error: 'Respuesta vacía de la extensión.' });
        };
        window.addEventListener('message', oyente);
        const alarma = setTimeout(() => { window.removeEventListener('message', oyente); resolve({ ok: false, error: 'La extensión no respondió a tiempo.' }); }, limiteSeg * 1000);
        window.postMessage({ canal: 'brynex-portales', tipo: 'pedido', id, portal: 'fsp', accion, datos }, window.location.origin);
    });
}
// ¿El aliado tiene catálogo de seguros? Sin él no hay "Plan de seguro" que
// escoger y el valor se escribe a mano, así que no se puede ocultar.
const HAY_CATALOGO_SEGUROS = {{ ($segurosCatalogo ?? collect())->isNotEmpty() ? 'true' : 'false' }};
const FACTOR_POR_DIAS_TP = { 7: 0.25, 14: 0.50, 21: 0.75, 30: 1.00 };
const MODALIDAD_UPC  = 13; // Afiliar a alguien fuera del núcleo familiar: no depende de salario.
// Modalidades que pueden cotizar el mes en curso (Independientes, ARL Tipo Y, Exterior).
const MODALIDADES_MES_ACTUAL = @json($modalidadesMesActual ?? [8, 10, 14]);


// ── Formateador de miles para campos .campo-money ────────────────
function numRaw(el)  { return parseInt(el.value.replace(/\./g, '') || 0); }
function numFmt(v)   { return Math.round(v||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
function fmtMoney(el) {
    const raw = numRaw(el);
    el.dataset.raw = raw;
    el.value = raw > 0 ? numFmt(raw) : (raw === 0 ? '0' : '');
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.campo-money').forEach(el => {
        // Formatear al inicio
        const initRaw = parseInt(el.dataset.raw || el.value.replace(/\./g,'') || 0);
        el.dataset.raw = initRaw;
        el.value       = initRaw > 0 ? numFmt(initRaw) : '0';
        // En foco: mostrar numero limpio para editar
        el.addEventListener('focus', () => { el.value = el.dataset.raw || '0'; });
        // Al salir: formatear con puntos
        el.addEventListener('blur',  () => {
            el.dataset.raw = parseInt(el.value.replace(/\./g,'') || 0);
            el.value = numFmt(el.dataset.raw);
            // Si es el campo salario, actualizar IBC automáticamente
            if (el.id === 'inp_salario') {
                const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
                if (alpineComp) alpineComp.onSalarioChange();
            }
        });
        // Al tipear: mantener solo digitos
        el.addEventListener('input', () => {
            const pos = el.selectionStart;
            const raw = el.value.replace(/[^0-9]/g,'');
            el.value = raw;
            el.dataset.raw = raw || '0';
        });
    });
    // Antes de enviar el form: limpiar puntos de todos los campos money
    const form = document.getElementById('form-contrato');
    if (form) form.addEventListener('submit', () => {
        document.querySelectorAll('.campo-money').forEach(el => {
            el.value = el.dataset.raw || el.value.replace(/\./g,'') || '0';
        });
    }, true);
});


// Indexar NITs de ARL
const arlNitMap = {};
document.querySelectorAll('#sel_arl option[data-nit]').forEach(opt => {
    if (opt.dataset.nit) arlNitMap[opt.dataset.nit] = opt.value;
});

// Indexar data de planes
document.querySelectorAll('#sel_plan option[value]').forEach(opt => {
    if (opt.value) PLAN_DATA[opt.value] = {
        eps:   opt.dataset.eps  === '1',
        arl:   opt.dataset.arl  === '1',
        pen:   opt.dataset.pen  === '1',
        caja:  opt.dataset.caja === '1',
    };
});

// ── Caja según la razón social y el departamento del cliente ─────────────
// Pone arriba del selector las cajas de la razón social (la principal y las de
// otros departamentos) y escoge la del departamento del cliente; si la empresa
// no tiene caja allá, la principal. Solo llena la caja si estaba vacía o si la
// había puesto esta misma sugerencia: lo que el usuario escogió no se toca.
function sugerirCajaRazonSocial(rsId) {
    const sel  = document.getElementById('sel_caja');
    const hint = document.getElementById('hint-caja-rs');
    if (!sel) return;
    sel.querySelector('optgroup[data-rs]')?.remove();
    if (hint) { hint.style.display = 'none'; hint.innerHTML = ''; }

    const lista = CAJAS_RS[rsId] || [];
    if (!lista.length) return;

    const grupo = document.createElement('optgroup');
    grupo.label = '🏢 De la razón social';
    grupo.dataset.rs = '1';
    lista.forEach(c => {
        const o = new Option(c.caja + (c.departamento ? ' — ' + c.departamento : '') + (c.principal ? ' (principal)' : ''), c.caja_id);
        if (DEPT_CLIENTE && c.departamento_id === DEPT_CLIENTE) o.textContent = '★ ' + o.textContent;
        grupo.appendChild(o);
    });
    const valorAntes = sel.value || sel.dataset.valorPrevio || '';
    sel.insertBefore(grupo, sel.options[0]?.nextSibling || null);

    const delDpto   = DEPT_CLIENTE ? lista.find(c => c.departamento_id === DEPT_CLIENTE) : null;
    const sugerida  = delDpto || lista.find(c => c.principal) || null;

    if (sugerida && (!valorAntes || sel.dataset.cajaSugerida === '1')) {
        if (sel.disabled) {
            sel.dataset.valorPrevio = String(sugerida.caja_id);   // el plan no lleva caja: se repone al cambiarlo
        } else {
            sel.value = String(sugerida.caja_id);
            sel.style.cssText = STYLE_COMPLETO;
        }
        sel.dataset.cajaSugerida = '1';
    } else if (valorAntes) {
        sel.value = valorAntes;   // la misma caja, ahora desde el grupo de arriba
    }

    if (hint) {
        let txt;
        if (delDpto) {
            txt = `Cliente en <strong>${delDpto.departamento}</strong>: la razón social usa <strong>${delDpto.caja}</strong>.`;
        } else if (sugerida && DEPT_CLIENTE) {
            txt = `La razón social no tiene caja en el departamento del cliente; la principal es <strong>${sugerida.caja}</strong>.`;
        } else if (sugerida) {
            txt = `Caja principal de la razón social: <strong>${sugerida.caja}</strong>.`;
        }
        if (txt && lista.length > 1) {
            txt += '<br><span style="color:#94a3b8">' + lista.map(c => (c.departamento || '—') + ' → ' + c.caja).join(' · ') + '</span>';
        }
        if (txt) { hint.innerHTML = txt; hint.style.display = 'block'; }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('sel_caja');
    // Escoger la caja a mano apaga la sugerencia para esta razón social.
    sel?.addEventListener('change', () => { sel.dataset.cajaSugerida = '0'; });
    const rs = document.getElementById('sel_rs');
    if (rs && rs.value) sugerirCajaRazonSocial(rs.value);
});

function onRazonSocialChange(sel) {
    const opt    = sel.options[sel.selectedIndex];
    const arlNit = opt?.dataset?.arl || '';
    // Actualizar badge NIT
    const badgeNit = document.getElementById('badge-rs-nit');
    if (badgeNit) {
        const nit = opt?.dataset?.nit || '';
        if (nit && sel.value) {
            badgeNit.textContent = 'NIT ' + nit;
            badgeNit.style.display = 'inline';
        } else {
            badgeNit.style.display = 'none';
        }
    }
    if (arlNit && arlNitMap[arlNit]) {
        document.getElementById('sel_arl').value = arlNitMap[arlNit];
    }
    sugerirCajaRazonSocial(sel.value);
    // Resetear Plan al cambiar Razón Social SOLO en modo creación
    if (!ES_EDICION) {
        const selPlan = document.getElementById('sel_plan');
        if (selPlan) {
            selPlan.value = '';
            // Notificar a Alpine.js que cambió el plan
            const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
            if (alpineComp) { alpineComp.planId = ''; alpineComp.planNombre = ''; }
        }
    }
    // Filtrar modalidades según si la RS es independiente
    const esIndep = opt?.dataset?.independiente === '1';
    filtrarModalidades(esIndep);
    actualizarBloqueoArl();
    // Mostrar/ocultar panel Modo ARL según RS independiente o modalidad
    const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
    if (alpineComp) {
        const midId = parseInt(document.querySelector('select[name=tipo_modalidad_id]')?.value || 0);
        alpineComp.mostrarModoArl = MODALIDADES_MODO_ARL.includes(midId) || esIndep;
    }
    // Si la RS es independiente, auto-seleccionar "independiente" en Modo ARL y mostrar sub-panel
    if (esIndep) {
        const selModo = document.getElementById('sel_arl_modo');
        if (selModo && !selModo.value) {
            selModo.value = 'independiente';
        }
        mostrarPanelArlSegunModo('independiente');
    } else {
        // Si se cambia a RS no-independiente, ocultar sub-panel de cédula si el modo era independiente
        const selModo = document.getElementById('sel_arl_modo');
        if (selModo && selModo.value === 'independiente') {
            selModo.value = '';
            mostrarPanelArlSegunModo('');
        }
    }
    // Mostrar/ocultar panel Operador Planilla según independiente (independiente del ARL)
    const panelOp = document.getElementById('panel-operador-planilla');
    if (panelOp) panelOp.style.display = esIndep ? '' : 'none';
    // Actualizar NIT cotizante si ya hay un modo ARL seleccionado
    const modoSel = document.getElementById('sel_arl_modo');
    if (modoSel) sincronizarNitCotizante(modoSel.value, opt?.value || '');
}

/**
 * Se dispara cuando cambia el selector de Modo ARL.
 * Muestra el panel correcto (RS o cédula) y sincroniza el campo oculto.
 */
function onArlModoChange(sel) {
    mostrarPanelArlSegunModo(sel.value);
}

// ── Cargos de la razón social ────────────────────────────────────────
// Vive aquí, en el script que se renderiza siempre, y no junto al resto de la
// fila: estaba dentro del bloque condicional del modal de retiro (que solo se
// pinta en edición y con el contrato vigente), así que en un contrato nuevo
// —o retirado— el catálogo
// no existía y el campo se quedaba sin autocompletado y sin ajustar el N.ARL.
// El catálogo vive en razon_social_cargos y cada cargo trae su nivel de riesgo.
// Elegir uno de la lista ajusta el N.ARL solo, que es lo que determina el centro
// de trabajo al afiliar en ARL Sura. Escribir uno nuevo sigue permitido.
(function () {
    const inpCargo = document.getElementById('inp_cargo');
    const panel    = document.getElementById('lista_cargos');
    const selRS    = document.getElementById('sel_rs');
    if (!inpCargo || !panel) return;

    let cargos  = [];
    let marcado = -1;   // opción resaltada con las flechas

    async function cargarCargos() {
        // Sin razón social se piden igual: el catálogo común no depende de
        // ella, y antes la lista salía vacía en todo contrato nuevo, que es
        // justo cuando más sirve.
        const rsId = (selRS ? selRS.value : '{{ $contrato->razon_social_id ?? '' }}') || 0;
        cargos = [];

        try {
            const r = await fetch(`/admin/razones-sociales/${rsId}/cargos`, { headers: { 'Accept': 'application/json' } });
            cargos = ((await r.json()).cargos || [])
                .sort((a, b) => a.nivel_riesgo - b.nivel_riesgo || a.cargo.localeCompare(b.cargo));
        } catch (e) { /* sin catálogo se sigue escribiendo a mano */ }
    }

    const colorRiesgo = (n) => ['#64748b','#0d9488','#0891b2','#ca8a04','#ea580c','#b91c1c'][n] || '#64748b';

    function pintar(filtro) {
        const t = (filtro || '').trim().toUpperCase();
        const vistos = t ? cargos.filter(c => c.cargo.toUpperCase().includes(t)) : cargos;
        marcado = -1;

        if (! vistos.length) {
            panel.innerHTML = `<div style="padding:.5rem .6rem;font-size:.75rem;color:#94a3b8;">
                Sin cargos que coincidan. Puedes escribir uno nuevo.</div>`;
            return;
        }

        panel.innerHTML = vistos.map((c, i) => `
            <div class="op-cargo" data-i="${i}" data-cargo="${c.cargo.replace(/"/g,'&quot;')}"
                 style="display:flex;justify-content:space-between;gap:.5rem;padding:.4rem .6rem;
                        font-size:.78rem;cursor:pointer;border-bottom:1px solid #f1f5f9;">
                <span>${c.cargo}</span>
                <span style="color:${colorRiesgo(c.nivel_riesgo)};font-weight:700;font-size:.7rem;white-space:nowrap;">
                    riesgo ${c.nivel_riesgo}</span>
            </div>`).join('');

        panel.querySelectorAll('.op-cargo').forEach(el => {
            el.addEventListener('mousedown', (ev) => {   // antes del blur, si no el clic se pierde
                ev.preventDefault();
                elegir(el.dataset.cargo);
            });
            el.addEventListener('mouseenter', () => resaltar(Number(el.dataset.i)));
        });
    }

    function resaltar(i) {
        const ops = panel.querySelectorAll('.op-cargo');
        ops.forEach(o => o.style.background = '');
        marcado = i;
        if (ops[i]) {
            ops[i].style.background = '#eff6ff';
            ops[i].scrollIntoView({ block: 'nearest' });
        }
    }

    function abrir()  { pintar(inpCargo.value); panel.style.display = 'block'; inpCargo.setAttribute('aria-expanded','true'); }
    function cerrar() { panel.style.display = 'none'; inpCargo.setAttribute('aria-expanded','false'); }

    function elegir(cargo) {
        inpCargo.value = cargo;
        cerrar();
        ajustarRiesgo();
        inpCargo.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Elegir un cargo del catálogo mueve el N.ARL al riesgo que le corresponde.
    // No se anuncia en pantalla: el propio selector de riesgo ya muestra el cambio.
    function ajustarRiesgo() {
        const elegido = cargos.find(c => c.cargo.toUpperCase() === inpCargo.value.trim().toUpperCase());
        if (!elegido) return;

        const selArl = document.querySelector('select[name="n_arl"]');
        if (selArl && String(selArl.value) !== String(elegido.nivel_riesgo)) {
            selArl.value = String(elegido.nivel_riesgo);
            // x-model de Alpine escucha estos eventos: sin dispararlos, el
            // cálculo del contrato seguiría con el riesgo anterior.
            selArl.dispatchEvent(new Event('input',  { bubbles: true }));
            selArl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    inpCargo.addEventListener('focus', abrir);
    inpCargo.addEventListener('click', abrir);
    inpCargo.addEventListener('input', () => { abrir(); ajustarRiesgo(); });
    inpCargo.addEventListener('blur',  () => setTimeout(cerrar, 120));

    inpCargo.addEventListener('keydown', (ev) => {
        const ops = panel.querySelectorAll('.op-cargo');
        if (ev.key === 'ArrowDown')      { if (panel.style.display === 'none') abrir(); resaltar(Math.min(marcado + 1, ops.length - 1)); ev.preventDefault(); }
        else if (ev.key === 'ArrowUp')   { resaltar(Math.max(marcado - 1, 0)); ev.preventDefault(); }
        else if (ev.key === 'Enter')     { if (marcado >= 0 && ops[marcado]) { elegir(ops[marcado].dataset.cargo); ev.preventDefault(); } }
        else if (ev.key === 'Escape')    { cerrar(); }
    });

    if (selRS) selRS.addEventListener('change', cargarCargos);
    document.addEventListener('DOMContentLoaded', cargarCargos);
    if (document.readyState !== 'loading') cargarCargos();
})();

/**
 * Deja el NIT cotizante de la ARL en sincronía con el modo elegido cuando cambia
 * la razón social del contrato: por razón social se cotiza bajo una RS (por
 * defecto la del contrato) y como independiente bajo la cédula del cliente.
 *
 * Si el select de RS ARL ya tiene una elegida no se pisa: se puede estar
 * cotizando la ARL bajo una RS distinta a la del contrato a propósito.
 */
function sincronizarNitCotizante(modo, rsIdContrato) {
    const inpNit = document.getElementById('inp_arl_nit');
    if (!inpNit) return;

    if (modo === 'razon_social') {
        const selRsArl = document.getElementById('sel_arl_rs_cotizante');
        if (selRsArl && !selRsArl.value && rsIdContrato) selRsArl.value = rsIdContrato;
        inpNit.value = selRsArl?.value || rsIdContrato || '';
    } else if (modo === 'independiente') {
        inpNit.value = document.querySelector('input[name="cedula"]')?.value || '';
    }
    // Sin modo elegido no se toca: lo que hay guardado sigue siendo lo bueno, y
    // el backend lo vuelve a derivar al guardar si llega vacío.
}

/**
 * Se dispara cuando el usuario elige una RS en el select de RS ARL.
 * Guarda su ID (=NIT) en el campo oculto.
 */
function onArlRsChange(sel) {
    const inp = document.getElementById('inp_arl_nit');
    if (inp) inp.value = sel.value;
}

/**
 * Muestra/oculta los paneles de la segunda columna según el modo elegido:
 *  - razon_social  → panel con select de razones sociales
 *  - independiente → panel con cédula del cliente (readonly)
 *  - otro/vacío    → ambos ocultos
 * También sincroniza el campo oculto inp_arl_nit.
 */
function mostrarPanelArlSegunModo(modo) {
    const panelRs     = document.getElementById('panel_arl_rs');
    const panelCedula = document.getElementById('panel_arl_cedula');
    const inpNit      = document.getElementById('inp_arl_nit');

    if (!panelRs || !panelCedula) return;

    if (modo === 'razon_social') {
        panelRs.style.display     = 'block';
        panelCedula.style.display = 'none';
        // Sincronizar con la RS ya seleccionada en el select (si hay)
        const selRS = document.getElementById('sel_arl_rs_cotizante');
        if (inpNit) inpNit.value = selRS?.value || '';

    } else if (modo === 'independiente') {
        panelRs.style.display     = 'none';
        panelCedula.style.display = 'block';
        // Auto-llenar cédula del cliente
        const cedula = document.querySelector('input[name="cedula"]')?.value || '';
        const disp   = document.getElementById('disp_arl_cedula');
        if (disp) disp.value = cedula;
        if (inpNit) inpNit.value = cedula;

    } else {
        panelRs.style.display     = 'none';
        panelCedula.style.display = 'none';
        // No limpiar inp_arl_nit para no perder valores ya guardados
    }
}

// Almacén de todas las options originales (para restaurarlas)
const _OPTS_MODALIDAD = [];
const _OPTS_PLAN      = [];

// Inicializar stores — se llama antes de DOMContentLoaded,
// usar defer o llamar desde DOMContentLoaded
function _initOptsStore() {
    const selMod = document.getElementById('sel_modalidad');
    const selPln = document.getElementById('sel_plan');
    if (selMod && !_OPTS_MODALIDAD.length) {
        selMod.querySelectorAll('option').forEach(opt => {
            _OPTS_MODALIDAD.push({ el: opt, value: opt.value });
        });
    }
    if (selPln && !_OPTS_PLAN.length) {
        selPln.querySelectorAll('option').forEach(opt => {
            _OPTS_PLAN.push({ el: opt, value: opt.value, text: opt.textContent.trim() });
        });
    }
}

/**
 * Encadena los tres selectores: la Razón Social habilita la Modalidad, y la
 * Modalidad habilita el Plan. Sin la anterior no se puede tocar la siguiente,
 * porque cada una decide qué opciones tiene sentido mostrar en la que sigue —
 * elegir el plan primero era escoger de una lista que después se filtraba sola.
 *
 * Un campo que YA trae valor nunca se bloquea: hay contratos viejos guardados sin
 * razón social o sin modalidad, y un select deshabilitado no se envía al guardar,
 * así que bloquearlos les borraría el dato.
 */
function aplicarDependenciasSelects() {
    const selRS   = document.getElementById('sel_rs');
    const selMod  = document.getElementById('sel_modalidad');
    const selPlan = document.getElementById('sel_plan');
    if (!selMod || !selPlan) return;

    const bloquearMod  = !selRS?.value  && !selMod.value;
    const bloquearPlan = !selMod.value  && !selPlan.value;

    bloquearSelectDependiente(selMod, bloquearMod);
    // Con rsLock el plan ya viene deshabilitado del servidor y con su hidden
    // detrás: no se toca desde aquí.
    if (!RS_LOCK) bloquearSelectDependiente(selPlan, bloquearPlan);

    const notaMod = document.getElementById('nota-modalidad-rs');
    if (notaMod) notaMod.style.display = bloquearMod ? 'block' : 'none';
}

function bloquearSelectDependiente(sel, bloquear) {
    sel.disabled           = bloquear;
    sel.style.background   = bloquear ? '#f1f5f9' : '#fff';
    sel.style.cursor       = bloquear ? 'not-allowed' : '';
}

// ── Filtrado 1: RS → Modalidades ─────────────────────────────────
function filtrarModalidades(soloIndependiente, evitarRecalcular = false) {
    const selMod = document.getElementById('sel_modalidad');
    if (!selMod) return;

    const valorActual = selMod.value;
    // Limpiar select (excepto opción vacía)
    while (selMod.options.length > 0) selMod.remove(0);

    let primerValido = null;
    let valorSigueDisponible = false;

    _OPTS_MODALIDAD.forEach(({ el, value }) => {
        if (!value) {
            selMod.appendChild(el);  // siempre: opción vacía
            return;
        }
        const esIndepOpt = MODALIDADES_INDEP.includes(parseInt(value));
        const mostrar = MODALIDADES_SIN_FILTRO_RS.includes(parseInt(value))
                     || (soloIndependiente ? esIndepOpt : !esIndepOpt);
        if (mostrar) {
            selMod.appendChild(el);
            if (!primerValido) primerValido = value;
            if (value === valorActual) valorSigueDisponible = true;
        }
    });

    // Sincronizar valor seleccionado
    selMod.value = valorSigueDisponible ? valorActual : '';
    // Sincronizar Alpine. Solo cuando la modalidad que había dejó de estar
    // disponible: si venía vacía —contratos guardados sin modalidad— no cambió
    // nada, y borrar el plan solo hacía desaparecer de la pantalla el que ya
    // estaba guardado en la BD.
    if (!valorSigueDisponible && valorActual) {
        const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
        if (alpineComp) { alpineComp.tipoModalidadId = ''; alpineComp.planId = ''; }
    }
    // Filtrar planes y mostrar nota
    filtrarPlanes(selMod.value, evitarRecalcular);
    const divNota = document.getElementById('nota-plan-modalidad');
    if (divNota) divNota.style.display = (!selMod.value ? 'block' : 'none');
}

/**
 * Muestra el aviso mientras el plan elegido siga llevando AFP y el cliente esté
 * pensionado. Se llama desde filtrarPlanes (cambio de modalidad) y desde
 * onPlanChange: el aviso tiene que irse en cuanto se elija un plan sin pensión.
 */
function actualizarAvisoPensionado() {
    const aviso = document.getElementById('aviso-plan-pensionado');
    if (!aviso) return;
    const selPlan = document.getElementById('sel_plan');
    const opt     = selPlan?.options[selPlan.selectedIndex];
    // El aviso solo tiene sentido mientras algo esté mal: o el plan elegido lleva
    // AFP (pasa con rsLock, donde no se filtra), o el selector quedó vacío porque
    // el plan del contrato la llevaba. Con un plan sin pensión ya elegido, sobra.
    const planConAfp  = opt?.dataset?.pen === '1';
    const vacioPorAfp = PLAN_BD_CON_AFP && !selPlan?.value;
    if (!CLIENTE_PENSIONADO || (!planConAfp && !vacioPorAfp)) {
        aviso.style.display = 'none';
        return;
    }
    aviso.textContent = planConAfp
        ? '⚠️ Este plan incluye AFP y el cliente es pensionado.'
        : '⚠️ El plan que traía el contrato incluía AFP: elija uno sin pensión.';
    aviso.style.display = 'block';
}

// ── Filtrado 2: Modalidad → Planes ───────────────────────────────
function filtrarPlanes(modalidadId, evitarRecalcular = false) {
    const selPlan = document.getElementById('sel_plan');
    if (!selPlan) return;
    const divNota    = document.getElementById('nota-plan-modalidad');

    const valorActual = parseInt(selPlan.value) || 0;
    // Limpiar select
    while (selPlan.options.length > 0) selPlan.remove(0);

    if (!modalidadId) {
        // Sin modalidad: restaurar todas las opciones. Las de AFP igual se van si
        // el cliente ya está pensionado — la exclusión es del cliente, no de la
        // modalidad, así que no depende de haber elegido una.
        _OPTS_PLAN.forEach(({ el }) => selPlan.appendChild(el));
        // Reinsertar las options deja seleccionada la última: hay que devolver el
        // select al valor que traía (o a vacío), o el plan queda elegido a dedo
        // sin que nadie lo haya tocado.
        selPlan.value = valorActual ? String(valorActual) : '';
        if (divNota)    divNota.style.display    = 'block';
        aplicarDependenciasSelects();
        return;
    }

    const permitidos = (MODALIDAD_PLANES[modalidadId] || []).map(Number);
    const esTP       = !!MODALIDADES_TP[parseInt(modalidadId)];
    if (divNota) divNota.style.display = 'none';

    // ── Regla AFP obligatorio ──────────────────────────────────
    // Se activa si: la regla global está ON + modalidad en la lista + cliente NO exento
    const modalidadIdInt     = parseInt(modalidadId);
    const modalidadAfpOblig  = MODALIDADES_AFP_OBLIGATORIO.includes(modalidadIdInt);
    const aplicarAfpObligatorio = REGLA_AFP_ACTIVA && modalidadAfpOblig && !CLIENTE_EXENTO_AFP;


    const selRS      = document.getElementById('sel_rs');
    const esIndepRS  = selRS?.options[selRS.selectedIndex]?.dataset?.independiente === '1';
    const esIndepMod = MODALIDADES_INDEP.includes(modalidadIdInt);
    // Tiempo Parcial Independiente: es TP e independiente a la vez, y los dos
    // filtros de abajo fueron escritos suponiendo que eso no pasaba.
    const esTpIndep  = MODALIDADES_DIAS_CONTRATO.includes(modalidadIdInt);

    let planActualPermitido  = false;
    let planesAgregados      = [];

    _OPTS_PLAN.forEach(({ el, value, text }) => {
        if (!value) { selPlan.appendChild(el); return; }  // opción vacía siempre
        const planId = parseInt(value);
        let permitido = permitidos.includes(planId);
        
        // ── Excepción Tiempo Parcial ──
        // Si no hay planes configurados para esta modalidad TP, permitimos por defecto (y luego se filtran los de EPS)
        if (esTP && permitidos.length === 0) {
            permitido = true;
        }

        if (!permitido) return;

        // ── Filtro Tiempo Parcial específico ──────────
        // Para Tiempo Parcial, solo se permiten planes que NO tengan EPS y que SÍ tengan ARL y CCF
        // (por ejemplo: ARL+AFP+CCF o ARL+CCF). Los planes como Solo ARL o Solo AFP quedan excluidos.
        //
        // Salvo en el Tiempo Parcial Independiente (cotizante 76), donde la caja
        // es voluntaria por norma: ahí basta con que el plan no lleve EPS y sí
        // lleve ARL. Sin esta excepción el plan "ARL + AFP" se caía del selector.
        if (esTP && !esTpIndep && (el.dataset.eps === '1' || el.dataset.arl !== '1' || el.dataset.caja !== '1')) {
            return;
        }
        if (esTpIndep && (el.dataset.eps === '1' || el.dataset.arl !== '1')) {
            return;
        }

        // ── Fondo de Solidaridad: Solo AFP únicamente para el desempleado ──
        // El resto de grupos cotiza salud y pensión (EPS + AFP).
        if (FONDO_SOLIDARIDAD && modalidadIdInt === FONDO_SOLIDARIDAD.id
            && el.dataset.eps !== '1' && el.dataset.pen === '1') {
            const grupoFsp = document.getElementById('sel_grupo_fsp')?.value || '';
            if (!(FONDO_SOLIDARIDAD.grupos[grupoFsp]?.solo_afp)) return;
        }

        // ── Regla AFP obligatorio: ocultar planes sin AFP si aplica la regla ──
        // Un plan sin AFP tiene incluye_pension = false (data-pen !== '1')
        // Excepto si es el plan "Solo ARL" y la RS es independiente o la modalidad es independiente
        const esSoloArlPlan = el.dataset.arl  === '1'
            && el.dataset.eps  !== '1'
            && el.dataset.pen  !== '1'
            && el.dataset.caja !== '1';
        const exceptuarSoloArl = esSoloArlPlan && (esIndepRS || esIndepMod);
        if (aplicarAfpObligatorio && el.dataset.pen !== '1' && !exceptuarSoloArl) return;

        // ── Cliente pensionado: fuera los planes con AFP ────────────
        // Un pensionado ya no cotiza a pensión, así que ofrecerle un plan con AFP
        // es ofrecerle algo que no puede pagar. Con rsLock no se filtra: el plan
        // está bloqueado por los radicados y sacarlo solo lo guardaría vacío.
        if (CLIENTE_PENSIONADO && !RS_LOCK && el.dataset.pen === '1') return;

        // ── Filtro Independientes: NO pueden tener ARL+CCF ni ARL+AFP+CCF ──────────
        // Es decir, si no tienen EPS pero SÍ tienen Caja y ARL, se bloquea.
        // El Tiempo Parcial Independiente queda por fuera: ARL+AFP+CCF sin EPS es
        // justamente su plan completo (no cotiza salud y la caja es opcional).
        if (esIndepMod && !esTpIndep && el.dataset.eps !== '1' && el.dataset.arl === '1' && el.dataset.caja === '1') {
            return;
        }

        el.textContent = text;  // restaurar texto original

        selPlan.appendChild(el);
        planesAgregados.push(planId);
        if (planId === valorActual) planActualPermitido = true;
    });

    // ── Referencia del plan: cuando hay rsLock usamos PLAN_ORIGINAL_ID ──────
    // selPlan.value pudo haber sido limpiado por una selección incompatible
    // anterior, así que no es confiable. PLAN_ORIGINAL_ID siempre es el valor
    // guardado en BD y no cambia durante la sesión.
    const valorReferencia = RS_LOCK ? PLAN_ORIGINAL_ID : (valorActual || 0);
    // Recalcular si el plan de referencia está entre los permitidos
    let planReferenciaPermitido = planesAgregados.includes(valorReferencia);

    // Si hay rsLock: decisión basada en PLAN_ORIGINAL_ID (inmutable, no en selPlan.value)
    if (RS_LOCK) {
        const hiddenPlan = document.querySelector('input[type=hidden][name=plan_id]');
        const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
        if (planReferenciaPermitido && valorReferencia) {
            // Plan original compatible con la nueva modalidad → restaurar
            selPlan.value = String(valorReferencia);
            if (hiddenPlan) hiddenPlan.value = String(valorReferencia);
            if (alpineComp) { alpineComp.planId = String(valorReferencia); }
            bloquearEntidadesPorPlan(String(valorReferencia));
        } else {
            // Plan original NO compatible → limpiar
            selPlan.value = '';
            if (hiddenPlan) hiddenPlan.value = '';
            if (alpineComp) { alpineComp.planId = ''; }
        }
    } else if (valorActual && !planActualPermitido) {
        selPlan.value = '';
        const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
        if (alpineComp) { alpineComp.planId = ''; }
    } else {
        selPlan.value = valorActual || '';
        if (selPlan.value) bloquearEntidadesPorPlan(selPlan.value);
    }

    // ── Aviso: pensionado con el plan de AFP heredado del contrato ───
    actualizarAvisoPensionado();

    // ── Feedback visual inmediato cuando hay rsLock ──────────────────
    if (RS_LOCK) {
        const aviso      = document.getElementById('aviso-plan-incompatible');
        const selPlanEl  = document.getElementById('sel_plan');
        const hiddenPlan = document.querySelector('input[type=hidden][name=plan_id]');
        const planVacio  = !planReferenciaPermitido || !valorReferencia;
        if (aviso)     aviso.style.display   = planVacio ? 'block' : 'none';
        if (selPlanEl) selPlanEl.style.borderColor = planVacio ? '#dc2626' : '';
    }

    // ── Auto-selección: si es TP y solo hay 1 plan válido, seleccionarlo ──
    if (esTP && !selPlan.value && planesAgregados.length === 1) {
        selPlan.value = String(planesAgregados[0]);
        const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
        if (alpineComp) {
            alpineComp.planId = String(planesAgregados[0]);
            if (!evitarRecalcular) alpineComp.recalcular();
        }
        bloquearEntidadesPorPlan(String(planesAgregados[0]));
        // Actualizar hidden si rsLock
        if (RS_LOCK) {
            const hiddenPlan = document.querySelector('input[type=hidden][name=plan_id]');
            if (hiddenPlan) hiddenPlan.value = String(planesAgregados[0]);
        }
    }

    aplicarDependenciasSelects();
}

function actualizarBloqueoArl(evitarRecalcular = false) {
    const midId   = parseInt(document.querySelector('select[name=tipo_modalidad_id]')?.value || 0);
    const selRS   = document.getElementById('sel_rs');
    const arlSel  = document.getElementById('sel_arl');
    const lbl     = document.getElementById('lbl_arl_lock');

    // Libre por modalidad (Independiente Activo, Independiente Vencido, etc.)
    const libreParaModalidad = MODALIDADES_ARL_LIBRE.includes(midId);
    // Libre también cuando la Razón Social es del tipo Independiente (es_independiente=1)
    const esIndepRS = selRS?.options[selRS.selectedIndex]?.dataset?.independiente === '1';
    const libre = libreParaModalidad || esIndepRS;

    arlSel.disabled         = !libre;
    arlSel.style.background = libre ? '#fff' : '#f8fafc';
    if (lbl) lbl.textContent = libre ? '(editable)' : '(de la R.Social)';
    // Restricción de niveles ARL para modalidad id=8 con RS no-independiente
    actualizarNivelesArl(evitarRecalcular);
}

/**
 * Deja en el selector de riesgo ARL solo los niveles que admite la modalidad:
 * Estudiante K (−1) los riesgos 1-3 y ARL Tipo Y (8) los riesgos 4-5, porque son los dos
 * tipos de planilla PILA y cada uno cubre su rango. El resto de modalidades, los 5.
 *
 * Antes la restricción era solo para la modalidad 8 y únicamente si la razón social NO era
 * independiente; ahora aplica siempre, que es la regla real del negocio y la misma que usa
 * el tarifario (TarifaAsesorService::NIVELES_ARL_POR_MODALIDAD).
 */
function nivelesArlPermitidos(modalidadId) {
    const r = NIVELES_ARL_POR_MODALIDAD[modalidadId];
    return (Array.isArray(r) && r.length) ? r.map(Number) : [1, 2, 3, 4, 5];
}

function actualizarNivelesArl(evitarRecalcular = false) {
    const selMod    = document.querySelector('select[name=tipo_modalidad_id]');
    const selNivel  = document.querySelector('select[name=n_arl]');
    if (!selNivel) return;

    const modalidadId       = parseInt(selMod?.value || 0);
    const nivelesPermitidos = nivelesArlPermitidos(modalidadId);
    const restringido       = nivelesPermitidos.length < 5;

    // Guardar valor actual antes de manipular opciones
    const nivelActual = parseInt(selNivel.value || 1);

    // Limpiar y reconstruir opciones
    selNivel.innerHTML = '';
    nivelesPermitidos.forEach(n => {
        const opt = document.createElement('option');
        opt.value = n;
        opt.textContent = n;
        selNivel.appendChild(opt);
    });

    // Restaurar nivel si sigue siendo válido, si no usar el menor disponible
    if (nivelesPermitidos.includes(nivelActual)) {
        selNivel.value = nivelActual;
    } else {
        selNivel.value = nivelesPermitidos[0];
    }

    // Sincronizar Alpine x-model
    const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
    if (alpineComp) {
        alpineComp.nivelArl = parseInt(selNivel.value);
        if (!evitarRecalcular) alpineComp.recalcular();
    }

    // Indicador visual: borde ámbar cuando la modalidad recorta los niveles
    selNivel.style.border = restringido ? '2px solid #f59e0b' : '1px solid #cbd5e1';
    selNivel.title        = restringido
        ? `Esta modalidad solo admite riesgo ${nivelesPermitidos.join(' y ')}`
        : '';
}

// Estilos base para entidades
const STYLE_REQUERIDO  = 'width:100%;padding:0.38rem 0.5rem;border:2px solid #ef4444;border-radius:6px;font-size:0.82rem;background:#fff;box-sizing:border-box;';
const STYLE_DESHABILITADO = 'width:100%;padding:0.38rem 0.5rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.82rem;background:#f1f5f9;color:#94a3b8;box-sizing:border-box;cursor:not-allowed;';
const STYLE_NORMAL     = 'width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;background:#fff;box-sizing:border-box;';
const STYLE_COMPLETO   = 'width:100%;padding:0.38rem 0.5rem;border:2px solid #22c55e;border-radius:6px;font-size:0.82rem;background:#f0fdf4;box-sizing:border-box;';

function aplicarEstadoEntidad(sel, incluido) {
    if (!sel) return;
    if (!incluido) {
        // Deshabilitado: no aplica este plan. El valor se guarda antes de
        // limpiarlo porque pasar por un plan que no cubre la entidad no puede
        // costar el dato: al volver a un plan que sí la cubre el usuario no
        // siempre lo puede reponer (la ARL la manda la Razón Social y su
        // selector queda bloqueado).
        if (sel.value) sel.dataset.valorPrevio = sel.value;
        sel.disabled  = true;
        sel.required  = false;
        sel.style.cssText = STYLE_DESHABILITADO;
        sel.value     = '';   // limpiar
    } else {
        // Habilitado y requerido
        sel.disabled  = false;
        sel.required  = true;
        // Reponer lo que se limpió al pasar por un plan sin esta entidad.
        if (!sel.value && sel.dataset.valorPrevio) sel.value = sel.dataset.valorPrevio;
        // Color segun si tiene valor
        if (!sel.value) {
            sel.style.cssText = STYLE_REQUERIDO;
        } else {
            sel.style.cssText = STYLE_COMPLETO;
        }
    }
}

function bloquearEntidadesPorPlan(planId) {
    const d      = PLAN_DATA[planId] || null;
    const epsSel = document.getElementById('sel_eps');
    const penSel = document.getElementById('sel_pen');
    const arlSel = document.getElementById('sel_arl');
    const cajSel = document.getElementById('sel_caja');

    if (!d) {
        [epsSel,penSel,arlSel,cajSel].forEach(s => { if(s) { s.disabled=false; s.required=false; s.style.cssText=STYLE_NORMAL; } });
        return;
    }

    aplicarEstadoEntidad(epsSel, d.eps);
    aplicarEstadoEntidad(penSel, d.pen);
    aplicarEstadoEntidad(cajSel, d.caja);

    // ARL especial
    if (!d.arl) {
        aplicarEstadoEntidad(arlSel, false);
        // Sin ARL en el plan no hay aseguradora que escoger ni modo que mostrar,
        // pero el NIVEL DE RIESGO se deja editable: al cliente le pueden estar
        // pagando la ARL por otro lado y el nivel del cargo igual hay que saberlo.
        const panelModoArl = document.getElementById('panel-modo-arl');
        const selNivelArl  = document.querySelector('select[name=n_arl]');
        if (panelModoArl) panelModoArl.style.display = 'none';
        if (selNivelArl) {
            selNivelArl.disabled = false;
            selNivelArl.style.background = '';
            selNivelArl.style.opacity    = '';
            selNivelArl.style.cursor     = '';
        }
    } else {
        actualizarBloqueoArl();
        // La ARL de la Razón Social es el valor por defecto del contrato: si el
        // selector quedó vacío se repone, porque cuando está bloqueado nadie
        // más lo puede llenar y el guardado se quedaba trabado en rojo.
        if (!arlSel.value && ARL_ID_RS) arlSel.value = ARL_ID_RS;
        arlSel.required = true;
        arlSel.style.cssText = arlSel.value ? STYLE_COMPLETO : STYLE_REQUERIDO;
        // Restaurar panel Modo ARL y nivel ARL si la modalidad lo permite
        const panelModoArl = document.getElementById('panel-modo-arl');
        const selNivelArl  = document.querySelector('select[name=n_arl]');
        const midId = parseInt(document.querySelector('select[name=tipo_modalidad_id]')?.value || 0);
        if (panelModoArl) {
            // Solo mostrar el panel si la modalidad lo habilita (Alpine lo controla con x-show,
            // pero forzamos display para el caso en que Alpine ya lo tenía visible)
            const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
            if (alpineComp && alpineComp.mostrarModoArl) panelModoArl.style.display = '';
        }
        if (selNivelArl) {
            selNivelArl.disabled = false;
            selNivelArl.style.background = '';
            selNivelArl.style.opacity    = '';
            selNivelArl.style.cursor     = '';
        }
    }

    // % Caja: ocultar cuando el plan no incluye caja
    const divPctCaja = document.getElementById('div-pct-caja');
    if (divPctCaja) {
        // Solo aplica si estamos en modalidad independiente (de lo contrario Alpine ya lo oculta)
        const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
        const esIndep = alpineComp ? alpineComp.esIndependiente : false;
        if (esIndep) {
            divPctCaja.style.display = d.caja ? '' : 'none';
        }
    }

    // ── Helper: mostrar badge ⚠️ "Sin X guardada" en el área de badge ─────────
    function mostrarBadgePendiente(areaId, nombre) {
        const area = document.getElementById(areaId);
        if (!area) return;
        // Si el valor ya estaba guardado en BD (data-saved="1"), reemplazar con ⚠️ al cambiar
        area.innerHTML = '<span class="chip-ss" style="background:#fef3c7;color:#92400e;">'
            + '\u26a0\ufe0f Sin ' + nombre + ' guardada \u2014 seleccione y guarde</span>';
    }
    function limpiarBadge(areaId) {
        const area = document.getElementById(areaId);
        if (area) area.innerHTML = '';
    }

    // Auto-seleccionar entidades del cliente si el campo quedó habilitado pero vacío.
    // Aplica tanto en creación como en edición: si el contrato no tiene la entidad guardada,
    // pre-seleccionar la del cliente como sugerencia. El badge ⚠️ advierte al usuario
    // que debe guardar el contrato para que quede registrada correctamente.
    if (d.eps && epsSel && !epsSel.value && CLIENTE_EPS_ID) {
        epsSel.value = CLIENTE_EPS_ID;
        epsSel.style.cssText = STYLE_COMPLETO;
        mostrarBadgePendiente('badge-area-eps', 'EPS');
    }
    if (d.pen && penSel && !penSel.value && CLIENTE_PENSION_ID) {
        penSel.value = CLIENTE_PENSION_ID;
        penSel.style.cssText = STYLE_COMPLETO;
        mostrarBadgePendiente('badge-area-pen', 'AFP');
    }

    // Actualizar en tiempo real al seleccionar una entidad
    [epsSel, penSel, arlSel, cajSel].forEach(sel => {
        if (!sel) return;
        sel.onchange = function() {
            if (sel.required) {
                sel.style.cssText = sel.value ? STYLE_COMPLETO : STYLE_REQUERIDO;
            }
            // Al cambiar manualmente → badge ⚠️ (el valor aún no está guardado)
            if (sel === epsSel) {
                if (sel.value) mostrarBadgePendiente('badge-area-eps', 'EPS');
                else limpiarBadge('badge-area-eps');
            }
            if (sel === penSel) {
                if (sel.value) mostrarBadgePendiente('badge-area-pen', 'AFP');
                else limpiarBadge('badge-area-pen');
            }
            // Recalcular solo para ARL (afecta el %)
            if (sel === arlSel) {
                const alpineComp = document.querySelector('[x-data]')?._x_dataStack?.[0];
                if (alpineComp) alpineComp.recalcular();
            }
        };
    });

}

/**
 * Superadmin sobre un contrato con afiliaciones en trámite u OK: el formulario
 * se ve normal hasta que toca uno de los campos protegidos (data-prot). En ese
 * momento se revela el aviso y los campos quedan resaltados en ámbar.
 */
let _avisoProtegidosVisible = false;
function activarAvisoProtegidos() {
    if (_avisoProtegidosVisible) return;
    _avisoProtegidosVisible = true;

    const aviso = document.getElementById('aviso-protegidos');
    if (aviso) {
        aviso.style.display = 'block';
        aviso.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    document.querySelectorAll('[data-prot]').forEach(el => {
        el.classList.add('prot-activo');
        // Tooltip al pasar el mouse, igual que en los campos bloqueados
        const cont = el.parentElement;
        if (cont && !cont.classList.contains('tip-lock')) {
            cont.dataset.tip = '⚠️ Editable solo por ser superadmin — hay afiliaciones en trámite u OK';
            cont.classList.add('tip-lock');
        }
    });
}

function initAvisoProtegidos() {
    document.querySelectorAll('[data-prot]').forEach(el => {
        // mousedown cubre el clic sobre el select; focus cubre navegación por teclado
        el.addEventListener('mousedown', activarAvisoProtegidos);
        el.addEventListener('focus',     activarAvisoProtegidos);
    });
}

/**
 * Superadmin editando un contrato con afiliaciones en trámite u OK.
 * Compara los campos protegidos contra lo guardado en BD, lista lo que cambió
 * y pide confirmación explícita. Devuelve true si se puede guardar.
 */
function confirmarCambiosProtegidos(form) {
    // Texto legible de un valor: la etiqueta del <option> cuando aplica
    const etiqueta = (el, valor) => {
        const v = String(valor ?? '');
        if (!el || el.tagName !== 'SELECT') return v || '(vacío)';
        const opt = Array.from(el.options).find(o => o.value === v);
        return opt ? opt.text.trim() : (v || '(vacío)');
    };

    const cambios = [];
    VALORES_PROTEGIDOS.forEach(campo => {
        const el = form.querySelector('[name="' + campo.name + '"]');
        if (!el) return;
        const actual = String(el.value ?? '');
        if (actual === String(campo.valor ?? '')) return;
        cambios.push({
            label:  campo.label,
            esPlan: campo.name === 'plan_id',
            antes:  etiqueta(el, campo.valor),
            ahora:  etiqueta(el, actual),
        });
    });

    if (!cambios.length) return true;

    let msg = '⚠️ Este contrato tiene afiliaciones en trámite u OK.\n\n'
            + 'Va a modificar:\n\n'
            + cambios.map(c => '  • ' + c.label + ':\n        ' + c.antes + '\n     →  ' + c.ahora).join('\n\n')
            + '\n\n';

    if (cambios.some(c => c.esPlan)) {
        msg += 'Cambiar el PLAN altera las entidades cubiertas (EPS / AFP / ARL / Caja).\n'
             + 'Los radicados ya generados NO se anulan ni se corrigen ante las\n'
             + 'entidades: debe gestionarlos manualmente desde Afiliaciones.\n\n';
    }

    msg += 'El cambio quedará registrado en la bitácora a su nombre.\n\n¿Confirma que desea guardar?';

    return confirm(msg);
}

// Validacion al enviar el formulario
document.addEventListener('DOMContentLoaded', () => {
    if (RS_WARN) initAvisoProtegidos();
    const form = document.getElementById('form-contrato');
    if (form) {
        form.addEventListener('submit', function(e) {
            // ── Validación: modalidad incompatible con plan bloqueado ──
            // Si hay radicados (RS_LOCK), el plan está disabled y su valor viaja
            // por el hidden. Pero si la modalidad cambió a una incompatible,
            // filtrarPlanes() ya limpió el hidden → plan_id queda vacío.
            if (RS_LOCK) {
                const hiddenPlan = form.querySelector('input[type=hidden][name=plan_id]');
                const planVal    = hiddenPlan ? hiddenPlan.value : (document.getElementById('sel_plan')?.value || '');
                if (!planVal) {
                    e.preventDefault();
                    // Resaltar el select de plan
                    const selPlan = document.getElementById('sel_plan');
                    if (selPlan) selPlan.style.cssText += ';border-color:#dc2626!important;box-shadow:0 0 0 2px rgba(220,38,38,0.25)!important;';
                    // Mostrar aviso visual
                    const aviso = document.getElementById('aviso-plan-incompatible');
                    if (aviso) { aviso.style.display = 'block'; aviso.scrollIntoView({ behavior:'smooth', block:'nearest' }); }
                    return;
                }
            }

            const planId = document.getElementById('sel_plan')?.value;
            const d = PLAN_DATA[planId] || {};
            const checks = [
                { sel: 'sel_eps',   incl: d.eps,  nombre: 'EPS' },
                { sel: 'sel_pen',   incl: d.pen,  nombre: 'AFP/Pensión' },
                { sel: 'sel_arl',   incl: d.arl,  nombre: 'ARL' },
                { sel: 'sel_caja',  incl: d.caja, nombre: 'Caja' },
            ];
            let hayError = false;
            checks.forEach(({ sel, incl, nombre }) => {
                const el = document.getElementById(sel);
                if (!el || !incl) return;
                if (!el.value) {
                    el.style.cssText = STYLE_REQUERIDO;
                    hayError = true;
                }
            });
            if (hayError) {
                e.preventDefault();
                alert('Por favor seleccione todas las entidades requeridas por el plan (marcadas en rojo).');
                return;
            }

            // ── Confirmación del superadmin sobre campos protegidos ────
            // Se pregunta de último, cuando el resto del formulario ya es válido.
            if (RS_WARN && !confirmarCambiosProtegidos(form)) {
                e.preventDefault();
                return;
            }

            // ── La ARL bloqueada por la Razón Social sí tiene que viajar ────
            // Un <select disabled> no se envía en el POST, y el de ARL lo está
            // siempre que la Razón Social sea de empresa: sin esto el contrato
            // se guardaba una y otra vez con arl_id vacío. Se habilita en el
            // último momento, con el envío ya decidido.
            const arlSubmit = document.getElementById('sel_arl');
            if (arlSubmit && arlSubmit.disabled && d.arl && arlSubmit.value) {
                arlSubmit.disabled = false;
            }
        });
    }
});

/** Alpine del formulario, para poder llamarlo desde los handlers globales. */
function datosContrato() {
    return document.querySelector('[x-data]')?._x_dataStack?.[0] || null;
}

// ── Reparto de la afiliación: asesor ↔ empresa ───────────────────
// Son las dos caras de la misma moneda: costo − retiro − otros = asesor + empresa.
// Se edita cualquiera de las dos y la otra se ajusta. Solo viaja afiliacion_asesor.
// El vacío es significativo: '' = contrato sin tarifario, y 0 = el asesor no gana nada
// por la afiliación. La facturación los trata distinto, así que nunca convertir '' en 0.

/** Lee una de las dos casillas. Solo le quita el formato a la que se está tecleando:
 *  a la otra hay que dejarle sus puntos de miles. Devuelve null si está vacía. */
function repartoLeer(el) {
    if (el === document.activeElement) {
        const pos = el.selectionStart;
        el.value = el.value.replace(/[^0-9]/g, '');
        try { el.setSelectionRange(pos, pos); } catch (e) {}
    }
    const limpio = el.value.replace(/\./g, '');

    return limpio === '' ? null : (parseInt(limpio, 10) || 0);
}

/**
 * Al escoger un seguro del catálogo del aliado, copia su valor al campo "Seguro $".
 *
 * Es una precarga, no un candado: el precio puede editarse a mano después, porque lo
 * que se le cobra a esa persona vive en el contrato y no en el catálogo. Dejar el
 * selector en "Ninguno" pone el valor en cero.
 */
/**
 * La opción escogida se queda solo con el nombre del plan; las demás recuperan
 * su texto con precio para que la lista siga siendo útil al escoger.
 *
 * Sin esto el campo mostraba "Plan exequial 1 · $20.00…" cortado, repitiendo un
 * valor que ya se ve completo en la casilla Seguro $ de al lado.
 */
function etiquetaSeguroSeleccionado() {
    const sel = document.getElementById('sel_seguro');
    if (!sel) return;

    for (const op of sel.options) {
        if (!op.dataset.full) continue;   // la opción "-- Ninguno --" no se toca
        op.textContent = (op.selected && op.dataset.nombre) ? op.dataset.nombre : op.dataset.full;
    }
}

document.addEventListener('DOMContentLoaded', etiquetaSeguroSeleccionado);

function aplicarSeguroCatalogo(sel) {
    etiquetaSeguroSeleccionado();

    const inp = document.getElementById('inp_seguro');
    if (!inp) return;

    const valor = parseInt(sel.selectedOptions[0]?.dataset.valor || 0, 10) || 0;
    inp.value = numFmt(valor);
    inp.dataset.raw = valor;
    inp.dispatchEvent(new Event('input'));   // que la cotización de la derecha se entere

    // El listener de .campo-money deja el campo en dígitos pelados mientras se
    // teclea, y acaba de correr con el evento de arriba: el valor quedaría en
    // "20000" y no en "20.000" como el resto de los campos de plata. Se
    // reformatea después, salvo que el usuario lo tenga en foco escribiendo.
    if (document.activeElement !== inp) {
        inp.value = numFmt(valor);
        inp.dataset.raw = valor;
    }
}

/**
 * Rearma el costo de afiliación cada vez que se teclea una de las dos partes:
 *   afiliación empresa + afiliación asesor = costo_afiliacion (campo oculto)
 * El asesor vacío no suma y deja el contrato sin tarifario.
 */
function repartoAfiliacion(origen) {
    const ipA = document.getElementById('inp_afiliacion_asesor');
    const ipE = document.getElementById('inp_afiliacion_empresa');
    const ipC = document.getElementById('inp_costo');
    if (!ipA || !ipE || !ipC) return;

    // Se lee la que se está tecleando (queda en dígitos limpios) y también la otra.
    if (origen === 'asesor') repartoLeer(ipA); else if (origen === 'empresa') repartoLeer(ipE);

    const asesor  = repartoLeer(ipA) ?? 0;
    const empresa = repartoLeer(ipE) ?? 0;
    const costo   = empresa + asesor;

    ipC.value = costo;
    ipC.dataset.raw = costo;
    // Sin recalcular(): la cotización de la derecha es de seguridad social + admon y no
    // incluye la afiliación, así que llamarla aquí sería una consulta por cada tecla.
}

/** Precarga las dos casillas desde el tarifario. */
function repartoSincronizar(d, tieneAsesor) {
    const ipA = document.getElementById('inp_afiliacion_asesor');
    const ipE = document.getElementById('inp_afiliacion_empresa');
    const ipC = document.getElementById('inp_costo');
    if (!ipA || !ipE || !ipC) return;

    const costo = parseInt(ipC.dataset.raw || ipC.value || 0);
    const tiene = tieneAsesor && d && d.afiliacion_asesor !== undefined && d.afiliacion_asesor !== null;
    const asesor = tiene ? Math.round(d.afiliacion_asesor) : 0;

    // Sin asesor: 0. Con asesor pero sin tarifario: vacío, que es lo que deja la factura
    // en la regla anterior; poner 0 ahí le quitaría la comisión al asesor en silencio.
    ipA.value = tiene ? numFmt(asesor) : (tieneAsesor ? '' : '0');
    ipE.value = numFmt(Math.max(0, costo - asesor));
    repartoAfiliacion();
    repartoCandadoAsesor();
}

/**
 * Sin asesor escogido, la casilla "Afiliacion Asesor" queda bloqueada en 0.
 *
 * Al guardar un contrato sin asesor, el backend borra afiliacion_asesor (no hay a quién
 * pagarle) y el costo completo queda como afiliación de la empresa. Si la casilla se
 * dejaba escribir, lo digitado desaparecía en silencio: 45.000 + 5.000 volvían como
 * 50.000 de empresa. Si alguien ya había escrito un valor, se le devuelve a la empresa
 * para que el total no cambie.
 */
function repartoCandadoAsesor() {
    const ipA = document.getElementById('inp_afiliacion_asesor');
    const ipE = document.getElementById('inp_afiliacion_empresa');
    const sel = document.getElementById('sel_asesor');
    if (!ipA || !ipE || !sel) return;

    const sinAsesor = !sel.value;
    ipA.readOnly = sinAsesor;
    ipA.style.background = sinAsesor ? '#f1f5f9' : '';
    ipA.style.cursor = sinAsesor ? 'not-allowed' : '';
    ipA.title = sinAsesor ? 'Escoja primero un asesor para darle parte de la afiliación' : '';

    if (sinAsesor && (repartoLeer(ipA) ?? 0) > 0) {
        const total = (repartoLeer(ipE) ?? 0) + (repartoLeer(ipA) ?? 0);
        ipA.value = '0';
        ipE.value = numFmt(total);
        repartoAfiliacion();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const ipA = document.getElementById('inp_afiliacion_asesor');
    const ipE = document.getElementById('inp_afiliacion_empresa');
    if (!ipA || !ipE) return;

    repartoCandadoAsesor();

    // Al salir de la casilla se vuelve a poner el punto de miles; el vacío sigue vacío.
    [ipA, ipE].forEach(el => el.addEventListener('blur', () => {
        if (el.value !== '') el.value = numFmt(parseInt(el.value.replace(/\./g, ''), 10) || 0);
    }));

    // El backend distingue '' (sin tarifario) de 0; hay que mandarlo sin los puntos.
    document.getElementById('form-contrato')?.addEventListener('submit', () => {
        ipA.value = ipA.value === '' ? '' : ipA.value.replace(/\./g, '');
    }, true);
});

/**
 * Aviso bajo la fila de admon. El desglose ya no se escribe aquí: las dos casillas
 * (empresa y asesor) lo dicen solas. Queda solo lo que no se ve en ninguna casilla:
 * que este cliente ya se afilió antes y por eso al asesor le tocará la admon.
 */
function pintarAvisoTarifa(d, tieneAsesor) {
    const caja = document.getElementById('aviso_tarifa_asesor');
    if (!caja) return;

    if (!tieneAsesor || !d || !d.es_renovacion) {
        caja.style.display = 'none';
        caja.innerHTML = '';
        return;
    }

    caja.innerHTML = '<span style="color:#b45309;font-weight:700">↻ Este cliente ya tuvo una afiliación en esta modalidad:</span>'
                   + ' al facturar se le pagará al asesor la administración, no la comisión de afiliación.';
    caja.style.display = 'block';
}

function onAsesorChange(sel) {
    repartoCandadoAsesor();
    const datos = datosContrato();

    // Con plan y modalidad elegidos, el servidor resuelve todo el reparto (incluida la parte
    // de la afiliación, que antes no existía). Sin ellos se conserva el reparto local de siempre.
    if (datos && datos.planId) {
        datos.refrescarTarifas();
        return;
    }

    const opt      = sel.options[sel.selectedIndex];
    const admonAse = parseFloat(opt?.dataset?.admon || 0);
    const admonIp  = document.getElementById('inp_admon_asesor');
    const admonTot = document.getElementById('inp_admon');
    const afilAse  = document.getElementById('inp_afiliacion_asesor');
    if (!admonIp) return;

    if (!sel.value) {
        // Sin asesor: admon_asesor = 0, admon queda completo y no hay parte de afiliación.
        admonIp.value = 0;
        if (afilAse) afilAse.value = '';
        repartoSincronizar(null, false);
    } else {
        // Con asesor: admon_asesor = su comision, admon = admon_plan - comision
        admonIp.value = admonAse;
        repartoSincronizar(null, true);
        if (admonTot) {
            const admonActual = parseFloat(admonTot.value || 0);
            // Solo resta si el admon actual NO fue ya reducido (es mayor que admonAse)
            // Usamos el dataset del option del plan como referencia
            const planOpt = document.querySelector('#sel_plan option[value="'+ (document.getElementById('sel_plan')?.value||'') +'"]');
            const admonPlan = planOpt ? parseFloat(planOpt.dataset.admonPlan || admonActual) : admonActual;
            // Guardar el admon del plan en el option para futuras referencias
            if (planOpt && !planOpt.dataset.admonPlan) planOpt.dataset.admonPlan = admonActual;
            admonTot.value = Math.max(0, (planOpt?.dataset.admonPlan ? parseFloat(planOpt.dataset.admonPlan) : admonActual) - admonAse);
            // Actualizar Alpine
            const alpineData = datosContrato();
            if (alpineData) { alpineData.admon = parseFloat(admonTot.value); alpineData.recalcular(); }
        }
    }
}

function updateRadicado(id, campo, valor) {
    fetch(`${URL_RADICADO}/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ [campo]: valor })
    }).catch(console.error);
}

document.addEventListener('DOMContentLoaded', () => {
    // ── Inicializar stores de opciones (DEBE ser primero) ──────────
    _initOptsStore();

    // ── Aplicar filtros al cargar (modo edición) ──────────────────
    const selRS  = document.getElementById('sel_rs');
    const selMod = document.getElementById('sel_modalidad');
    const selPln = document.getElementById('sel_plan');

    if (selRS?.value) {
        const esIndepRS = selRS.options[selRS.selectedIndex]?.dataset?.independiente === '1';
        filtrarModalidades(esIndepRS, true);   // filtra modalidades sin disparar selectOnChange ni cotizar
    }
    if (selMod?.value) {
        filtrarPlanes(selMod.value, true);     // filtra planes sin limpiar selección ni cotizar
    }

    actualizarBloqueoArl(true);  // incluye actualizarNivelesArl() y evita cotizar
    // ⚡ Asignar ARL de la RS ANTES de bloquearEntidadesPorPlan para que el
    //    estilo se calcule con el valor ya presente (evita borde rojo falso).
    if (ARL_ID_RS && !document.getElementById('sel_arl').value) {
        document.getElementById('sel_arl').value = ARL_ID_RS;
    }
    const planId = selPln?.value;
    if (planId) bloquearEntidadesPorPlan(planId);

    // Nota inicial si no hay modalidad seleccionada
    if (!selMod?.value && document.getElementById('nota-plan-modalidad')) {
        document.getElementById('nota-plan-modalidad').style.display = 'block';
    }

    // Modalidad y Plan arrancan bloqueados mientras no exista lo anterior
    aplicarDependenciasSelects();

    // ── Inicializar paneles Modo ARL (modo edición: restaurar estado) ─
    const modoArlSel = document.getElementById('sel_arl_modo');
    if (modoArlSel?.value) {
        mostrarPanelArlSegunModo(modoArlSel.value);
    }

    // ── Mostrar Operador Planilla si la RS es independiente ────────
    const esIndepInit = selRS?.options[selRS.selectedIndex]?.dataset?.independiente === '1';
    const panelOpInit = document.getElementById('panel-operador-planilla');
    if (panelOpInit) panelOpInit.style.display = esIndepInit ? '' : 'none';

});

// ── Alpine.js cotizador ──────────────────────────────────────────
function cotizador() {
    return {
        salario:         {{ $defSalario }},
        ibc:             {{ $defIbc }},
        admon:           {{ $defAdmon }},
        seguro:          {{ $defSeguro }},
        // Qué seguro del catálogo se le vendió. Gobierna si se muestra el campo
        // del valor: sin plan escogido no hay monto que cobrar.
        seguroId:        '{{ old('seguro_id', $contrato->seguro_id ?? '') }}',
        nivelArl:        {{ (int)old('n_arl', $contrato->n_arl ?? 1) }},
        pctCaja:         {{ (float)old('porcentaje_caja', $contrato->porcentaje_caja ?? 2) }},
        planId:          '{{ $esEdicion ? old('plan_id', $contrato->plan_id ?? '') : '' }}',
        planNombre:      '',
        tipoModalidadId: '{{ old('tipo_modalidad_id', $contrato->tipo_modalidad_id ?? '') }}',
        pagaMesActual:   '{{ old('paga_mes_actual', ($contrato->paga_mes_actual ?? false) ? '1' : '0') }}',
        MODALIDADES_MES_ACTUAL: @json($modalidadesMesActual ?? [8, 10, 14]),
        esIndependiente: false,
        esTiempoParcial: false,
        // Tiempo parcial con los días en el contrato (cotizante 76): muestra el
        // selector de semanas y con él se arman IBC, piso de salario y cotización.
        esTpDiasContrato: false,
        diasTp:          {{ (int) old('dias_tp_afp', $contrato->dias_tp_afp ?? 7) }},
        // Fondo de Solidaridad (PSAP): grupo que fija la tarifa de pensión, la
        // pensión elegida (para el requisito de Colpensiones) y el certificado.
        esFondoSolidaridad: false,
        grupoFsp:        @json(old('grupo_fondo_solidaridad', $contrato->grupo_fondo_solidaridad ?? '')),
        pensionSel:      '',
        certificadoFsp:  FONDO_SOLIDARIDAD?.certificado || null,
        subiendoCertificadoFsp: false,
        fspTrayendo:     false,
        fspMensaje:      '',
        esUpc: false,
        // Solo seguro: sin entidades, sin salario y sin planilla. Lo único que se cobra
        // es el seguro, así que el panel de cotización no depende del salario.
        esSeguros: false,
        mostrarModoArl:  false,
        ibcSugFmt:       '',
        // Secuencia de cotizaciones en vuelo: cada tecla dispara un fetch y sin esto
        // la respuesta que llegue de ultimas pisa el estado aunque sea de un valor viejo
        // (el badge "sug:" y los totales quedaban mostrando el salario anterior).
        _reqSeq:         0,
        pctEps:0, pctPen:0, pctArl:0, pctCajaCalc:0,
        diasCotizar: 30,
        diasArl: 0, diasAfp: 0, diasCaja: 0,
        result: { eps:0, arl:0, pen:0, caja:0, ss:0, seguro:0, admon:0, iva:0, total:0 },

        fmt(v) {
            // Formato colombiano: punto de miles, sin decimales (ej: 1.750.905)
            const n = Math.round(v || 0);
            return '$' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        },

        init() {
            const opt = document.querySelector(`select[name=tipo_modalidad_id] option[value="${this.tipoModalidadId}"]`);
            this.esIndependiente = opt?.dataset.independiente === '1';
            this.esUpc = (parseInt(this.tipoModalidadId) === MODALIDAD_UPC);
            this.esSeguros = (parseInt(this.tipoModalidadId) === MODALIDAD_SEGUROS);
            // Mostrar panel Modo ARL si la modalidad lo requiere O si la RS es independiente
            const rsSelInit = document.getElementById('sel_rs');
            const rsEsIndepInit = rsSelInit?.options[rsSelInit.selectedIndex]?.dataset?.independiente === '1';
            this.mostrarModoArl  = MODALIDADES_MODO_ARL.includes(parseInt(this.tipoModalidadId)) || rsEsIndepInit;
            // Inicializar badge NIT de la RS seleccionada al cargar
            (function() {
                if (!rsSelInit) return;
                const optInit = rsSelInit.options[rsSelInit.selectedIndex];
                const nitInit = optInit?.dataset?.nit || '';
                const badgeNit = document.getElementById('badge-rs-nit');
                if (badgeNit && nitInit && rsSelInit.value) {
                    badgeNit.textContent = 'NIT ' + nitInit;
                    badgeNit.style.display = 'inline';
                }
            })();
            // Si la RS es independiente y no hay modo ARL guardado, auto-seleccionar "independiente"
            // Usar setTimeout para esperar que Alpine haya montado el panel en el DOM
            if (rsEsIndepInit) {
                setTimeout(() => {
                    const selModo = document.getElementById('sel_arl_modo');
                    if (selModo && !selModo.value) {
                        selModo.value = 'independiente';
                    }
                    // Siempre mostrar el sub-panel de cédula cuando RS es independiente
                    mostrarPanelArlSegunModo('independiente');
                }, 50);
            }
            this.planNombre      = document.querySelector(`#sel_plan option[value="${this.planId}"]`)?.textContent?.trim() || '';
            // Fondo de Solidaridad: la pensión se sigue para el requisito de Colpensiones.
            this.esFondoSolidaridad = !!FONDO_SOLIDARIDAD && parseInt(this.tipoModalidadId) === FONDO_SOLIDARIDAD.id;
            const selPenInit = document.getElementById('sel_pen');
            if (selPenInit) {
                this.pensionSel = selPenInit.value;
                selPenInit.addEventListener('change', e => { this.pensionSel = e.target.value; });
            }
            // Inicializar Tiempo Parcial al cargar
            this.esTpDiasContrato = MODALIDADES_DIAS_CONTRATO.includes(parseInt(this.tipoModalidadId));
            const tpData = this.tpDataDe(this.tipoModalidadId);
            this.esTiempoParcial = !!tpData;
            if (tpData) {
                this.diasArl  = tpData.dias_arl;
                this.diasAfp  = tpData.dias_afp;
                this.diasCaja = tpData.dias_caja;
            }
            // Sincronizar IBC según modalidad
            if (!this.esIndependiente) {
                this.setIbc(this.salario);
            } else if (this.salario > 0) {
                // En modo edición: si ya hay IBC guardado, respetar; si no, auto-calcular
                if (!this.ibc || this.ibc <= 0) {
                    this.calcularIbcIndependiente();
                } else {
                    // Solo actualizar el badge sugerido
                    const sug = this.ibcSugerido();
                    this.ibcSugFmt = this.fmt(sug);
                }
            }
            // Auto-calcular dias desde fecha_ingreso al iniciar
            this.calcularDiasDesde(document.querySelector('input[name=fecha_ingreso]')?.value);
            // Escuchar cambios en fecha_ingreso
            const fecInp = document.querySelector('input[name=fecha_ingreso]');
            if (fecInp) fecInp.addEventListener('change', e => {
                this.calcularDiasDesde(e.target.value);
                this.recalcular();
            });
            // Disparar cotizacion inicial después de que Alpine termine de montar
            // (UPC no depende de salario: se recalcula igual aunque esté en 0)
            setTimeout(() => {
                if ((this.salario > 0 || this.esUpc || this.esSeguros) && this.planId) this.recalcular();
            }, 300);
        },

        /**
         * Escribe el IBC en Alpine y en el campo visible a la vez.
         * El input ya no usa x-model (es un .campo-money con puntos de miles), asi que
         * cada asignacion tiene que mover tambien dataset.raw y el texto formateado.
         */
        setIbc(v) {
            const val = Math.round(v || 0);
            this.ibc = val;
            const el = document.getElementById('inp_ibc');
            if (el) {
                el.dataset.raw = val;
                el.value = val > 0 ? numFmt(val) : '';
            }
        },

        /** Mientras teclean: el valor real son los digitos, sin los puntos. */
        onIbcInput(e) {
            this.ibc = parseInt((e?.target?.value || '').replace(/\D/g, '') || 0);
            this.recalcular();
        },

        /**
         * Piso legal del IBC/salario para la modalidad activa.
         * Tiempo parcial cotiza sobre una fraccion del minimo (TP 7 = 25%), y UPC
         * no depende del salario, asi que no tiene piso. Misma regla que
         * TipoModalidad::salarioMinimoPermitido() en el backend.
         */
        pisoSalario() {
            const id = parseInt(this.tipoModalidadId || 0);
            // UPC y Seguros no cotizan sobre el salario, así que no tienen piso.
            if (id === MODALIDAD_UPC || id === MODALIDAD_SEGUROS) return 0;
            // Tipo E - Extras: el piso lo fija el plan, no la modalidad.
            if (id === MODALIDAD_EXTRAS) {
                const plan = PLANES_EXTRAS[parseInt(this.planId || 0)];
                return Math.round(SALARIO_MINIMO * (plan?.factor || 1));
            }
            const tp = this.tpDataDe(id);
            return Math.round(SALARIO_MINIMO * (tp?.factor_salario || 1));
        },

        /**
         * Días y factor de salario de una modalidad de tiempo parcial. En las
         * modalidades donde los días son del contrato (cotizante 76) el catálogo
         * los trae en null y se completan con el selector de semanas.
         */
        tpDataDe(idModalidad) {
            const id = parseInt(idModalidad || 0);
            const tp = MODALIDADES_TP[id];
            if (!tp) return null;
            if (!MODALIDADES_DIAS_CONTRATO.includes(id)) return tp;

            const dias = parseInt(this.diasTp) || 7;
            return {
                dias_arl:       30,   // la ARL del tiempo parcial siempre va por mes completo
                dias_afp:       dias,
                dias_caja:      dias,
                factor_salario: FACTOR_POR_DIAS_TP[dias] || 1,
            };
        },

        /** Cambió el bloque de semanas: se rehacen salario, IBC y cotización. */
        onDiasTpChange() {
            const tp = this.tpDataDe(this.tipoModalidadId);
            if (!tp) return this.recalcular();

            this.diasArl  = tp.dias_arl;
            this.diasAfp  = tp.dias_afp;
            this.diasCaja = tp.dias_caja;

            const salarioTP = Math.round(SALARIO_MINIMO * (tp.factor_salario || 1));
            this.salario    = salarioTP;
            this.setIbc(salarioTP);
            const inpSal = document.getElementById('inp_salario');
            if (inpSal) {
                inpSal.dataset.raw = salarioTP;
                inpSal.value       = numFmt(salarioTP);
            }

            return this.recalcular();
        },

        /** Calcula el IBC sugerido (40% del salario) con piso en el minimo de la modalidad */
        ibcSugerido() {
            // Leer salario real del campo money (puede estar en dataset.raw)
            const inpSal = document.getElementById('inp_salario');
            const salReal = parseInt(inpSal?.dataset?.raw || inpSal?.value?.replace(/\./g,'') || this.salario || 0);
            if (salReal > 0) this.salario = salReal;
            const raw = Math.round(this.salario * {{ $pctIbcSugerido }} / 100);
            return Math.max(raw, this.pisoSalario());
        },

        /**
         * Vuelta del calculo: el operador digita el IBC y el salario se deduce.
         * El IBC es el {{ $pctIbcSugerido }}% del ingreso, asi que el salario del que sale
         * ese IBC es ibc / {{ $pctIbcSugerido }}%. Asi las dos direcciones cuadran: escribir
         * el salario da ese IBC, y escribir el IBC devuelve ese salario.
         *
         * Corre en @change (al salir del campo), no en cada tecla: si no, al teclear
         * "1750905" el salario iria saltando digito por digito.
         */
        onIbcChange() {
            const piso = this.pisoSalario();
            let ibcVal = parseInt(this.ibc || 0);
            if (ibcVal <= 0) { this.recalcular(); return; }

            // El IBC nunca puede quedar bajo el piso legal de la modalidad.
            if (ibcVal < piso) {
                ibcVal   = piso;
                this.setIbc(piso);
            }

            const sal = Math.round(ibcVal * 100 / {{ $pctIbcSugerido }});
            this.salario = Math.max(sal, piso);

            // El salario vive en un .campo-money: hay que mover dataset.raw y el texto
            // formateado a mano, porque no lo maneja x-model.
            const inpSal = document.getElementById('inp_salario');
            if (inpSal) {
                inpSal.dataset.raw = this.salario;
                inpSal.value       = numFmt(this.salario);
            }

            this.ibcSugFmt = this.fmt(this.ibc);
            this.recalcular();
        },

        /**
         * Auto-llena el campo IBC con el valor sugerido:
         * = max(salario_minimo, salario * {{ $pctIbcSugerido }}%)
         * Actualiza Alpine + el <input> visible + el badge sugerido.
         */
        calcularIbcIndependiente() {
            const sug = this.ibcSugerido();
            this.setIbc(sug);
            this.ibcSugFmt = this.fmt(sug);
        },

        onSalarioChange() {
            // Leer el valor real del campo money (puede estar formateado con puntos)
            const inpSal = document.getElementById('inp_salario');
            const salReal = parseInt(inpSal?.dataset?.raw || inpSal?.value?.replace(/\./g,'') || 0);
            if (salReal > 0) this.salario = salReal;

            if (!this.esIndependiente) {
                // Dependiente: IBC siempre = salario
                this.setIbc(this.salario);
            } else if (this.salario > 0) {
                // Independiente: IBC = max(salario_minimo, salario * {{ $pctIbcSugerido }}%)
                this.calcularIbcIndependiente();
            } else {
                this.ibcSugFmt = '';
            }
            this.recalcular();
        },
        // Calcula dias a cotizar segun fecha_ingreso.
        // Replica la lógica del backend (FacturacionController::calcularDias):
        //   ① Mes de ingreso = mes actual → dias = 30 - dia + 1  (prop. mes actual)
        //   ② Mes de ingreso = mes anterior → dias = 30 - dia + 1 (primera planilla mes vencido)
        //      EXCEPCIÓN: I Act (id=11) ya cobró su primera planilla en el mes de ingreso,
        //      así que en el mes siguiente siempre son 30 días.
        //   ③ Cualquier otro mes → 30 (mes completo)
        // Esto permite que al facturar en mayo un contrato que ingresó el 24-abril,
        // se pre-seleccionen 7 días en lugar de 30.
        calcularDiasDesde(fechaStr, mesFacturar = null, anioFacturar = null) {
            // ── GESTIÓN ARL (id=15): días siempre = 0 ─────────────────────────
            // Los contratos ARL se facturan como afiliación cada mes.
            // No hay SS ni planilla, por ende días_cotizados = 0 siempre.
            if (parseInt(this.tipoModalidadId || 0) === 15) {
                this.diasCotizar = 0;
                const sel = document.getElementById('sel_dias_cotizar');
                if (sel) sel.value = 0;
                return;
            }
            if (!fechaStr) { this.diasCotizar = 30; return; }
            const fecha  = new Date(fechaStr + 'T00:00:00'); // evitar timezone
            
            const inpMes = document.getElementById('mf-mes');
            const inpAnio = document.getElementById('mf-anio');
            
            const targetMes  = mesFacturar !== null ? mesFacturar - 1 : (inpMes ? parseInt(inpMes.value) - 1 : new Date().getMonth());
            const targetAnio = anioFacturar !== null ? anioFacturar : (inpAnio ? parseInt(inpAnio.value) : new Date().getFullYear());

            const fAnio  = fecha.getFullYear();
            const fMes   = fecha.getMonth(); // 0-based

            // ① Ingresó en el mes de facturación seleccionado
            if (fAnio === targetAnio && fMes === targetMes) {
                const dia = fecha.getDate();
                this.diasCotizar = Math.max(1, 30 - dia + 1);
            }
            // ② Ingresó en el mes anterior al mes de facturación seleccionado
            else {
                const esIndAct   = parseInt(this.tipoModalidadId || 0) === 11;
                const mesAntMes  = targetMes === 0 ? 11 : targetMes - 1;
                const mesAntAnio = targetMes === 0 ? targetAnio - 1 : targetAnio;
                if (!esIndAct && fAnio === mesAntAnio && fMes === mesAntMes) {
                    const dia = fecha.getDate();
                    this.diasCotizar = Math.max(1, 30 - dia + 1);
                } else {
                    // ③ Mes normal (≥ 2 meses activo, o I Act en su 2do+ mes)
                    this.diasCotizar = 30;
                }
            }
            // Sincronizar el select
            const sel = document.getElementById('sel_dias_cotizar');
            if (sel) sel.value = this.diasCotizar;
        },


        onModalidadChange(e) {
            const opt = e.target.options[e.target.selectedIndex];
            const id  = parseInt(e.target.value || 0);
            this.esIndependiente = MODALIDADES_INDEP.includes(id);
            this.esUpc = (id === MODALIDAD_UPC);
            this.esSeguros = (id === MODALIDAD_SEGUROS);
            if (this.esSeguros) {
                // No cotiza nada: sin salario, sin IBC y sin entidades que escoger.
                this.salario = 0;
                this.setIbc(0);
                this.ibcSugFmt = '';
                const inpSalSeg = document.getElementById('inp_salario');
                if (inpSalSeg) { inpSalSeg.dataset.raw = 0; inpSalSeg.value = ''; }
            }
            // El mes actual solo existe en algunas modalidades: al salir de ellas
            // se desmarca, para no guardar un flag que la nueva no admite.
            if (! this.MODALIDADES_MES_ACTUAL.includes(id)) { this.pagaMesActual = '0'; }
            if (this.esUpc) {
                // UPC no depende de salario/IBC: el valor de EPS sale de la
                // edad/zona del beneficiario. Si venía de otra modalidad con
                // salario ya cargado, se limpia para no dejar un valor que
                // ya no significa nada en pantalla.
                this.salario = 0;
                this.setIbc(0);
                this.ibcSugFmt = '';
                const inpSalUpc = document.getElementById('inp_salario');
                if (inpSalUpc) { inpSalUpc.dataset.raw = 0; inpSalUpc.value = ''; }
            }
            // Mostrar panel Modo ARL si la modalidad lo requiere O si la RS es independiente
            const rsSelMC = document.getElementById('sel_rs');
            const rsEsIndepMC = rsSelMC?.options[rsSelMC.selectedIndex]?.dataset?.independiente === '1';
            this.mostrarModoArl  = MODALIDADES_MODO_ARL.includes(id) || rsEsIndepMC;
            this.pctCaja = this.esIndependiente ? 2 : 4;
            // Tiempo Parcial
            this.esTpDiasContrato = MODALIDADES_DIAS_CONTRATO.includes(id);
            const tpData = this.tpDataDe(id);
            this.esTiempoParcial = !!tpData;
            if (tpData) {
                this.diasArl  = tpData.dias_arl;
                this.diasAfp  = tpData.dias_afp;
                this.diasCaja = tpData.dias_caja;
                // ── Auto-rellenar Salario e IBC según factor del plan ──
                const factor     = tpData.factor_salario || 1;
                const salarioTP  = Math.round(SALARIO_MINIMO * factor);
                this.salario     = salarioTP;
                this.setIbc(salarioTP);
                // Actualizar el campo .campo-money del salario
                const inpSal = document.getElementById('inp_salario');
                if (inpSal) {
                    inpSal.dataset.raw = salarioTP;
                    inpSal.value       = numFmt(salarioTP);
                }
            } else {
                this.diasArl = this.diasAfp = this.diasCaja = 0;
                // ── Salir de Tiempo Parcial: restaurar el salario al mínimo ──
                // Sin esto el salario quedaba pegado en la fracción del SMMLV
                // (ej. 437.726 al venir de TP 7) y se guardaba un dependiente
                // cotizando sobre un cuarto del mínimo.
                if (!this.esUpc && !this.esSeguros && this.salario < SALARIO_MINIMO) {
                    this.salario = SALARIO_MINIMO;
                    const inpSalTC = document.getElementById('inp_salario');
                    if (inpSalTC) {
                        inpSalTC.dataset.raw = SALARIO_MINIMO;
                        inpSalTC.value       = numFmt(SALARIO_MINIMO);
                    }
                }
            }
            // ── Fondo de Solidaridad: un salario mínimo fijo y Colpensiones ──
            this.esFondoSolidaridad = !!FONDO_SOLIDARIDAD && id === FONDO_SOLIDARIDAD.id;
            if (this.esFondoSolidaridad) {
                this.salario = SALARIO_MINIMO;
                const inpSalFsp = document.getElementById('inp_salario');
                if (inpSalFsp) { inpSalFsp.dataset.raw = SALARIO_MINIMO; inpSalFsp.value = numFmt(SALARIO_MINIMO); }
                const selPen = document.getElementById('sel_pen');
                if (selPen && FONDO_SOLIDARIDAD.colpensionesId) {
                    selPen.value = String(FONDO_SOLIDARIDAD.colpensionesId);
                    this.pensionSel = selPen.value;
                }
            }
            if (!this.esIndependiente) {
                // Dependiente: IBC = salario
                this.setIbc(this.salario);
                this.ibcSugFmt = '';
            } else if (this.esFondoSolidaridad) {
                // Sin sugerencia del 40 %: el IBC es el mínimo, punto.
                this.setIbc(SALARIO_MINIMO);
                this.ibcSugFmt = '';
            } else if (this.salario > 0) {
                // Al pasar a independiente: auto-calcular IBC
                this.calcularIbcIndependiente();
            }
            actualizarBloqueoArl();
            filtrarPlanes(e.target.value);
            // Recalcular días según la nueva modalidad (especialmente ARL → 0 días)
            this.calcularDiasDesde(document.querySelector('input[name=fecha_ingreso]')?.value);
            // El tarifario cambia por modalidad, no solo por plan.
            this.refrescarTarifas();
            this.recalcular();
        },


        /** Datos del grupo elegido del Fondo de Solidaridad (tarifa, valores, subsidio). */
        get grupoFspDatos() {
            return (this.esFondoSolidaridad && FONDO_SOLIDARIDAD?.grupos?.[this.grupoFsp]) || null;
        },

        /** ¿El plan elegido es Solo AFP? En el cotizante 33 hoy no liquida. */
        get esPlanSoloAfp() {
            if (!this.esFondoSolidaridad || !this.planId) return false;
            const d = document.querySelector(`#sel_plan option[value="${this.planId}"]`)?.dataset;
            return !!d && d.pen === '1' && d.eps !== '1' && d.arl !== '1' && d.caja !== '1';
        },

        /**
         * Cambió el grupo: cambia la tarifa de pensión y el plan que le toca.
         * El desempleado arranca en Solo AFP y el resto en EPS + AFP; se puede
         * cambiar a mano después. Solo corre cuando el usuario cambia el grupo:
         * al abrir un contrato guardado se respeta el plan que ya tiene.
         */
        onGrupoFspChange(e) {
            // El @change corre antes de que x-model actualice grupoFsp: sin esto
            // la cotización salía con el grupo anterior.
            if (e?.target) this.grupoFsp = e.target.value;
            filtrarPlanes(this.tipoModalidadId, true);

            const soloAfp = !!FONDO_SOLIDARIDAD?.grupos?.[this.grupoFsp]?.solo_afp;
            const selPlan = document.getElementById('sel_plan');
            const opcion  = [...(selPlan?.options || [])].find(o => {
                const d = o.dataset;
                return o.value && d.pen === '1' && d.arl !== '1' && d.caja !== '1'
                    && (soloAfp ? d.eps !== '1' : d.eps === '1');
            });

            if (opcion && selPlan.value !== opcion.value) {
                selPlan.value = opcion.value;
                this.planId   = opcion.value;
                const hiddenPlan = document.querySelector('input[type=hidden][name=plan_id]');
                if (hiddenPlan) hiddenPlan.value = opcion.value;
                // Mismo camino que elegir el plan a mano: entidades, avisos y tarifas
                // (que a su vez recalcula la cotización).
                this.onPlanChange({ target: selPlan });
                return;
            }

            this.recalcular();
        },

        /**
         * Sube el certificado del PSAP a los documentos del cliente sin salir del
         * formulario. Se baja a mano del Fondo porque tiene captcha.
         */
        subirCertificadoFsp(e) {
            const archivo = e.target.files?.[0];
            if (!archivo) return;
            this.guardarCertificadoFsp(archivo)
                .catch(err => alert(err.message))
                .finally(() => { e.target.value = ''; });
        },

        /** Guarda el certificado en los documentos del cliente y marca el requisito. */
        guardarCertificadoFsp(archivo) {
            if (!FONDO_SOLIDARIDAD?.urlSubirCertificado) return Promise.reject(new Error('Guarde primero el cliente.'));

            const datos = new FormData();
            datos.append('archivo', archivo);
            datos.append('tipo_documento', 'certificado_fsp');

            this.subiendoCertificadoFsp = true;
            return fetch(FONDO_SOLIDARIDAD.urlSubirCertificado, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: datos,
            })
            .then(r => r.json().then(j => ({ ok: r.ok, j })))
            .then(({ ok, j }) => {
                if (!ok) throw new Error(j?.message || 'No se pudo subir el certificado.');
                const hoy = new Date();
                this.certificadoFsp = {
                    fecha: hoy.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' }),
                    url: j?.url || null,
                };
            })
            .finally(() => { this.subiendoCertificadoFsp = false; });
        },

        /**
         * Pide el certificado al Fondo con la extensión BryNex Portales: abre la
         * consulta con la cédula escrita, espera a que la persona marque el
         * captcha y guarda el PDF que devuelve. Sin extensión el enlace sigue su
         * curso y abre la página vacía.
         */
        async traerCertificadoFsp(e) {
            if (!document.documentElement.dataset.brynexPortales || !FONDO_SOLIDARIDAD?.documento) return;
            e.preventDefault();
            if (this.fspTrayendo) return;

            this.fspMensaje  = '';
            this.fspTrayendo = true;
            const datos = { tipoDoc: FONDO_SOLIDARIDAD.tipoDoc, documento: FONDO_SOLIDARIDAD.documento };

            const abierta = await fspExt('fspAbrir', datos, 60);
            if (!abierta.ok) { this.fspTrayendo = false; this.fspMensaje = abierta.error || 'No se pudo abrir la consulta del Fondo.'; return; }

            // Hasta 5 minutos para marcar el captcha.
            const limite = Date.now() + 5 * 60 * 1000;
            while (this.fspTrayendo && Date.now() < limite) {
                await new Promise(r => setTimeout(r, 2000));
                if (!this.fspTrayendo) return;   // canceló

                const r = await fspExt('fspCertificado', { documento: FONDO_SOLIDARIDAD.documento }, 60);
                if (!r.ok) { this.fspTrayendo = false; this.fspMensaje = r.error || 'La extensión no respondió.'; return; }
                if (!r.listo) continue;

                this.fspTrayendo = false;
                if (!r.pdf) { this.fspMensaje = 'El Fondo dice: ' + (r.mensaje || 'no entregó el certificado.'); return; }

                const bytes = Uint8Array.from(atob(r.pdf), c => c.charCodeAt(0));
                const archivo = new File([bytes], r.nombre || `certificado_psap_${FONDO_SOLIDARIDAD.documento}.pdf`, { type: 'application/pdf' });
                try { await this.guardarCertificadoFsp(archivo); }
                catch (err) { this.fspMensaje = err.message; }
                return;
            }
            if (this.fspTrayendo) { this.fspTrayendo = false; this.fspMensaje = 'Pasaron 5 minutos sin marcar el captcha. Vuelve a intentarlo.'; }
        },

        onPlanChange(e) {
            this.planNombre = e.target.options[e.target.selectedIndex]?.textContent?.trim() || '';
            bloquearEntidadesPorPlan(this.planId);
            actualizarAvisoPensionado();
            // En Tipo E - Extras el plan cambia el piso del salario ("Solo CCF
            // 14" vende media jornada), así que el salario se acomoda solo en
            // vez de quedar por debajo y reventar al guardar.
            if (parseInt(this.tipoModalidadId || 0) === MODALIDAD_EXTRAS) this.ajustarSalarioAlPiso();
            this.refrescarTarifas();
        },

        /**
         * Sube el salario al piso de la combinación actual si quedó por debajo.
         *
         * En un plan de media jornada ("Solo CCF 14") el salario no es un piso
         * sino el valor exacto: el plano lo toma tal cual como IBC de la caja
         * (ver PilaCotizanteDosPasos), así que un salario completo pegado de
         * antes haría cobrar y liquidar el doble. Ahí se fija, igual que el
         * Tiempo Parcial fija su fracción del mínimo.
         */
        ajustarSalarioAlPiso() {
            const piso = this.pisoSalario();
            const plan = PLANES_EXTRAS[parseInt(this.planId || 0)];
            const fraccion = parseInt(this.tipoModalidadId || 0) === MODALIDAD_EXTRAS && (plan?.factor || 1) < 1;
            const actual = parseInt(this.salario) || 0;
            if (!piso || (fraccion ? actual === piso : actual >= piso)) return;

            this.salario = piso;
            this.setIbc(piso);
            const inpSal = document.getElementById('inp_salario');
            if (inpSal) {
                inpSal.dataset.raw = piso;
                inpSal.value       = numFmt(piso);
            }
        },

        /**
         * Pide al servidor las tarifas de la combinación actual (plan + modalidad + riesgo +
         * asesor) y las precarga. Se llama al cambiar cualquiera de esos cuatro, porque el
         * tarifario puede tener un precio distinto por cada uno.
         */
        refrescarTarifas() {
            if (!this.planId) return Promise.resolve();

            const asesorSel = document.getElementById('sel_asesor');
            const cedula    = document.querySelector('input[name=cedula]')?.value || '';

            const params = new URLSearchParams({ plan_id: this.planId, n_arl: this.nivelArl || 1 });
            // Sin modalidad el servidor responde el formato viejo: el formulario sigue igual.
            if (this.tipoModalidadId !== '' && this.tipoModalidadId !== null && this.tipoModalidadId !== undefined) {
                params.set('tipo_modalidad_id', this.tipoModalidadId);
            }
            if (asesorSel?.value) params.set('asesor_id', asesorSel.value);
            if (cedula)           params.set('cedula', cedula);

            return fetch(`${URL_TARIFAS}?${params.toString()}`)
                .then(r => r.json())
                .then(d => this.aplicarTarifas(d))
                .catch(() => this.recalcular());
        },

        aplicarTarifas(d) {
            const f           = document.getElementById('form-contrato');
            const asesorSel   = document.getElementById('sel_asesor');
            const asesorOpt   = asesorSel?.options[asesorSel.selectedIndex];
            const admonAseOpt = parseFloat(asesorOpt?.dataset?.admon || 0);
            const tieneAsesor = !!(asesorSel?.value && parseFloat(asesorSel.value) > 0);

            // Seguro y costo: solo se pisan si la API trae un valor real, para no borrar
            // lo que el usuario ya escribió a mano.
            if (d.seguro > 0) {
                const inpSeg = document.getElementById('inp_seguro');
                if (inpSeg) { inpSeg.dataset.raw = d.seguro; inpSeg.value = numFmt(d.seguro); }
                this.seguro = d.seguro;
            }
            if (d.costo_afiliacion > 0) {
                const inpCosto = document.getElementById('inp_costo');
                if (inpCosto) { inpCosto.dataset.raw = d.costo_afiliacion; inpCosto.value = numFmt(d.costo_afiliacion); }
            }
            if (d.encargado_id && f?.elements['encargado_id']) f.elements['encargado_id'].value = d.encargado_id;

            // Admon. Con el tarifario nuevo el servidor ya manda separada la parte de la
            // empresa (admon_total − asesor); sin él se conserva el reparto de siempre.
            const tarifarioFino = (d.admon_total !== undefined && d.admon_total !== null);
            const admonTotal    = tarifarioFino ? d.admon_total : d.administracion;

            if (admonTotal > 0) {
                const admonEmpresa = tarifarioFino
                    ? d.administracion
                    : (tieneAsesor ? Math.max(0, d.administracion - admonAseOpt) : d.administracion);

                const planOpt = document.querySelector('#sel_plan option[value="' + this.planId + '"]');
                if (planOpt) planOpt.dataset.admonPlan = admonTotal;

                this.admon = admonEmpresa;
                const inpAdmon = document.getElementById('inp_admon');
                if (inpAdmon) { inpAdmon.dataset.raw = admonEmpresa; inpAdmon.value = numFmt(admonEmpresa); }
            }

            if (admonTotal > 0 || tieneAsesor) {
                const inpAdmonAse = document.getElementById('inp_admon_asesor');
                const valAdmonAse = tieneAsesor ? (d.admon_asesor ?? admonAseOpt) : 0;
                if (inpAdmonAse) { inpAdmonAse.dataset.raw = valAdmonAse; inpAdmonAse.value = numFmt(valAdmonAse); }
            }

            // Parte del asesor en la afiliación y lo que le queda a la empresa. Vacío (no 0)
            // cuando no hay asesor o no hay tarifario: ese vacío es el que deja la facturación
            // en su lógica anterior.
            repartoSincronizar(d, tieneAsesor);

            pintarAvisoTarifa(d, tieneAsesor);
            this.recalcular();
        },

        recalcular() {
            const cedula  = document.querySelector('input[name=cedula]')?.value || '';
            // Leer salario del campo money (dataset.raw) o del Alpine state
            const salRaw  = parseInt(document.getElementById('inp_salario')?.dataset.raw || this.salario || 0);
            // El campo del seguro dispara recalcular() pero no tiene x-model, así
            // que su valor nunca llegaba al estado y la cotización lo mandaba en
            // cero: el seguro no se sumaba al total. Se lee del DOM, igual que el
            // salario, que ya resolvía lo mismo dos líneas arriba.
            const segRaw  = parseInt(document.getElementById('inp_seguro')?.dataset.raw || this.seguro || 0);
            if (segRaw !== this.seguro) this.seguro = segRaw;
            if (salRaw > 0 && salRaw !== this.salario) this.salario = salRaw;
            const ibcVal  = (this.esIndependiente && this.ibc > 0) ? this.ibc : (salRaw || this.salario);
            // UPC no depende de salario (el valor sale de la edad/zona del beneficiario).
            if (!this.planId || (!this.salario && !this.esUpc && !this.esSeguros)) return Promise.resolve();
            const seq = ++this._reqSeq;
            return fetch(URL_COTIZAR, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    tipo_modalidad_id: this.tipoModalidadId,
                    plan_id:           this.planId,
                    n_arl:             this.nivelArl,
                    salario:           this.salario,
                    ibc:               ibcVal,
                    administracion:    this.admon || 0,
                    admon_asesor:      parseInt(document.getElementById('inp_admon_asesor')?.dataset?.raw || document.getElementById('inp_admon_asesor')?.value?.replace(/\./g,'') || 0),
                    seguro:            this.seguro || 0,
                    porcentaje_caja:   this.pctCaja || 2,
                    // Solo en las modalidades donde los días son del contrato:
                    // en el resto los pone la modalidad y mandarlos los pisaría.
                    dias_tp_afp:       this.esTpDiasContrato ? (parseInt(this.diasTp) || 7) : null,
                    // Fondo de Solidaridad: el grupo fija la tarifa de pensión.
                    grupo_fondo_solidaridad: this.esFondoSolidaridad ? (this.grupoFsp || null) : null,
                    // 0 días es un valor válido (afiliación pura, Gestión ARL): con
                    // `|| 30` se colaba el mes completo y el panel mostraba una
                    // seguridad social que esa factura no cobra.
                    dias:              Number.isFinite(parseInt(this.diasCotizar)) ? parseInt(this.diasCotizar) : 30,
                    cedula
                }),
            })
            .then(r => { if (!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(d => {
                // Llego tarde: ya se pidio otra cotizacion mas reciente.
                if (seq !== this._reqSeq) return;
                this.result = {
                    eps:    d.eps    ?? 0,
                    arl:    d.arl    ?? 0,
                    pen:    d.pen    ?? 0,
                    caja:   d.caja   ?? 0,
                    ss:     d.ss     ?? 0,
                    seguro: d.seguro ?? 0,
                    admon:  d.admon  ?? 0,
                    iva:    d.iva    ?? 0,
                    total:  d.total  ?? 0
                };
                this.pctEps      = d.pctEps  ?? 0;
                this.pctPen      = d.pctPen  ?? 0;
                this.pctArl      = d.pctArl  ?? 0;
                this.pctCajaCalc = d.pctCaja ?? 0;
                if (d.ibcSugerido && this.esIndependiente) this.ibcSugFmt = this.fmt(d.ibcSugerido);
                // Tiempo Parcial: actualizar días y flag desde el servidor
                if (typeof d.es_tiempo_parcial !== 'undefined') {
                    this.esTiempoParcial = !!d.es_tiempo_parcial;
                }
                if (d.es_tiempo_parcial) {
                    this.diasArl  = d.dias_arl  ?? 0;
                    this.diasAfp  = d.dias_afp  ?? 0;
                    this.diasCaja = d.dias_caja ?? 0;
                }

                // Sincronizar el modal de facturar si está abierto
                if (typeof MF !== 'undefined' && document.getElementById('mf-overlay')?.style.display === 'flex') {
                    MF.actualizarValoresDesdeAlpine();
                }
            })
            .catch(err => console.warn('Cotizador error:', err));
        },
    };
}

// ── Modal Unificado de Facturación (modo individual) ──────────────────
const FC_CSRF          = document.querySelector('meta[name="csrf-token"]').content;
const FC_URL_FAC       = '{{ route('admin.facturacion.facturar') }}';
const FC_URL_MES_PAG   = '{{ url('admin/facturacion/api/mes-pagado') }}';
const FC_CONTRATO_ID   = {{ $esEdicion ? $contrato->id : 'null' }};
const FC_FECHA_ING_MES = {{ $contrato->fecha_ingreso ? $contrato->fecha_ingreso->month : 0 }};
const FC_FECHA_ING_ANO = {{ $contrato->fecha_ingreso ? $contrato->fecha_ingreso->year : 0 }};
// Día de ingreso: con retiro dentro del mes de ingreso los días no se cuentan
// desde el 1 sino desde que entró (ver onRetiroFecha en modal_facturar_v2.js).
const FC_FECHA_ING_DIA = {{ $contrato->fecha_ingreso ? $contrato->fecha_ingreso->day : 0 }};
const FC_ES_INDEP      = {{ $contrato->tipoModalidad?->esIndependiente() ? 'true' : 'false' }};
const FC_TIPO_MODALIDAD_ID = {{ (int)($contrato->tipo_modalidad_id ?? 0) }};
// Cotiza el mes en curso: el modal lo necesita para no tratar el mes de ingreso
// como afiliación pura (ver modal_facturar_v2.js).
const FC_PAGA_MES_ACTUAL = {{ ($contrato->paga_mes_actual ?? false) ? 'true' : 'false' }};
const FC_COSTO_AFIL    = {{ (int)($contrato->costo_afiliacion ?? 0) }};
// IVA: cliente marcado con iva=SI, o cliente de una empresa marcada (ver IvaService)
const FC_TIENE_IVA     = {{ ($esEdicion && \App\Services\IvaService::aplicaContrato($contrato)) ? 'true' : 'false' }};
const FC_IVA_PCT       = {{ \App\Models\ConfiguracionBrynex::porcentajeIva() }};
const FC_ARL_NIVEL     = {!! json_encode($contrato->n_arl ?? '') !!};
const FC_DIST_DEFAULTS = {
    asesor:    {{ (int)($contrato->asesor?->comision_afil_valor ?? 0) }},
    retiro:    0,
    encargado: 0,
};
// Otros contratos vigentes del mismo cliente (para modal multi-contrato)
const FC_OTROS_CONTRATOS  = @json($otrosContratosVigentes ?? []);
const FC_URL_COTIZACION   = '{{ url('admin/facturacion/api/cotizacion-contrato') }}'; // + '/{id}?mes=X&anio=Y'

if (typeof MF !== 'undefined' && FC_CONTRATO_ID) {
    MF.init({
        modo:              'individual',
        urlFacturar:       FC_URL_FAC,
        urlMesPagado:      FC_URL_MES_PAG,
        // Corrección N de un mes ya pagado (ver CorreccionPlanillaController)
        urlCorreccion:     '{{ url('admin/facturacion/correccion') }}',
        // URL para subir imagen de soporte de consignación (mismo endpoint que en empresa.blade.php)
        urlConsignacionImagen: '{{ route('admin.facturacion.consignacion.imagen.subir', ['id' => '__ID__']) }}',
        csrf:              FC_CSRF,
        contratoId:        FC_CONTRATO_ID,
        fechaIngresoMes:   FC_FECHA_ING_MES,
        fechaIngresoAnio:  FC_FECHA_ING_ANO,
        fechaIngresoDia:   FC_FECHA_ING_DIA,
        esIndependiente:   FC_ES_INDEP,
        tipoModalidadId:   FC_TIPO_MODALIDAD_ID,
        pagaMesActual:     FC_PAGA_MES_ACTUAL,
        costoAfiliacion:   FC_COSTO_AFIL,
        tieneIva:          FC_TIENE_IVA,
        ivaPct:            FC_IVA_PCT,
        arlNivel:          FC_ARL_NIVEL,
        salarioMinimo:     SALARIO_MINIMO,
        distDefaults:      FC_DIST_DEFAULTS,
        getAlpineResult:   () => document.querySelector('[x-data]')?._x_dataStack?.[0]?.result || {},
        getDias:           () => {
            const alpine = document.querySelector('[x-data]')?._x_dataStack?.[0];
            if (!alpine) return 30;
            // ARL (id=15): siempre 0 días — nunca planilla
            if (parseInt(alpine.tipoModalidadId || 0) === 15) return 0;
            const d = parseInt(alpine.diasCotizar);
            return Number.isFinite(d) ? d : 30;
        },
        // Multi-contrato
        otrosContratos:         FC_OTROS_CONTRATOS,
        urlCotizacionContrato:  FC_URL_COTIZACION,
        onExito: (data) => {
            if (data.recibo_url) {
                let url = data.recibo_url;
                url += (url.includes('?') ? '&' : '?') + 'individual=1&modal=1';
                abrirRecibo(url, data);
            } else {
                @if(request()->has('iframe'))
                if (window.parent !== window) {
                    window.parent.postMessage(
                        { type: 'brynex:iframe_done', accion: 'facturacion', contratoId: FC_CONTRATO_ID, mensaje: data.mensaje },
                        window.location.origin
                    );
                }
                @else
                alert(data.mensaje || 'Factura generada correctamente.');
                window.location.reload();
                @endif
            }
        }
    });
}

function abrirModalFacturarContrato() {
    if (typeof MF !== 'undefined') {
        MF.abrir(
            [FC_CONTRATO_ID],
            '{{ nombre_oracion(trim(($cliente?->primer_nombre ?? "")." ".($cliente?->segundo_nombre ?? ""))) }} {{ nombre_oracion(trim(($cliente?->primer_apellido ?? "")." ".($cliente?->segundo_apellido ?? ""))) }}'
        );
    }
}

// Alias para compatibilidad con botones que todavía llaman a funciones fc*
// Todas las funciones del modal han sido reemplazadas por MF (modal_facturar.js)












@if(request()->has('iframe') && session('success') === 'Contrato retirado correctamente.')
// Modo iframe: retiro completado → notificar al padre
if (window.parent !== window) {
    window.parent.postMessage(
        { type: 'brynex:iframe_done', accion: 'retiro', contratoId: {{ $contrato->id ?? 'null' }}, mensaje: 'Contrato retirado correctamente.' },
        window.location.origin
    );
}
@endif

@if(request()->has('iframe') && session('success') && session('success') !== 'Contrato retirado correctamente.')
// Modo iframe: actualización normal del contrato → notificar al padre
if (window.parent !== window) {
    window.parent.postMessage(
        { type: 'brynex:iframe_done', accion: 'update', contratoId: {{ $contrato->id ?? 'null' }}, mensaje: {!! json_encode(session('success')) !!} },
        window.location.origin
    );
}
@endif

// ── Loading state en botón Guardar ──────────────────────────────────────
(function () {
    const btn  = document.getElementById('btn-guardar-contrato');
    const ico  = document.getElementById('btn-guardar-ico');
    const txt  = document.getElementById('btn-guardar-txt');
    if (!btn) return;

    // Detectar el form padre del botón
    const form = btn.closest('form');
    if (!form) return;

    form.addEventListener('submit', function () {
        // Evitar doble clic
        btn.disabled = true;
        btn.style.opacity = '0.75';
        btn.style.cursor  = 'not-allowed';

        // Spinner SVG inline
        ico.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="animation:spin-btn .7s linear infinite"><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>';
        txt.textContent = 'Guardando…';
    });
})();

// ── Modal Historial de Pagos ───────────────────────────────────────────────
@if($esEdicion)
const HISTORIAL_URL = '{{ route('admin.facturacion.historial', $contrato->cedula) }}';
let historialCambiosRealizados = false;

function registrarCambioHistorial() {
    historialCambiosRealizados = true;
}

function abrirHistorialPagos() {
    const modal  = document.getElementById('modal-historial');
    const iframe = document.getElementById('historial-frame');
    if (!modal || !iframe) return;
    historialCambiosRealizados = false; // resetear al abrir
    iframe.src = HISTORIAL_URL + '?iframe=1';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function cerrarHistorial() {
    const modal  = document.getElementById('modal-historial');
    const iframe = document.getElementById('historial-frame');
    if (modal)  modal.style.display = 'none';
    if (iframe) iframe.src = '';
    document.body.style.overflow = '';
    
    if (historialCambiosRealizados) {
        window.location.reload();
    }
}

let _facturaData = null;

function abrirRecibo(url, data) {
    _facturaData = data;
    const frame = document.getElementById('recibo-frame');
    const modal = document.getElementById('recibo-modal-ov');
    if (frame && modal) {
        frame.src = url;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function cerrarRecibo() {
    const frame = document.getElementById('recibo-frame');
    const modal = document.getElementById('recibo-modal-ov');
    if (modal) modal.style.display = 'none';
    if (frame) frame.src = '';
    document.body.style.overflow = '';
    
    @if(request()->has('iframe'))
        if (window.parent !== window) {
            window.parent.postMessage(
                { 
                    type: 'brynex:iframe_done', 
                    accion: 'facturacion', 
                    contratoId: FC_CONTRATO_ID, 
                    mensaje: (_facturaData && _facturaData.mensaje) || 'Factura generada correctamente.' 
                },
                window.location.origin
            );
        }
    @else
        window.location.reload();
    @endif
}
@endif
</script>
@endpush

@endsection

{{-- ═══════════════════════════════════════════════════════════════════════
     MODAL INGRESO-RETIRO: Duplicar Contrato
     Solo se renderiza cuando tipo_modalidad_id = 12 y está vigente
════════════════════════════════════════════════════════════════════════ --}}
@if($esEdicion && $contrato->estaVigente() && (int)$contrato->tipo_modalidad_id === 12)
@php
    $fIngresoDia = $contrato->fecha_ingreso ? $contrato->fecha_ingreso->format('d/m/Y') : '—';
    $fIngresoCO  = $contrato->fecha_ingreso ? $contrato->fecha_ingreso->toDateString() : null;
@endphp

<div id="modal-duplicar-ir" style="
    display:none; position:fixed; inset:0; z-index:4500;
    background:rgba(10,10,20,.72); backdrop-filter:blur(4px);
    align-items:center; justify-content:center; padding:1rem;
" onclick="if(event.target===this)cerrarModalDuplicarIR()">
<div style="
    background:#fff; border-radius:18px; width:min(520px,96vw);
    box-shadow:0 28px 80px rgba(0,0,0,.35); overflow:hidden;
    animation:mdirIn .22s cubic-bezier(.22,.68,0,1.2);
">
<style>
@@keyframes mdirIn { from{transform:translateY(-22px) scale(.97);opacity:0} to{transform:none;opacity:1} }
#modal-duplicar-ir .mir-header {
    background:linear-gradient(135deg,#7c2d12 0%,#c2410c 100%);
    padding:1rem 1.3rem; display:flex; align-items:center; justify-content:space-between;
}
#modal-duplicar-ir .mir-title { color:#fff; font-size:1rem; font-weight:800; display:flex; align-items:center; gap:.5rem; }
#modal-duplicar-ir .mir-close { background:rgba(255,255,255,.15); border:none; border-radius:7px; width:28px; height:28px; cursor:pointer; color:#fff; font-size:1rem; display:flex; align-items:center; justify-content:center; transition:background .15s; }
#modal-duplicar-ir .mir-close:hover { background:rgba(255,255,255,.3); }
#modal-duplicar-ir .mir-body { padding:1.2rem 1.4rem; }
#modal-duplicar-ir .mir-alert {
    background:#fff7ed; border:1px solid #fed7aa; border-radius:10px;
    padding:.75rem 1rem; margin-bottom:1rem; font-size:.78rem; color:#92400e; line-height:1.5;
}
#modal-duplicar-ir .mir-rs-badge {
    display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .75rem;
    background:#f0fdf4; border:1px solid #86efac; border-radius:8px;
    color:#15803d; font-size:.82rem; font-weight:700; margin-top:.5rem;
}
#modal-duplicar-ir .mir-no-rs {
    display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .75rem;
    background:#fef2f2; border:1px solid #fca5a5; border-radius:8px;
    color:#dc2626; font-size:.82rem; font-weight:700; margin-top:.5rem;
}
#modal-duplicar-ir .mir-fg { display:flex; flex-direction:column; gap:.22rem; margin-bottom:.75rem; }
#modal-duplicar-ir .mir-label { font-size:.7rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
#modal-duplicar-ir .mir-input, #modal-duplicar-ir .mir-select, #modal-duplicar-ir .mir-ta {
    padding:.46rem .65rem; border:1px solid #cbd5e1; border-radius:8px;
    font-size:.85rem; outline:none; font-family:inherit; color:#0f172a; background:#fff;
}
#modal-duplicar-ir .mir-input:focus, #modal-duplicar-ir .mir-select:focus { border-color:#f97316; box-shadow:0 0 0 2px rgba(249,115,22,.12); }
#modal-duplicar-ir .mir-ta { resize:vertical; min-height:70px; width:100%; box-sizing:border-box; }
#modal-duplicar-ir .mir-fecha-display {
    background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
    padding:.42rem .65rem; font-size:.83rem; color:#334155; font-weight:600;
}
#modal-duplicar-ir .mir-row { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
#modal-duplicar-ir .mir-btn-cancel {
    flex:1; padding:.58rem; border:1px solid #e2e8f0; background:#f8fafc; color:#64748b;
    border-radius:10px; font-size:.85rem; font-weight:600; cursor:pointer; transition:background .15s;
}
#modal-duplicar-ir .mir-btn-cancel:hover { background:#f1f5f9; }
#modal-duplicar-ir .mir-btn-confirm {
    flex:1; padding:.58rem; border:none;
    background:linear-gradient(135deg,#c2410c,#ea580c); color:#fff;
    border-radius:10px; font-size:.85rem; font-weight:700; cursor:pointer;
    box-shadow:0 3px 10px rgba(194,65,12,.3); transition:all .15s;
}
#modal-duplicar-ir .mir-btn-confirm:hover { transform:translateY(-1px); box-shadow:0 5px 16px rgba(194,65,12,.4); }
#modal-duplicar-ir .mir-btn-confirm:disabled { opacity:.6; cursor:not-allowed; transform:none; }
</style>

    {{-- Header --}}
    <div class="mir-header">
        <div class="mir-title">&#128260; Plan Ingreso-Retiro — Rotación de RS</div>
        <button class="mir-close" onclick="cerrarModalDuplicarIR()">&#x2715;</button>
    </div>

    {{-- Body --}}
    <div class="mir-body">
        {{-- Alerta informativa --}}
        <div class="mir-alert">
            ℹ️ Se marcará <strong>retiro</strong> en la RS actual y se creará un <strong>nuevo contrato</strong>
            con fecha de ingreso <strong>{{ $diaIngresoIr ?? 26 }} del mes actual</strong>, asignando automáticamente la siguiente RS disponible.
        </div>

        {{-- RS actual --}}
        <div class="mir-fg">
            <div class="mir-label">RS Actual (se retirará)</div>
            <div class="mir-fecha-display" style="color:#dc2626;">&#128683; {{ $contrato->razonSocial?->razon_social ?? '—' }}</div>
        </div>

        {{-- Select de nueva RS con todas las opciones coloreadas --}}
        <div class="mir-fg">
            <div class="mir-label">Nueva Razón Social *
                <span style="font-size:.6rem;font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0;"
                      title="Verde = nunca usada · Azul = disponible (con tiempo) · Rojo = bloqueada (vigente o actual)">
                    ● verde=nueva &nbsp;● azul=disponible &nbsp;● rojo=bloqueada
                </span>
            </div>
            @if(!empty($rsIrOpciones))
            <select id="mir-rs-id" class="mir-select" style="width:100%;">
                @foreach($rsIrOpciones as $op)
                @php
                    $sel     = $op['id'] == $rsIrPreviewId ? 'selected' : '';
                    if ($op['bloqueada']) {
                        $optStyle = 'color:#b91c1c;background:#fef2f2;';
                        $prefijo  = '🔴 ';
                        $sufijo   = $op['es_actual'] ? ' (RS actual)' : ' (cliente vigente aquí)';
                    } elseif ($op['nunca_usada']) {
                        $optStyle = 'color:#15803d;background:#f0fdf4;font-weight:700;';
                        $prefijo  = '🟢 ';
                        $sufijo   = ' — Nunca usada';
                    } else {
                        $optStyle = 'color:#1e40af;background:#eff6ff;';
                        $prefijo  = '🔵 ';
                        $sufijo   = $op['tiempo'] ? ' — ' . $op['tiempo'] : '';
                    }
                @endphp
                <option value="{{ $op['id'] }}"
                    {{ $sel }}
                    {{ $op['bloqueada'] ? 'disabled' : '' }}
                    style="{{ $optStyle }}">
                    {{ $prefijo }}{{ $op['nombre'] }}{{ $sufijo }}
                </option>
                @endforeach
            </select>
            <div style="font-size:.66rem;color:#94a3b8;margin-top:.25rem;">
                La primera opción verde/azul es la sugerida por el sistema. Puede cambiarla si lo desea.
            </div>
            @else
                <div class="mir-no-rs">&#9888; Sin RS disponibles — verifique configuración</div>
            @endif
        </div>

        <hr style="border:none;border-top:1px solid #f1f5f9;margin:.8rem 0;">

        {{-- Días cotizados en retiro --}}
        <div class="mir-fg" id="mir-dias-wrapper" style="margin-bottom:.4rem;">
            <label class="mir-label">Días cotizados en retiro (1–3) *</label>
            <input type="number" id="mir-num-dias" class="mir-input"
                min="1" max="3" value="1"
                oninput="mirActualizarFecha()"
                style="width:100%;box-sizing:border-box;">
        </div>
        <div id="mir-dias-hint" style="font-size:.68rem;color:#94a3b8;margin-top:-.2rem;margin-bottom:.75rem;">
            1 día = fecha ingreso · 2 días = fecha ingreso +1 día · 3 días = fecha ingreso +2 días
        </div>

        {{-- Fecha y Motivo de retiro en la misma fila --}}
        <div class="mir-row" style="margin-bottom:.75rem;">
            <div class="mir-fg" style="margin-bottom:0;">
                <label class="mir-label">Fecha de retiro</label>
                <div class="mir-fecha-display" id="mir-fecha-display">{{ $fIngresoDia }}</div>
            </div>
            <div class="mir-fg" style="margin-bottom:0;">
                <label class="mir-label">Motivo de retiro *</label>
                <select id="mir-motivo" class="mir-select" style="width:100%;">
                    <option value="">— Seleccione motivo —</option>
                    @foreach($motivosRetiro as $mr)
                    <option value="{{ $mr->id }}" {{ $mr->id == 2 ? 'selected' : '' }}>{{ $mr->nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Observación --}}
        <div class="mir-fg">
            <label class="mir-label">Observación (opcional)</label>
            <textarea id="mir-obs" class="mir-ta" placeholder="Ingrese una observación..."></textarea>
        </div>

        {{-- Error --}}
        <div id="mir-error" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:.6rem .9rem;font-size:.78rem;color:#dc2626;margin-bottom:.75rem;"></div>

        {{-- Botones --}}
        <div style="display:flex;gap:.7rem;margin-top:.5rem;">
            <button class="mir-btn-cancel" onclick="cerrarModalDuplicarIR()">Cancelar</button>
            @if($rsIrHayDisponible)
            <button class="mir-btn-confirm" id="mir-btn-confirmar" onclick="confirmarDuplicarIR()">
                &#128260; Confirmar Retiro y Duplicar
            </button>
            @else
            <button class="mir-btn-confirm" disabled>Sin RS disponibles</button>
            @endif
        </div>
    </div>
</div>
</div>


@push('scripts')
<script>
// ─── Datos para el modal IR ───────────────────────────────────────────────
const MIR_URL_DUPLICAR  = '{{ route("admin.contratos.duplicar-ir", $contrato->id) }}';
const MIR_CSRF          = document.querySelector('meta[name="csrf-token"]')?.content;
const MIR_FECHA_INGRESO = '{{ $fIngresoCO }}'; // YYYY-MM-DD

function abrirModalDuplicarIR() {
    mirActualizarFecha();
    document.getElementById('modal-duplicar-ir').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function cerrarModalDuplicarIR() {
    document.getElementById('modal-duplicar-ir').style.display = 'none';
    document.body.style.overflow = '';
    document.getElementById('mir-error').style.display = 'none';
    const btn = document.getElementById('mir-btn-confirmar');
    if (btn) { btn.disabled = false; btn.textContent = '🔄 Confirmar Retiro y Duplicar'; }
}

function mirActualizarFecha() {
    const dias       = parseInt(document.getElementById('mir-num-dias').value) || 1;
    const diaClamped = Math.min(3, Math.max(1, dias));
    document.getElementById('mir-num-dias').value = diaClamped;

    if (!MIR_FECHA_INGRESO) return;

    const ingreso = new Date(MIR_FECHA_INGRESO + 'T00:00:00');
    const hoy     = new Date();

    // Mes anterior al actual (0-based)
    const mesAntMes  = hoy.getMonth() === 0 ? 11 : hoy.getMonth() - 1;
    const mesAntAnio = hoy.getMonth() === 0 ? hoy.getFullYear() - 1 : hoy.getFullYear();

    // ¿La afiliación fue exactamente el mes anterior?
    const esAfiliacionMesAnterior = (
        ingreso.getFullYear() === mesAntAnio &&
        ingreso.getMonth()    === mesAntMes
    );

    let fechaRetiro;
    const diasWrapper = document.getElementById('mir-dias-wrapper');
    const diasHint    = document.getElementById('mir-dias-hint');

    if (esAfiliacionMesAnterior) {
        // Afiliación fue el mes anterior → usar fecha_ingreso + (dias-1)
        fechaRetiro = new Date(MIR_FECHA_INGRESO + 'T00:00:00');
        fechaRetiro.setDate(fechaRetiro.getDate() + (diaClamped - 1));
        // Mostrar campo de días
        if (diasWrapper) diasWrapper.style.display = '';
        if (diasHint)    diasHint.style.display = '';
    } else {
        // Afiliación fue antes del mes anterior → retiro = 1 del mes anterior
        fechaRetiro = new Date(mesAntAnio, mesAntMes, 1);
        // Ocultar campo de días (no aplica)
        if (diasWrapper) diasWrapper.style.display = 'none';
        if (diasHint)    diasHint.style.display = 'none';
    }

    const dd   = String(fechaRetiro.getDate()).padStart(2,'0');
    const mm   = String(fechaRetiro.getMonth()+1).padStart(2,'0');
    const yyyy = fechaRetiro.getFullYear();
    document.getElementById('mir-fecha-display').textContent = `${dd}/${mm}/${yyyy}`;
}

async function confirmarDuplicarIR() {
    const numDias  = parseInt(document.getElementById('mir-num-dias').value) || 1;
    const motivoId = document.getElementById('mir-motivo').value;
    const obs      = document.getElementById('mir-obs').value;
    const rsSelEl  = document.getElementById('mir-rs-id');
    const rsSelId  = rsSelEl ? rsSelEl.value : '';
    const errEl    = document.getElementById('mir-error');
    const btn      = document.getElementById('mir-btn-confirmar');

    errEl.style.display = 'none';

    if (numDias < 1 || numDias > 3) {
        errEl.textContent = 'Los días deben estar entre 1 y 3.';
        errEl.style.display = 'block';
        return;
    }
    if (!motivoId) {
        errEl.textContent = 'Debe seleccionar un motivo de retiro.';
        errEl.style.display = 'block';
        return;
    }
    if (rsSelEl && !rsSelId) {
        errEl.textContent = 'Debe seleccionar una Razón Social de destino.';
        errEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Procesando...';

    try {
        const res  = await fetch(MIR_URL_DUPLICAR, {
            method : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': MIR_CSRF, 'Accept': 'application/json' },
            body   : JSON.stringify({ num_dias: numDias, motivo_retiro_id: motivoId, observacion: obs, nueva_rs_id: rsSelId || null }),
        });
        const data = await res.json();

        if (!res.ok || data.error) {
            errEl.textContent = data.mensaje || data.message || 'Error desconocido.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = '🔄 Confirmar Retiro y Duplicar';
            return;
        }

        // Éxito → redirigir al nuevo contrato
        cerrarModalDuplicarIR();
        // Si estamos dentro de un iframe (cobros), preservar ?iframe=1
        const esIframe = new URLSearchParams(window.location.search).get('iframe') === '1';
        const iframeSuffix = esIframe ? '?iframe=1' : '';
        if (data.redirect_url) {
            window.location.href = data.redirect_url + iframeSuffix;
        } else if (data.nuevo_id) {
            window.location.href = `/admin/contratos/${data.nuevo_id}/edit${iframeSuffix}`;
        }
    } catch (err) {
        errEl.textContent = 'Error de conexión. Intente de nuevo.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = '🔄 Confirmar Retiro y Duplicar';
    }
}

// Inicializar fecha al cargar
document.addEventListener('DOMContentLoaded', mirActualizarFecha);

// Auto-abrir modal si viene de url (?facturar=1 o ?abrir_retiro=...)
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('facturar') === '1') {
        setTimeout(() => {
            if (typeof abrirModalFacturarContrato === 'function') abrirModalFacturarContrato();
        }, 400);
    }
    if (urlParams.get('abrir_retiro') === 'informativo' || urlParams.get('abrir_retiro') === '1') {
        setTimeout(() => {
            const m = document.getElementById('modal-retiro');
            if (m) {
                m.style.display = 'flex';
                if (typeof mrInitSelects === 'function') mrInitSelects();
                if (typeof mrSetDefault === 'function') mrSetDefault();
                if (urlParams.get('abrir_retiro') === 'informativo') {
                    const rInfo = document.querySelector('input[name="_tipo_retiro_ui"][value="informativo"]');
                    if (rInfo) {
                        rInfo.checked = true;
                        if (typeof mrTipo === 'function') mrTipo('informativo');
                    }
                }
            }
        }, 400);
    }
});
</script>
@endpush
@endif

