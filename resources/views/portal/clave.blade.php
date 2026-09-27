@extends('layouts.portal')
@section('titulo', 'Cambiar clave')

@section('contenido')
<div class="clave-caja tarjeta aparece" x-data="{ ver: false, c: '', c2: '',
        get fuerza() { let f = 0; if (this.c.length >= 8) f++; if (/[a-zA-Z]/.test(this.c) && /\d/.test(this.c)) f++; if (this.c.length >= 12) f++; if (/[^a-zA-Z0-9]/.test(this.c)) f++; return f; } }">
    <div class="clave-ico">@include('portal._icono', ['n' => 'llave'])</div>
    @if($acceso->debe_cambiar_clave)
        <h1>Crea tu clave</h1>
        <p>Entraste con una clave temporal. Escoge una propia para seguir; solo tú la vas a conocer.</p>
    @else
        <h1>Cambiar clave</h1>
        <p>Escoge una clave nueva para entrar al portal.</p>
    @endif

    <form method="POST" action="{{ route('portal.clave.guardar') }}">
        @csrf
        <label class="campo">
            <span>Clave nueva</span>
            <div class="entrada">
                <input :type="ver ? 'text' : 'password'" name="password" x-model="c" autocomplete="new-password" required minlength="8" autofocus>
                <button type="button" @click="ver = !ver" :aria-label="ver ? 'Ocultar' : 'Mostrar'">@include('portal._icono', ['n' => 'ojo', 'clase' => 'ic-sm'])</button>
            </div>
        </label>
        <div class="fuerza"><i :style="`width:${fuerza * 25}%;background:${['#e2e8f0', '#ef4444', '#f59e0b', '#10b981', '#059669'][fuerza]}`"></i></div>
        <div class="sub" style="margin-bottom:.9rem">Mínimo 8 caracteres, con letras y números.</div>

        <label class="campo">
            <span>Repite la clave</span>
            <div class="entrada">
                <input :type="ver ? 'text' : 'password'" name="password_confirmation" x-model="c2" autocomplete="new-password" required>
            </div>
        </label>
        <div class="sub" x-show="c2 && c !== c2" x-cloak style="color:#b91c1c">Las dos claves no coinciden.</div>

        @if($errors->any())
            <div class="aviso aviso-warn" style="margin-top:.8rem">{{ $errors->first() }}</div>
        @endif

        <button type="submit" class="btn" style="width:100%;margin-top:1rem;padding:.8rem" :disabled="c.length < 8 || c !== c2">Guardar clave</button>
    </form>
</div>
@endsection

@push('estilos')
<style>
    .clave-caja { max-width: 420px; margin: 1.5rem auto; padding: 1.6rem 1.4rem; }
    .clave-caja h1 { font-size: 1.35rem; margin: .8rem 0 .3rem; }
    .clave-caja p { color: var(--tenue); font-size: .88rem; margin: 0 0 1.2rem; }
    .clave-ico { width: 54px; height: 54px; border-radius: 16px; display: grid; place-items: center; color: #fff;
        background: linear-gradient(135deg, var(--azul-oscuro), var(--azul-vivo)); box-shadow: 0 8px 20px rgba(30,64,175,.3); }
    .clave-ico .ic { width: 26px; height: 26px; }
    .campo { display: block; margin-bottom: .5rem; }
    .campo > span { display: block; font-size: .75rem; font-weight: 700; color: var(--tinta-2); margin-bottom: .3rem; }
    .entrada { display: flex; align-items: center; border: 1.5px solid var(--borde); border-radius: 10px; background: #fff;
        transition: border-color .15s, box-shadow .15s; }
    .entrada:focus-within { border-color: var(--acento); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
    .entrada input { flex: 1; border: 0; outline: 0; font: inherit; padding: .7rem .8rem; background: none; min-width: 0; }
    .entrada button { border: 0; background: none; color: var(--tenue); padding: 0 .8rem; cursor: pointer; display: flex; }
    .fuerza { height: 5px; border-radius: 9px; background: #e2e8f0; overflow: hidden; margin: .4rem 0 .35rem; }
    .fuerza i { display: block; height: 100%; transition: width .3s, background .3s; }
    .sub { font-size: .76rem; color: var(--tenue); }
    .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
</style>
@endpush
