@extends('layouts.app')
@section('modulo', isset($asesor->id) ? 'Editar Asesor' : 'Nuevo Asesor')

@section('contenido')
<div style="max-width:800px;margin:0 auto;">
<div style="background:#fff;border-radius:14px;padding:1.75rem 2rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

    @include('admin.partials.table-header', [
        'titulo' => isset($asesor->id) ? '✏️ Editar Asesor' : '🤝 Nuevo Asesor Comercial',
    ])

    @if($errors->any())
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.83rem;">
            <strong>Corrige los siguientes errores:</strong>
            <ul style="margin:0.4rem 0 0 1rem;">
                @foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ isset($asesor->id) ? route('admin.asesores.update', $asesor) : route('admin.asesores.store') }}">
        @csrf
        @if(isset($asesor->id)) @method('PUT') @endif

        <h3 style="font-size:0.95rem;color:#0f172a;margin-bottom:1rem;border-bottom:1px solid #f1f5f9;padding-bottom:0.5rem;">Información Personal</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Nombre completo *</label>
                <input type="text" name="nombre" value="{{ old('nombre', $asesor->nombre) }}" required
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Cédula *</label>
                <input type="text" name="cedula" value="{{ old('cedula', $asesor->cedula) }}" required
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;font-family:monospace;">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Celular</label>
                <input type="text" name="celular" value="{{ old('celular', $asesor->celular) }}"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Teléfono fijo</label>
                <input type="text" name="telefono" value="{{ old('telefono', $asesor->telefono) }}"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Correo</label>
                <input type="email" name="correo" value="{{ old('correo', $asesor->correo) }}"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
            </div>
        </div>
        
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:1.5rem;margin-bottom:2rem;">
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Dirección</label>
                <input type="text" name="direccion" value="{{ old('direccion', $asesor->direccion) }}"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Departamento</label>
                <select name="departamento" id="sel_depto"
                    onchange="cargarMunicipios(this.value)"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.87rem;background:#fff;">
                    <option value="">— Seleccione —</option>
                    @foreach($departamentos as $depto)
                    <option value="{{ $depto->nombre }}"
                        data-id="{{ $depto->id }}"
                        {{ old('departamento', $asesor->departamento) === $depto->nombre ? 'selected' : '' }}>
                        {{ $depto->nombre }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Ciudad / Municipio</label>
                <select name="ciudad" id="sel_ciudad"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.87rem;background:#fff;">
                    <option value="">— Primero seleccione depto. —</option>
                    {{-- Se carga dinámicamente; al editar se pre-pobla via JS --}}
                </select>
            </div>
        </div>


        <h3 style="font-size:0.95rem;color:#0f172a;margin-bottom:1rem;border-bottom:1px solid #f1f5f9;padding-bottom:0.5rem;">Configuración de Comisiones y Pagos</h3>

        @isset($asesor->id)
        {{-- ── Nivel y tarifario ─────────────────────────────────────────────
             Va FUERA del formulario de datos (no se puede anidar un <form>): el bloque de
             abajo solo enlaza y el formulario de aplicar nivel está al final de la página. --}}
        <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:1rem 1.15rem;margin-bottom:1.5rem;">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div style="min-width:0;">
              <div style="font-size:0.68rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;">
                🎚️ Nivel y tarifario
              </div>
              <div style="font-size:0.8rem;color:#0c4a6e;">
                @if($asesor->nivel)
                  Nivel actual: <strong>{{ $asesor->nivel->nombre }}</strong>
                  <span style="color:#64748b;">· {{ $asesor->nivel->rangoLabel() }}</span>
                @else
                  <span style="color:#b45309;font-weight:600;">Sin nivel asignado</span>
                  <span style="color:#64748b;">— usa su comisión general de abajo</span>
                @endif
              </div>
              <div style="font-size:0.74rem;color:#64748b;margin-top:0.3rem;">
                {{ $contratosVigentes }} contrato(s) vigente(s)
                @if($celdasPropias > 0)
                  · <strong style="color:#0369a1;">{{ $celdasPropias }}</strong> tarifa(s) propias
                @endif
              </div>
            </div>

            <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
              @if($celdasPropias > 0)
              <a href="{{ route('admin.asesores.tarifas', $asesor) }}"
                 style="padding:0.42rem 0.85rem;background:#0369a1;border-radius:7px;color:#fff;text-decoration:none;font-size:0.77rem;font-weight:600;">
                📊 Ver / editar tarifas
              </a>
              <a href="{{ route('admin.asesores.tarifario_pdf', $asesor) }}"
                 style="padding:0.42rem 0.85rem;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#475569;text-decoration:none;font-size:0.77rem;font-weight:600;">
                📄 Imprimir tarifario
              </a>
              @endif
            </div>
          </div>

          @if($nivelSugerido && (int) $asesor->nivel_id !== (int) $nivelSugerido->id)
          <div style="background:#fef9c3;border:1px solid #fde047;border-radius:7px;padding:0.5rem 0.75rem;margin-top:0.7rem;font-size:0.76rem;color:#92400e;">
            💡 Con {{ $contratosVigentes }} contrato(s) vigente(s) le correspondería
            <strong>{{ $nivelSugerido->nombre }}</strong>. Es solo una sugerencia: nada cambia hasta que lo apliques.
          </div>
          @endif

          @if($nivelesDisponibles->isEmpty())
          <div style="font-size:0.75rem;color:#64748b;margin-top:0.7rem;">
            Aún no hay niveles creados.
            <a href="{{ route('admin.configuracion.niveles.index') }}" style="color:#0369a1;font-weight:600;">Crear el primero →</a>
          </div>
          @endif
        </div>
        @endisset

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-bottom:1.5rem;">
            {{-- Comisión Afiliación --}}
            <div style="background:#f8fafc;padding:1.25rem;border-radius:10px;border:1px solid #e2e8f0;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:1rem;display:flex;align-items:center;gap:0.4rem;">
                    <span style="color:#8b5cf6;">■</span> Comisión 1er mes (Afiliación)
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Tipo *</label>
                        <select name="comision_afil_tipo" required style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;">
                            <option value="fijo" {{ old('comision_afil_tipo', $asesor->comision_afil_tipo) === 'fijo' ? 'selected' : '' }}>Valor Fijo ($)</option>
                            <option value="porcentaje" {{ old('comision_afil_tipo', $asesor->comision_afil_tipo) === 'porcentaje' ? 'selected' : '' }}>Porcentaje (%)</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Valor/Monto *</label>
                        <input type="number" step="0.01" min="0" name="comision_afil_valor" value="{{ old('comision_afil_valor', $asesor->comision_afil_valor) }}" required
                            style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;">
                    </div>
                </div>
            </div>

            {{-- Comisión Administración --}}
            <div style="background:#f8fafc;padding:1.25rem;border-radius:10px;border:1px solid #e2e8f0;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:1rem;display:flex;align-items:center;gap:0.4rem;">
                    <span style="color:#0891b2;">■</span> Comisión mensual (Admin)
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Tipo *</label>
                        <select name="comision_admon_tipo" required style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;background:#fff;">
                            <option value="fijo" {{ old('comision_admon_tipo', $asesor->comision_admon_tipo) === 'fijo' ? 'selected' : '' }}>Valor Fijo ($)</option>
                            <option value="porcentaje" {{ old('comision_admon_tipo', $asesor->comision_admon_tipo) === 'porcentaje' ? 'selected' : '' }}>Porcentaje (%)</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Valor/Monto *</label>
                        <input type="number" step="0.01" min="0" name="comision_admon_valor" value="{{ old('comision_admon_valor', $asesor->comision_admon_valor) }}" required
                            style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;">
                    </div>
                </div>
            </div>
        </div>

        {{-- Cómo trabaja con el aliado: se cambia a mano; el informe «Cómo van» muestra si conviene moverlo --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.5rem;margin-bottom:1.5rem;">
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Tipo de cobro</label>
                <select name="tipo_cobro" style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;background:#fff;">
                    @foreach(\App\Models\Asesor::TIPOS_COBRO as $valor => $etiqueta)
                        <option value="{{ $valor }}" {{ old('tipo_cobro', $asesor->tipo_cobro ?? 'comision') === $valor ? 'selected' : '' }}>{{ $etiqueta }}</option>
                    @endforeach
                </select>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.3rem;">Comisión: su parte va en el contrato. Neta: paga un valor fijo y cobra lo suyo aparte.</div>
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Tarifa neta por persona ($)</label>
                <input type="number" step="1" min="0" name="tarifa_neta" value="{{ old('tarifa_neta', $asesor->tarifa_neta !== null ? (int) $asesor->tarifa_neta : '') }}"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.3rem;">Solo si es tarifa neta. Es informativo: lo que se cobra sale del contrato.</div>
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Cómo recibe su comisión</label>
                <select name="forma_pago" style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;background:#fff;">
                    <option value="">Sin definir</option>
                    @foreach(\App\Models\Asesor::FORMAS_PAGO as $valor => $etiqueta)
                        <option value="{{ $valor }}" {{ old('forma_pago', $asesor->forma_pago) === $valor ? 'selected' : '' }}>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Cuenta Bancaria (para liquidación)</label>
                <input type="text" name="cuenta_bancaria" value="{{ old('cuenta_bancaria', $asesor->cuenta_bancaria) }}"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Fecha de Ingreso</label>
                <input type="date" name="fecha_ingreso" value="{{ old('fecha_ingreso', $asesor->fecha_ingreso ? $asesor->fecha_ingreso->format('Y-m-d') : '') }}"
                    style="width:100%;padding:0.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.9rem;">
            </div>
            <div style="display:flex;flex-direction:column;justify-content:center;">
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Estado</label>
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                    <input type="checkbox" name="activo" value="1" {{ old('activo', $asesor->activo ?? true) ? 'checked' : '' }}
                        style="width:18px;height:18px;cursor:pointer;">
                    <span style="font-size:0.85rem;font-weight:600;color:#0f172a;">Asesor Activo</span>
                </label>
            </div>
        </div>

        {{-- Botones --}}
        <div style="display:flex;gap:0.75rem;justify-content:flex-end;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
            <a href="{{ route('admin.asesores.index') }}"
                style="padding:0.6rem 1.25rem;border:1px solid #cbd5e1;border-radius:8px;color:#475569;text-decoration:none;font-size:0.85rem;font-weight:500;">
                Cancelar
            </a>
            <button type="submit"
                style="padding:0.6rem 1.5rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;border-radius:8px;color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;box-shadow:0 3px 10px rgba(37,99,235,0.35);">
                💾 {{ isset($asesor->id) ? 'Actualizar Asesor' : 'Guardar y Crear' }}
            </button>
        </div>
    </form>

    {{-- Aplicar un nivel: formulario aparte porque copiar la matriz pisa lo que el asesor
         tuviera, y eso debe ser una acción deliberada, no un efecto de guardar el teléfono. --}}
    @isset($asesor->id)
    @can('asesores.gestionar')
    @if($nivelesDisponibles->isNotEmpty())
    <div style="border-top:1px solid #f1f5f9;margin-top:1.5rem;padding-top:1.25rem;">
        <div style="font-size:0.68rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
            Aplicar un nivel
        </div>
        <form method="POST" action="{{ route('admin.asesores.nivel.aplicar', $asesor) }}"
              onsubmit="return confirm('Se copiarán al asesor los valores del nivel elegido. ¿Continuar?')">
            @csrf
            <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
                <select name="nivel_id" required
                    style="flex:1;min-width:220px;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;background:#fff;">
                    <option value="">— Elige un nivel —</option>
                    @foreach($nivelesDisponibles as $nv)
                    <option value="{{ $nv->id }}"
                        {{ (int) $asesor->nivel_id === (int) $nv->id ? 'selected' : '' }}>
                        {{ $nv->nombre }} — {{ $nv->rangoLabel() }} · admon ${{ number_format($nv->admon_asesor, 0, ',', '.') }}
                        {{ $nivelSugerido && (int) $nivelSugerido->id === (int) $nv->id ? '  (sugerido)' : '' }}
                    </option>
                    @endforeach
                </select>

                <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.76rem;color:#475569;cursor:pointer;"
                       title="Conserva las celdas que ya le ajustaste y su administración mensual, si es distinta a la del nivel.">
                    <input type="checkbox" name="conservar_editadas" value="1" style="width:16px;height:16px;cursor:pointer;">
                    No pisar lo que ya ajusté
                </label>

                <button type="submit"
                    style="padding:0.5rem 1.2rem;background:#0369a1;border:none;border-radius:8px;color:#fff;font-size:0.82rem;font-weight:700;cursor:pointer;">
                    Aplicar nivel
                </button>
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.4rem;line-height:1.5;">
                Copia al asesor las tarifas de afiliación del nivel y su administración mensual.
                @if($celdasPropias > 0)
                    Hoy tiene <strong>{{ $celdasPropias }}</strong> celdas propias.
                @endif
                <br>
                Con <strong>«No pisar lo que ya ajusté»</strong> conserva las celdas que ya tiene y
                le respeta su administración mensual si le pusiste una distinta a la del nivel;
                solo completa lo que le falte. Sin marcar, rehace la matriz completa y le deja la
                administración del nivel.
            </div>
        </form>
    </div>
    @endif
    @endcan
    @endisset

</div>
</div>
@push('scripts')
<script>
// Ciudades preseleccionadas al editar
const CIUDAD_ACTUAL    = @json(old('ciudad', $asesor->ciudad ?? ''));
const DEPTO_ACTUAL_ID  = (() => {
    const opt = document.querySelector('#sel_depto option[selected]');
    return opt ? opt.dataset.id : null;
})();

async function cargarMunicipios(deptoNombre, ciudadPreselectar = '') {
    const selDepto  = document.getElementById('sel_depto');
    const selCiudad = document.getElementById('sel_ciudad');
    const opt = selDepto.querySelector(`option[value="${deptoNombre}"]`);
    const deptoId = opt ? opt.dataset.id : null;

    selCiudad.innerHTML = '<option value="">Cargando...</option>';

    if (!deptoId) {
        selCiudad.innerHTML = '<option value="">— Seleccione un departamento —</option>';
        return;
    }

    const base = '{{ url("admin/api/departamentos") }}';
    const res  = await fetch(`${base}/${deptoId}/ciudades`);
    const data = await res.json();

    selCiudad.innerHTML = '<option value="">— Municipio —</option>';
    data.forEach(c => {
        const sel = (ciudadPreselectar && ciudadPreselectar === c.nombre) ? 'selected' : '';
        selCiudad.innerHTML += `<option value="${c.nombre}" ${sel}>${c.nombre}</option>`;
    });
}

// Al cargar la página: si hay depto seleccionado (edición) → cargar municipios
document.addEventListener('DOMContentLoaded', () => {
    const deptoActual = document.querySelector('#sel_depto')?.value;
    if (deptoActual) {
        cargarMunicipios(deptoActual, CIUDAD_ACTUAL);
    }
});
</script>
@endpush
@endsection
