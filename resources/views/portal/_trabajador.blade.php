{{--
    Buscador de trabajador (contratos vigentes de la empresa). Deja el id del
    contrato en `contrato_id`. Recibe $trabajadores, $contratoElegido y,
    opcional, $opcional.
--}}
@php $opcional = $opcional ?? false; @endphp
<div class="campo trab-buscador" style="margin-bottom:.7rem"
     x-data="buscadorTrabajador(@js($trabajadores), {{ (int) old('contrato_id', $contratoElegido) }})"
     @click.outside="abierto = false" @keydown.escape="abierto = false">
    <span>Trabajador @if($opcional)<em>opcional</em>@endif</span>
    <input type="hidden" name="contrato_id" :value="elegido ? elegido.id : ''">
    <div class="trab-caja" :class="elegido && 'elegido'">
        <template x-if="elegido">
            <div class="trab-elegido">
                <span class="trab-av" x-text="elegido.nombre.charAt(0)"></span>
                <span style="min-width:0;flex:1"><b x-text="elegido.nombre"></b><small x-text="elegido.detalle"></small></span>
                <button type="button" @click="elegido = null; $nextTick(() => $refs.q.focus())" aria-label="Cambiar">@include('portal._icono', ['n' => 'cerrar', 'clase' => 'ic-sm'])</button>
            </div>
        </template>
        <input x-show="!elegido" x-ref="q" x-model="q" @focus="abierto = true" @input="abierto = true"
               placeholder="Escribe el nombre o la cédula" autocomplete="off" @if(! $opcional) :required="!elegido" @endif>
    </div>
    <div class="trab-lista tarjeta" x-show="abierto && !elegido" x-cloak x-transition:enter="t-fade-enter" x-transition:enter-start="t-fade-from">
        <template x-for="t in resultados" :key="t.id">
            <button type="button" @click="elegido = t; abierto = false; q = ''">
                <b x-text="t.nombre"></b><small x-text="t.detalle"></small>
            </button>
        </template>
        <div x-show="!resultados.length" class="sub" style="padding:.7rem .8rem">Nadie coincide. Solo salen los trabajadores con afiliación vigente.</div>
    </div>
</div>

@once
@push('estilos')
<style>
    .trab-buscador { position: relative; }
    .trab-caja { border: 1.5px solid var(--borde); border-radius: 10px; background: #fff; transition: border-color .15s, box-shadow .15s; }
    .trab-caja:focus-within { border-color: var(--acento); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
    .trab-caja.elegido { border-color: #93c5fd; background: #eff6ff; }
    .trab-caja input { border: 0 !important; box-shadow: none !important; }
    .trab-elegido { display: flex; align-items: center; gap: .6rem; padding: .5rem .6rem; }
    .trab-elegido b { display: block; font-size: .88rem; }
    .trab-elegido small { display: block; font-size: .74rem; color: var(--tenue); }
    .trab-elegido button { border: 0; background: #fff; border-radius: 8px; width: 30px; height: 30px; display: grid; place-items: center; cursor: pointer; color: var(--tenue); }
    .trab-av { width: 32px; height: 32px; border-radius: 50%; background: var(--azul-btn); color: #fff; font-weight: 800; display: grid; place-items: center; flex-shrink: 0; }
    .trab-lista { position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30; max-height: 280px; overflow-y: auto; box-shadow: var(--sombra-alta); }
    .trab-lista button { display: block; width: 100%; text-align: left; border: 0; background: none; font: inherit; padding: .6rem .8rem; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
    .trab-lista button:hover { background: #f8fbff; }
    .trab-lista b { display: block; font-size: .86rem; }
    .trab-lista small { display: block; font-size: .72rem; color: var(--tenue); }
</style>
@endpush
@push('scripts')
<script>
    function buscadorTrabajador(lista, elegidoId) {
        return {
            lista, q: '', abierto: false,
            elegido: lista.find(t => t.id === elegidoId) || null,
            get resultados() {
                const q = this.q.trim().toLowerCase();
                return this.lista.filter(t => !q || (t.nombre + ' ' + t.detalle).toLowerCase().includes(q)).slice(0, 40);
            },
        };
    }
</script>
@endpush
@endonce
