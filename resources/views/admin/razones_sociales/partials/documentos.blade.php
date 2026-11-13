{{-- Documentos de una razón social: listado, descarga, subida y borrado.
     Se usa en la ficha de edición (pestaña Archivos) y en la página propia
     de documentos, que es la que ve quien tiene `razones_sociales.documentos`
     sin poder editar los datos de la empresa. Espera $rs. --}}
@php
    $nitRs = $rs->nit ?? $rs->id;
    $documentosRs = \App\Models\DocumentoCliente::with('subidor')
        ->where('aliado_id', session('aliado_id_activo'))
        ->where('cc_cliente', $nitRs)
        ->whereNull('doc_beneficiario')
        ->orderByDesc('created_at')
        ->get();

    $anioConstitucion = null;
    if (!empty($rs->fecha_constitucion)) {
        $anioConstitucion = (int) \Carbon\Carbon::parse($rs->fecha_constitucion)->format('Y');
    }
    $anioActual = (int) date('Y');
    $aniosRenta = [];
    if ($anioConstitucion && $anioConstitucion <= $anioActual) {
        for ($anio = $anioActual; $anio >= $anioConstitucion; $anio--) {
            $aniosRenta[] = $anio;
        }
    } else {
        for ($anio = $anioActual; $anio >= ($anioActual - 6); $anio--) {
            $aniosRenta[] = $anio;
        }
    }
    $puedeDocs = auth()->user()->can('razones_sociales.documentos');

    // La cédula y el RUT personal del representante se guardan una sola vez
    // por persona en Representantes (BryNex) y aquí solo se muestran, para
    // no tener que cargarlos en cada empresa de la misma persona.
    $repBrynex = app(\App\Services\BrynexRepresentanteService::class)->representanteDeRazonSocial((int) $rs->id);
    $repDocs = $repBrynex?->documentos ?? collect();
    $repTieneCedula = $repDocs->contains('tipo', 'CEDULA');

    // Un solo documento por tipo: subir otro del mismo tipo reemplaza al actual.
    $tiposCargados = $documentosRs->pluck('tipo_documento')->unique()->flip();
    $etiquetaTipo = fn (string $tipo) => $tipo . (isset($tiposCargados[$tipo]) ? ' · reemplaza el actual' : '');
@endphp

    {{-- ── 📁 Documentos de la Razón Social ── --}}
    <div class="card" id="cardDocumentos">
        <div class="card-title">📁 Documentos de la Razón Social</div>

        {{-- Listado de documentos existentes --}}
        <div style="margin-bottom: 1.5rem">
            <h4 style="font-size: .75rem; color: #475569; text-transform: uppercase; margin-bottom: .6rem; letter-spacing: .02em">
                Documentos Cargados ({{ $documentosRs->count() + $repDocs->count() }})
            </h4>

            @if($documentosRs->isEmpty() && $repDocs->isEmpty())
                <div style="background: #f8fafc; border: 1.5px dashed #e2e8f0; padding: 1.5rem; text-align: center; border-radius: 10px; color: #94a3b8; font-size: .8rem">
                    📭 No hay documentos cargados para esta razón social.
                </div>
            @else
                <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px">
                    <table style="width: 100%; border-collapse: collapse; font-size: .78rem; text-align: left">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0">
                                <th style="padding: .5rem .75rem; color: #64748b; font-weight: 700; font-size: .62rem; text-transform: uppercase">Tipo</th>
                                <th style="padding: .5rem .75rem; color: #64748b; font-weight: 700; font-size: .62rem; text-transform: uppercase">Nombre del Archivo</th>
                                <th style="padding: .5rem .75rem; color: #64748b; font-weight: 700; font-size: .62rem; text-transform: uppercase">Subido Por</th>
                                <th style="padding: .5rem .75rem; color: #64748b; font-weight: 700; font-size: .62rem; text-transform: uppercase">Fecha</th>
                                <th style="padding: .5rem .75rem; color: #64748b; font-weight: 700; font-size: .62rem; text-transform: uppercase; text-align: right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($repDocs as $rd)
                                <tr style="border-bottom: 1px solid #f1f5f9; background: #f5f3ff">
                                    <td style="padding: .55rem .75rem; font-weight: 700; color: #1e293b">
                                        {{ $rd->tipo === 'CEDULA' ? 'Cédula del Representante' : $rd->tipoEtiqueta() . ' del Representante' }}
                                        <div style="font-size: .62rem; font-weight: 600; color: #6d28d9">👤 de Representantes · {{ $repBrynex->nombre }}</div>
                                    </td>
                                    <td style="padding: .55rem .75rem; color: #475569; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" title="{{ $rd->nombre_original }}">
                                        {{ $rd->nombre_original }}
                                    </td>
                                    <td style="padding: .55rem .75rem; color: #64748b">BryNex</td>
                                    <td style="padding: .55rem .75rem; color: #94a3b8; font-size: .72rem">
                                        {{ $rd->created_at?->format('d/m/Y h:i A') }}
                                    </td>
                                    <td style="padding: .55rem .75rem; text-align: right; white-space: nowrap">
                                        <a href="{{ route('admin.configuracion.razones.documentos.representante', [$rs->id, $rd->id]) }}"
                                           style="padding: .25rem .5rem; background: #dbeafe; border: 1px solid #bfdbfe; border-radius: 6px; color: #1d4ed8; text-decoration: none; font-size: .7rem; font-weight: 700; display: inline-flex; align-items: center; gap: .2rem">
                                            📥 Descargar
                                        </a>
                                        <span title="Se cambia en BryNex → Razones sociales → Representantes, y sirve para todas sus empresas"
                                              style="display: inline-block; margin-left: 4px; font-size: .64rem; color: #7c3aed; font-weight: 600">🔒 se edita en Representantes</span>
                                    </td>
                                </tr>
                            @endforeach
                            @foreach($documentosRs as $doc)
                                <tr style="border-bottom: 1px solid #f1f5f9">
                                    <td style="padding: .55rem .75rem; font-weight: 700; color: #1e293b">
                                        {{ $doc->tipo_documento }}
                                    </td>
                                    <td style="padding: .55rem .75rem; color: #475569; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" title="{{ $doc->nombre_archivo }}">
                                        {{ $doc->nombre_archivo }}
                                    </td>
                                    <td style="padding: .55rem .75rem; color: #64748b">
                                        {{ $doc->subidor?->nombre ?? 'Sistema' }}
                                    </td>
                                    <td style="padding: .55rem .75rem; color: #94a3b8; font-size: .72rem">
                                        {{ $doc->created_at->format('d/m/Y h:i A') }}
                                    </td>
                                    <td style="padding: .55rem .75rem; text-align: right; white-space: nowrap">
                                        {{-- Descargar --}}
                                        <a href="{{ route('admin.configuracion.razones.documentos.download', $doc->id) }}"
                                           style="padding: .25rem .5rem; background: #dbeafe; border: 1px solid #bfdbfe; border-radius: 6px; color: #1d4ed8; text-decoration: none; font-size: .7rem; font-weight: 700; margin-right: 4px; display: inline-flex; align-items: center; gap: .2rem">
                                            📥 Descargar
                                        </a>
                                        {{-- Eliminar --}}
                                        @if($puedeDocs)
                                        <form method="POST" action="{{ route('admin.configuracion.razones.documentos.destroy', $doc->id) }}"
                                              style="display: inline" onsubmit="return confirm('¿Eliminar permanentemente este documento?')">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    style="padding: .25rem .5rem; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; color: #dc2626; font-size: .7rem; font-weight: 700; cursor: pointer">
                                                🗑️ Eliminar
                                            </button>
                                        </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Formulario para subir nuevo documento --}}
        @if($puedeDocs)
        <form method="POST" action="{{ route('admin.configuracion.razones.documentos.store', $rs->id) }}" enctype="multipart/form-data" style="border-top: 1.5px solid #f1f5f9; padding-top: 1.2rem">
            @csrf

            <h4 style="font-size: .75rem; color: #475569; text-transform: uppercase; margin-bottom: .8rem; letter-spacing: .02em">
                Subir Nuevo Documento
            </h4>

            <div style="display: flex; gap: 1.2rem; align-items: stretch; flex-wrap: wrap; margin-bottom: .8rem">
                {{-- Lado izquierdo: Selector de tipo --}}
                <div style="flex: 1; min-width: 250px; display: flex; flex-direction: column; gap: .6rem">
                    <div>
                        <label class="flb">Tipo de Documento <span>*</span></label>
                        <select class="finp" name="tipo_documento" id="selectTipoDoc" required onchange="toggleOtroDocumento(this.value)">
                            <option value="">— Seleccionar Tipo —</option>
                            <option value="RUT">{{ $etiquetaTipo('RUT') }}</option>
                            <option value="Cámara de Comercio">{{ $etiquetaTipo('Cámara de Comercio') }}</option>
                            <option value="Radicado ARL">{{ $etiquetaTipo('Radicado ARL') }}</option>
                            @unless($repTieneCedula)
                                <option value="Cédula del Representante">{{ $etiquetaTipo('Cédula del Representante') }}</option>
                            @endunless
                            <option value="Certificación Bancaria">{{ $etiquetaTipo('Certificación Bancaria') }}</option>
                            <optgroup label="Declaraciones de Renta">
                                @foreach($aniosRenta as $anio)
                                    <option value="Declaración de Renta {{ $anio }}">{{ $etiquetaTipo('Declaración de Renta ' . $anio) }}</option>
                                @endforeach
                            </optgroup>
                            <option value="Otro">Otro (Especificar nombre)</option>
                        </select>
                        <div style="font-size: .66rem; color: #64748b; margin-top: .3rem">
                            Se guarda uno por tipo: si ya hay uno, el nuevo lo reemplaza.
                            @if($repTieneCedula)
                                La cédula del representante viene de Representantes y no hay que cargarla aquí.
                            @endif
                        </div>
                    </div>

                    <div id="wrapOtroDoc" style="display: none">
                        <label class="flb">Nombre del Documento Personalizado <span>*</span></label>
                        <input class="finp" type="text" name="tipo_personalizado" id="inputOtroDoc" placeholder="Ej: Contrato de Arrendamiento">
                    </div>
                </div>

                {{-- Lado derecho: Dropzone del archivo --}}
                <div style="flex: 1; min-width: 280px; display: flex; flex-direction: column">
                    <label class="flb">Archivo adjunto <span>*</span></label>
                    <div id="docDropZone"
                         style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 1rem; text-align: center; cursor: pointer; transition: all .15s; background: #f8fafc; flex: 1; display: flex; flex-direction: column; justify-content: center"
                         onclick="document.getElementById('inputDocFile').click()"
                         ondragover="event.preventDefault(); this.style.borderColor='#3b82f6'; this.style.background='#eff6ff'"
                         ondragleave="this.style.borderColor='#cbd5e1'; this.style.background='#f8fafc'"
                         ondrop="handleDocDrop(event)">
                        <div style="font-size: 1.8rem; margin-bottom: .2rem">📁</div>
                        <div style="font-size: .78rem; font-weight: 700; color: #475569" id="docZoneTitle">
                            Arrastra tu documento aquí o haz clic
                        </div>
                        <div style="font-size: .6rem; color: #94a3b8; margin-top: .15rem" id="docZoneSub">PDF, imágenes, Word o Excel — máx. 15 MB</div>
                    </div>
                </div>
            </div>

            <input type="file" id="inputDocFile" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp,.zip,.rar,.doc,.docx,.xls,.xlsx"
                   style="display: none" onchange="actualizarDocSeleccionado(this)">

            <button type="submit" id="btnSubirDoc"
                    style="display: none; width: 100%; padding: .6rem; background: linear-gradient(135deg, #0284c7, #0369a1);
                           color: #fff; border: none; border-radius: 8px; font-size: .85rem; font-weight: 700; cursor: pointer; margin-top: .8rem; box-shadow: 0 4px 12px rgba(2,132,199,.2)">
                🚀 Cargar Documento
            </button>
        </form>
        @endif
    </div>

@if($puedeDocs)
<script>
// ── Documentos ──────────────────────────────────────────────────────
function toggleOtroDocumento(val) {
    const wrap = document.getElementById('wrapOtroDoc');
    const input = document.getElementById('inputOtroDoc');
    if (val === 'Otro') {
        wrap.style.display = 'block';
        input.required = true;
        input.focus();
    } else {
        wrap.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

function actualizarDocSeleccionado(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const sizeMB = (file.size / (1024 * 1024)).toFixed(2);

    document.getElementById('docZoneTitle').innerHTML = `📄 Archivo seleccionado: <strong style="color: #0369a1">${file.name}</strong>`;
    document.getElementById('docZoneSub').innerHTML = `Tamaño: ${sizeMB} MB — Listo para cargar`;
    document.getElementById('docDropZone').style.borderColor = '#0284c7';
    document.getElementById('docDropZone').style.background = '#f0f9ff';
    document.getElementById('btnSubirDoc').style.display = 'block';
}

function handleDocDrop(e) {
    e.preventDefault();
    document.getElementById('docDropZone').style.borderColor = '#cbd5e1';
    document.getElementById('docDropZone').style.background  = '#f8fafc';
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('inputDocFile');
    const dt    = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    actualizarDocSeleccionado(input);
}
</script>
@endif
