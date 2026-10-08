{{--
    Modal que pide la contraseña nueva cuando un operador (Enlace / Simple)
    la rechaza. SuaporteApiService deja de intentar con la clave rechazada
    —cada intento cuenta para el bloqueo de la cuenta— hasta que se guarde
    otra desde aquí o desde Configuración → Operadores de planilla.

    "Más tarde" lo oculta por esta pestaña y ese mismo rechazo; si Enlace
    vuelve a rechazar, la marca cambia y reaparece.
--}}
@php($__credRechazadas = \App\Http\Controllers\Admin\OperadorCredencialController::rechazadasDelAliado((int) session('aliado_id_activo')))
@if($__credRechazadas || session('cred_modal_error'))
<div x-data="credRechazada(@js($__credRechazadas), @js((bool) session('cred_modal_error')))" x-cloak>
    <div x-show="abierto" class="cr-overlay" @keydown.escape.window="despues()">
        <div class="cr-box" role="dialog" aria-modal="true" aria-labelledby="cr-titulo">
            <template x-if="actual">
                {{-- `enviando` se marca en @submit, no en el clic del botón: si el
                     botón queda deshabilitado durante su propio clic, Chrome
                     cancela el envío y el modal se queda en «Guardando…». --}}
                <form method="POST" :action="'{{ url('admin/configuracion/operadores-credenciales') }}/' + actual.id + '/contrasena'"
                      @submit="enviando = true">
                    @csrf
                    <div class="cr-head">
                        <span class="cr-ico" x-text="actual.tipo === 'bloqueo' ? '🔒' : '🔑'"></span>
                        <h3 id="cr-titulo" x-text="actual.tipo === 'bloqueo'
                            ? actual.operador + ' bloqueó la cuenta'
                            : actual.operador + ' rechazó la contraseña'"></h3>
                    </div>

                    <p class="cr-txt">
                        Usuario <b x-text="actual.usuario"></b><span x-show="actual.razon"> · <span x-text="actual.razon"></span></span>.
                        <span x-show="actual.tipo === 'clave'">Si la cambiaste en el portal, escribe aquí la nueva. Mientras tanto BryNex no vuelve a intentar con la vieja, para no bloquear la cuenta.</span>
                        <span x-show="actual.tipo === 'bloqueo'">BryNex no intenta de nuevo hasta las <b x-text="actual.hasta"></b>. Si la contraseña cambió, guárdala ya y se usará sola cuando pase el bloqueo.</span>
                    </p>

                    <div class="cr-msg">
                        <small x-text="'Enlace, ' + actual.en + ':'"></small>
                        <span x-text="actual.mensaje"></span>
                    </div>

                    @if(session('cred_modal_error'))
                        <div class="cr-error">❌ {{ session('cred_modal_error') }}</div>
                    @endif

                    <label class="cr-label" for="cr-clave">Contraseña nueva</label>
                    <div class="cr-campo">
                        <input id="cr-clave" name="contrasena" :type="ver ? 'text' : 'password'" required
                               autocomplete="new-password" x-ref="clave" maxlength="200">
                        <button type="button" class="cr-ver" @click="ver = !ver" x-text="ver ? 'Ocultar' : 'Ver'"></button>
                    </div>

                    <div class="cr-foot">
                        <button type="button" class="cr-btn-ghost" @click="despues()">Más tarde</button>
                        <button type="submit" class="cr-btn" :disabled="enviando"
                                x-text="enviando ? 'Guardando…' : 'Guardar y probar'"></button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>

<style>
    .cr-overlay { position: fixed; inset: 0; z-index: 9000; background: rgba(10,22,40,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
    .cr-box { background: #fff; border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.25); width: 100%; max-width: 440px; padding: 1.4rem 1.5rem; color: #0f172a; }
    .cr-head { display: flex; align-items: center; gap: .6rem; margin-bottom: .6rem; }
    .cr-head h3 { margin: 0; font-size: 1.05rem; font-weight: 700; }
    .cr-ico { font-size: 1.4rem; }
    .cr-txt { font-size: .86rem; color: #334155; line-height: 1.45; margin: 0 0 .8rem; }
    .cr-msg { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.3); color: #991b1b; border-radius: 8px; padding: .55rem .7rem; font-size: .8rem; margin-bottom: .9rem; }
    .cr-msg small { display: block; color: #b91c1c; opacity: .8; margin-bottom: .15rem; }
    .cr-error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: #991b1b; border-radius: 8px; padding: .5rem .7rem; font-size: .8rem; margin-bottom: .8rem; }
    .cr-label { display: block; font-size: .8rem; font-weight: 600; color: #475569; margin-bottom: .3rem; }
    .cr-campo { display: flex; gap: .4rem; }
    .cr-campo input { flex: 1; min-width: 0; border: 1px solid #cbd5e1; border-radius: 8px; padding: .55rem .7rem; font-size: .9rem; }
    .cr-campo input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
    .cr-ver { border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 8px; padding: 0 .7rem; font-size: .78rem; color: #475569; cursor: pointer; }
    .cr-foot { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1.1rem; }
    .cr-btn { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: .55rem 1rem; font-weight: 600; font-size: .86rem; cursor: pointer; }
    .cr-btn:disabled { opacity: .6; cursor: wait; }
    .cr-btn-ghost { background: transparent; border: 1px solid #cbd5e1; color: #475569; border-radius: 8px; padding: .55rem 1rem; font-size: .86rem; cursor: pointer; }
</style>

<script>
    function credRechazada(lista, conError) {
        const leer = () => { try { return JSON.parse(sessionStorage.getItem('cred_rechazada_ocultas') || '[]'); } catch (e) { return []; } };
        const pendientes = () => lista.filter(c => conError || !leer().includes(c.marca));

        return {
            ver: false,
            enviando: false,
            actual: null,
            abierto: false,
            init() {
                this.actual = pendientes()[0] || null;
                this.abierto = !!this.actual;
                if (this.abierto) this.$nextTick(() => this.$refs.clave?.focus());
            },
            despues() {
                if (!this.actual) { this.abierto = false; return; }
                try {
                    const ocultas = leer();
                    ocultas.push(this.actual.marca);
                    sessionStorage.setItem('cred_rechazada_ocultas', JSON.stringify(ocultas));
                } catch (e) {}
                conError = false;
                this.ver = false;
                this.actual = pendientes()[0] || null;
                this.abierto = !!this.actual;
            },
        };
    }
</script>
@endif
