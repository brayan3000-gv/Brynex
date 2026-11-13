{{-- Demo de BryNex: aviso flotante en todas las páginas (lo mete InyectarDemo). --}}
@php
    $wa = 'https://wa.me/'.config('demo.contacto_whatsapp').'?text='.rawurlencode('Hola, probé la demo de BryNex y quiero más información.');
@endphp
<style>
    #demo-franja{position:fixed;left:14px;bottom:14px;z-index:2147483000;display:flex;align-items:center;gap:.5rem;
        background:#111827;color:#fff;border-radius:999px;padding:.4rem .5rem .4rem .85rem;font:600 12px/1.2 system-ui,-apple-system,Segoe UI,sans-serif;
        box-shadow:0 6px 24px rgba(0,0,0,.25);max-width:calc(100vw - 28px)}
    #demo-franja .demo-pill{background:#f59e0b;color:#111827;border-radius:999px;padding:.15rem .5rem;font-weight:800;letter-spacing:.04em}
    #demo-franja a{background:#16a34a;color:#fff;text-decoration:none;border-radius:999px;padding:.3rem .7rem;white-space:nowrap}
    #demo-franja button{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:14px;padding:0 .25rem}
    /* Encogida queda solo la pastilla: no tapa el campo de escribir del chat ni los botones de abajo. */
    #demo-franja.min{padding:.3rem;cursor:pointer}
    #demo-franja.min .demo-txt,#demo-franja.min a,#demo-franja.min button{display:none}
    #demo-franja.min:hover .demo-txt,#demo-franja.min:hover a{display:inline}
    #demo-franja.min:hover{padding:.4rem .5rem .4rem .85rem}
    @media (max-width:640px){#demo-franja .demo-txt{display:none}}
</style>
<div id="demo-franja" role="status" title="Estás en la demo de BryNex">
    <span class="demo-pill">DEMO</span>
    <span class="demo-txt">Datos de prueba · Las ARL, EPS y operadores se simulan: nada sale a las entidades</span>
    <a href="{{ $wa }}" target="_blank" rel="noopener">Quiero BryNex</a>
    <button type="button" title="Encoger">⇆</button>
</div>
<script>
(function () {
    // Completa en la primera página que se abre; después, encogida.
    const f = document.getElementById('demo-franja');
    let vista = false;
    try { vista = sessionStorage.getItem('demo-franja-vista') === '1'; sessionStorage.setItem('demo-franja-vista', '1'); } catch (e) {}
    const encoger = () => f.classList.add('min');
    if (vista) encoger(); else setTimeout(encoger, 8000);
    f.querySelector('button').onclick = (e) => { e.stopPropagation(); encoger(); };
})();
</script>

@if ($enLogin)
    {{-- Entrada de la demo: la cuenta compartida ya escrita y a la vista. --}}
    <script>
    (function () {
        const form = document.querySelector('form[action*="login"]');
        if (!form) return;
        const ced = form.querySelector('input[name="cedula"]');
        const pass = form.querySelector('input[name="password"]');
        if (ced && !ced.value) ced.value = @json((string) config('demo.usuario'));
        if (pass && !pass.value) pass.value = @json((string) config('demo.clave'));

        const nota = document.createElement('div');
        nota.style.cssText = 'background:#fffbeb;border:1px solid #fcd34d;color:#78350f;border-radius:10px;padding:.7rem .85rem;margin:0 0 1rem;font-size:.82rem;line-height:1.45;text-align:left;';
        nota.innerHTML = '<strong>🧪 Bienvenido a la demo de BryNex</strong><br>'
            + 'Ya dejamos escrita la cuenta de prueba: solo pulsa <strong>Ingresar</strong>.<br>'
            + 'Crea personas, empresas y contratos, y radica en ARL y EPS «por API»: todo es simulado y se reinicia cada noche.'
            + '<div style="margin-top:.4rem;font-family:ui-monospace,monospace;font-size:.78rem;">Usuario: {{ e(config('demo.usuario')) }} · Clave: {{ e(config('demo.clave')) }}</div>';
        form.parentNode.insertBefore(nota, form);
    })();
    </script>
@endif
