{{-- Mensaje de la última acción de la fila (tandaX1). --}}
<div class="bx1-msg" :class="ok ? 'ok' : 'error'" x-show="mensaje" x-cloak>
    <span x-text="mensaje"></span>
    <template x-if="errores.length">
        <ul style="margin:.25rem 0 0;padding-left:1rem">
            <template x-for="e in errores"><li x-text="e"></li></template>
        </ul>
    </template>
</div>
