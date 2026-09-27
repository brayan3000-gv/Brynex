{{--
    Zona para subir un archivo: se toca para escoger o se arrastra encima.
    Recibe: $nombre (name del input), $requerido, $texto, $multiple (opcional).
--}}
@php $multiple = $multiple ?? false; @endphp
<label class="subir" x-data="{ archivos: [], encima: false }"
       :class="{ 'encima': encima, 'lleno': archivos.length }"
       @dragover.prevent="encima = true" @dragleave.prevent="encima = false"
       @drop.prevent="encima = false; $refs.input.files = $event.dataTransfer.files; archivos = [...$refs.input.files]">
    <input type="file" name="{{ $nombre }}" x-ref="input" accept=".pdf,.jpg,.jpeg,.png,.webp"
           @if($requerido) required @endif @if($multiple) multiple @endif
           @change="archivos = [...$event.target.files]">
    <span class="subir-ico">@include('portal._icono', ['n' => 'subir'])</span>
    <span class="subir-txt">
        <b x-show="!archivos.length">{{ $texto }}</b>
        <b x-show="archivos.length" x-cloak x-text="archivos.map(a => a.name).join(', ')"></b>
        <small x-show="!archivos.length">Toca para escoger o arrástralo aquí · PDF, JPG o PNG · máx. 10 MB</small>
        <small x-show="archivos.length" x-cloak>Listo · toca para cambiarlo</small>
    </span>
</label>

@once
@push('estilos')
<style>
    .subir { display: flex; align-items: center; gap: .9rem; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 1rem;
        cursor: pointer; background: #f8fafc; position: relative; transition: all .2s; }
    .subir:hover, .subir.encima { border-color: var(--acento); background: #eff6ff; }
    .subir.encima { transform: scale(1.01); }
    .subir.lleno { border-style: solid; border-color: #10b981; background: #ecfdf5; }
    .subir input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .subir-ico { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: #fff; color: var(--acento);
        box-shadow: var(--sombra); flex-shrink: 0; transition: transform .2s; }
    .subir:hover .subir-ico { transform: translateY(-2px); }
    .subir.lleno .subir-ico { color: #10b981; }
    .subir-txt { min-width: 0; }
    .subir-txt b { display: block; font-size: .88rem; overflow-wrap: anywhere; }
    .subir-txt small { display: block; font-size: .74rem; color: var(--tenue); }
</style>
@endpush
@endonce
