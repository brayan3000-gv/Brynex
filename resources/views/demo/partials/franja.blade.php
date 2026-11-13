{{--
    Demo de BryNex: lo mete InyectarDemo en todas las páginas.
      - En la entrada: el registro (nombre, empresa, celular) en lugar del login.
      - Adentro: la pastilla DEMO que abre el panel con el recorrido guiado y la
        conexión del WhatsApp de quien prueba (ver RelevoDemo).
--}}
@php
    $wa = 'https://wa.me/'.config('demo.contacto_whatsapp').'?text='.rawurlencode('Hola, probé la demo de BryNex y quiero más información.');
    $prospecto = session('demo_prospecto');
    $hechos = (array) session('demo_pasos', []);
    $pasos = [
        'persona' => ['Crea una persona con su cédula', 'Clientes → Nuevo cliente: la consulta al registro oficial llena la ficha.', url('/admin/clientes')],
        'contrato' => ['Asóciala a una empresa y hazle el contrato', 'En su ficha: empresa y «+ Nuevo contrato». BryNex calcula la cotización.', url('/admin/clientes')],
        'radicar' => ['Afíliala a la ARL y la EPS por API', 'Afiliaciones → toca el radicado pendiente → «Afiliar por API».', url('/admin/afiliaciones')],
        'planilla' => ['Liquida la planilla y págala por PSE', 'Planos SS → elige la razón social → Descargar plano → Liquidar.', url('/admin/planos')],
        'whatsapp' => ['Recíbela en tu WhatsApp', 'Envía una planilla, un cobro o un mensaje del chat: te llega a ti.', url('/admin/whatsapp/chat')],
        'ia' => ['Pregúntale al asistente de IA', 'El botón 🤖 de abajo a la derecha.', null],
    ];
    $listos = count(array_intersect(array_keys($pasos), $hechos));
    $videoDelPaso = \App\Services\Demo\VideosManual::PASOS;
@endphp
<style>
    #demo-franja{position:fixed;left:14px;bottom:14px;z-index:2147483000;display:flex;align-items:center;gap:.5rem;cursor:pointer;
        background:#111827;color:#fff;border-radius:999px;padding:.4rem .7rem .4rem .45rem;font:600 12px/1.2 system-ui,-apple-system,Segoe UI,sans-serif;
        box-shadow:0 6px 24px rgba(0,0,0,.25);border:none;width:auto;margin:0}
    #demo-franja .demo-pill{background:#f59e0b;color:#111827;border-radius:999px;padding:.15rem .5rem;font-weight:800;letter-spacing:.04em}
    #demo-panel{position:fixed;left:14px;bottom:60px;z-index:2147483000;width:340px;max-width:calc(100vw - 28px);max-height:calc(100vh - 90px);overflow:auto;
        background:#fff;color:#0f172a;border-radius:14px;box-shadow:0 14px 40px rgba(0,0,0,.28);font:13px/1.4 system-ui,-apple-system,Segoe UI,sans-serif;display:none}
    #demo-panel.abierto{display:block}
    #demo-panel .dp-cab{background:#111827;color:#fff;padding:.75rem .9rem;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center}
    #demo-panel .dp-sec{padding:.75rem .9rem;border-bottom:1px solid #f1f5f9}
    #demo-panel h4{margin:0 0 .45rem;font-size:.8rem;color:#475569;text-transform:uppercase;letter-spacing:.04em}
    #demo-panel ol{list-style:none;margin:0;padding:0}
    #demo-panel li{display:flex;gap:.5rem;padding:.35rem 0;align-items:flex-start}
    #demo-panel li .dp-chk{flex:0 0 1.2rem;height:1.2rem;border-radius:50%;border:2px solid #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:.7rem;color:#fff;margin-top:.1rem}
    #demo-panel li.hecho .dp-chk{background:#16a34a;border-color:#16a34a}
    #demo-panel li.hecho .dp-tit{color:#64748b;text-decoration:line-through}
    #demo-panel .dp-tit{font-weight:700;color:#0f172a;text-decoration:none}
    #demo-panel a.dp-tit:hover{color:#1d4ed8}
    #demo-panel .dp-ayuda{font-size:.74rem;color:#64748b}
    #demo-panel .dp-btn{display:inline-flex;align-items:center;gap:.35rem;background:#16a34a;color:#fff;border-radius:8px;padding:.45rem .8rem;font-weight:700;text-decoration:none;font-size:.8rem}
    #demo-panel .dp-cod{font:800 1.05rem ui-monospace,monospace;background:#f1f5f9;border-radius:6px;padding:.1rem .45rem;letter-spacing:.06em}
    #demo-panel .dp-x{background:none;border:none;color:#9ca3af;font-size:1.1rem;cursor:pointer}
    #demo-panel .dp-ver{color:#1d4ed8;font-weight:700;text-decoration:none;white-space:nowrap}
</style>

@if (! $enLogin && auth()->check())
<button id="demo-franja" type="button" title="Tu recorrido por la demo" onclick="document.getElementById('demo-panel').classList.toggle('abierto')">
    <span class="demo-pill">DEMO</span><span id="demo-franja-txt">Recorrido {{ $listos }}/{{ count($pasos) }}</span>
</button>
<div id="demo-panel" role="dialog" aria-label="Demo de BryNex">
    <div class="dp-cab">
        <div><div style="font-weight:800">🧪 Demo de BryNex</div>
            <div style="font-size:.72rem;color:#cbd5e1">Datos inventados · las entidades se simulan · se reinicia cada noche</div></div>
        <button class="dp-x" type="button" onclick="document.getElementById('demo-panel').classList.remove('abierto')">✕</button>
    </div>

    @if ($prospecto && ! empty($prospecto['codigo']))
    <div class="dp-sec" id="dp-wa">
        <h4>📲 Tu WhatsApp</h4>
        <div id="dp-wa-cuerpo" class="dp-ayuda">Consultando…</div>
    </div>
    @endif

    <div class="dp-sec">
        <h4>Recorrido · {{ $listos }} de {{ count($pasos) }}</h4>
        <ol>
        @foreach ($pasos as $clave => [$titulo, $ayuda, $enlace])
            <li class="{{ in_array($clave, $hechos, true) ? 'hecho' : '' }}">
                <span class="dp-chk">✓</span>
                <div>
                    @if ($enlace)<a class="dp-tit" href="{{ $enlace }}">{{ $loop->iteration }}. {{ $titulo }}</a>
                    @else<span class="dp-tit">{{ $loop->iteration }}. {{ $titulo }}</span>@endif
                    <div class="dp-ayuda">{{ $ayuda }}
                        @isset($videoDelPaso[$clave])
                            <a href="#" class="dp-ver" onclick="event.preventDefault(); window.DemoVideos && DemoVideos.abrir('{{ $videoDelPaso[$clave] }}')">▶ ver cómo</a>
                        @endisset
                    </div>
                </div>
            </li>
        @endforeach
        </ol>
    </div>
    <div class="dp-sec" style="border:none;text-align:center;display:flex;flex-direction:column;gap:.5rem;align-items:center">
        <a href="#" class="dp-ver" style="font-size:.82rem" onclick="event.preventDefault(); window.DemoVideos && DemoVideos.abrir('comercial')">🎬 Ver la presentación (2 min)</a>
        <a class="dp-btn" href="{{ $wa }}" target="_blank" rel="noopener">💬 Quiero BryNex para mi empresa</a>
    </div>
</div>
<script>
(function () {
    // El panel se abre solo la primera vez; después queda la pastilla.
    try {
        if (sessionStorage.getItem('demo-panel-visto') !== '1') {
            sessionStorage.setItem('demo-panel-visto', '1');
            document.getElementById('demo-panel').classList.add('abierto');
        }
    } catch (e) {}

    const caja = document.getElementById('dp-wa-cuerpo');
    if (!caja) return;
    const nombre = @json($prospecto['nombre'] ?? '');
    let espera = null;

    const pintar = (d) => {
        if (!d.activo) {
            caja.innerHTML = 'En esta demo los envíos solo se ven en el chat.';
            return false;
        }
        if (d.verificado) {
            caja.innerHTML = `<div style="color:#15803d;font-weight:700">✅ Conectado</div>
                Cuando envíes una planilla, un cobro o un mensaje por WhatsApp, te llega a ti.
                <div style="margin-top:.3rem"><strong>${d.envios}</strong> de <strong>${d.max}</strong> envíos de prueba usados.</div>`;
            return true;
        }
        const texto = encodeURIComponent(`DEMO ${d.codigo} — Hola, soy ${nombre} y estoy probando la demo de BryNex.`);
        const enlace = `https://wa.me/${d.numero}?text=${texto}`;
        if (d.bienvenida) {
            // Le llegó la plantilla: basta con tocar el botón. El código queda de respaldo.
            const cel = String(d.celular || '').replace(/(\d{3})(\d{3})(\d+)/, '$1 $2 $3');
            caja.innerHTML = `Te enviamos un WhatsApp al <strong>${cel}</strong>.
                <div style="margin:.45rem 0;font-weight:700;color:#0f172a">Ábrelo y toca «Sí, quiero los ejemplos».</div>
                Así te llega lo que envíes desde la demo (máximo ${d.max} mensajes).
                <div style="margin-top:.4rem;font-size:.72rem">⏳ Esperando tu respuesta…</div>
                <div style="margin-top:.5rem;font-size:.72rem">¿No te llegó? Escríbenos <span class="dp-cod" style="font-size:.8rem">DEMO ${d.codigo}</span>
                    <a href="${enlace}" target="_blank" rel="noopener" style="color:#16a34a;font-weight:700">desde aquí</a>.</div>`;
            return false;
        }
        caja.innerHTML = `Recibe en tu celular lo que envíes desde la demo (máximo ${d.max} mensajes).
            <div style="margin:.5rem 0">Escríbenos por WhatsApp el código <span class="dp-cod">DEMO ${d.codigo}</span></div>
            <a class="dp-btn" href="${enlace}" target="_blank" rel="noopener">Abrir WhatsApp</a>
            <div style="margin-top:.4rem;font-size:.72rem">⏳ Esperando tu mensaje…</div>`;
        return false;
    };

    const consultar = async () => {
        try {
            const r = await fetch(@json(route('demo.whatsapp.estado')), { headers: { Accept: 'application/json' } });
            const d = await r.json();
            if (pintar(d) && espera) { clearInterval(espera); espera = null; }
            return d;
        } catch (e) { caja.textContent = 'No se pudo consultar ahora.'; }
    };

    consultar().then(d => { if (d && d.activo && !d.verificado) espera = setInterval(consultar, 5000); });
})();
</script>
@endif

@if (! empty($cierre))
    {{-- Completó el recorrido: el momento de invitarlo a hablar (y a producción le llega el aviso). --}}
    <div id="demo-cierre" style="position:fixed;inset:0;z-index:2147483300;background:rgba(2,6,23,.75);display:flex;align-items:center;justify-content:center;padding:16px;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif">
        <div style="background:#fff;color:#0f172a;border-radius:16px;max-width:520px;width:100%;padding:1.6rem 1.5rem;text-align:center;box-shadow:0 30px 80px rgba(0,0,0,.4)">
            <div style="font-size:2.4rem;line-height:1">🎉</div>
            <h2 style="margin:.5rem 0 .3rem;font-size:1.35rem">¡Recorriste toda la demo{{ ($prospecto['nombre'] ?? '') ? ', '.explode(' ', trim($prospecto['nombre']))[0] : '' }}!</h2>
            <p style="margin:0 0 1.1rem;color:#475569">Afiliaste, liquidaste la planilla, la enviaste por WhatsApp y le preguntaste a la IA.
                ¿Te imaginas esto con tu gente y tus empresas? Te ayudamos a pasar tu operación a BryNex.</p>
            <a href="{{ $wa }}" target="_blank" rel="noopener" style="display:block;background:#16a34a;color:#fff;border-radius:10px;padding:.8rem 1rem;font-weight:800;text-decoration:none;margin-bottom:.6rem">💬 Quiero BryNex para mi empresa</a>
            <button type="button" onclick="document.getElementById('demo-cierre').remove()" style="background:none;border:none;color:#64748b;cursor:pointer;font:inherit">Seguir explorando la demo</button>
        </div>
    </div>
@endif

@if ($enLogin)
    {{-- La entrada de la demo: registro corto en vez del login (el login sigue a un clic). --}}
    <template id="demo-registro">
        <div id="demo-registro-caja" style="text-align:left">
            <div style="background:#fffbeb;border:1px solid #fcd34d;color:#78350f;border-radius:10px;padding:.7rem .85rem;margin:0 0 1rem;font-size:.82rem;line-height:1.45;">
                <strong>🧪 Prueba BryNex gratis</strong><br>
                Crea personas, empresas y contratos, y afilia a la ARL y la EPS «por API». Todo es simulado:
                nada sale a las entidades. Si quieres, te llegan los ejemplos a tu WhatsApp.
            </div>
            @if ($errors->any())
                <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:.5rem .7rem;margin-bottom:.8rem;font-size:.8rem;">
                    @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif
            <form method="POST" action="{{ route('demo.registro') }}" style="display:flex;flex-direction:column;gap:.6rem">
                @csrf
                <label class="demo-lbl">Tu nombre
                    <input name="nombre" required maxlength="120" value="{{ old('nombre') }}" autocomplete="name"></label>
                <label class="demo-lbl">Empresa (opcional)
                    <input name="empresa" maxlength="150" value="{{ old('empresa') }}" autocomplete="organization"></label>
                <label class="demo-lbl">Celular (WhatsApp)
                    <input name="celular" required inputmode="tel" maxlength="16" placeholder="300 123 4567" value="{{ old('celular') }}" autocomplete="tel"></label>
                <label style="display:flex;gap:.45rem;align-items:flex-start;font-size:.74rem;line-height:1.35;color:#cbd5e1;text-transform:none;letter-spacing:normal;font-weight:400;">
                    <input type="checkbox" name="acepto" value="1" required {{ old('acepto') ? 'checked' : '' }} style="margin-top:.15rem">
                    <span>Autorizo a BryNex a guardar estos datos y a escribirme por WhatsApp sobre la demo y sus servicios.</span>
                </label>
                <button type="submit" class="btn-login" style="margin-top:.2rem">Entrar a la demo</button>
            </form>
            <p style="text-align:center;margin:.8rem 0 0;font-size:.75rem;"><a href="#" id="demo-con-clave" style="color:#93c5fd;">Entrar con usuario y clave</a></p>
        </div>
    </template>
    <style>
        .demo-lbl{display:flex;flex-direction:column;gap:.25rem;font-size:.78rem;font-weight:600;color:#cbd5e1}
        .demo-lbl input{padding:.6rem .7rem;border-radius:8px;border:1px solid rgba(148,163,184,.35);background:rgba(15,23,42,.35);color:#fff;font-size:.9rem}
    </style>
    <script>
    (function () {
        const form = document.querySelector('form[action*="login"]');
        if (!form) return;
        // Debajo queda el login de siempre, con la cuenta compartida ya escrita.
        const ced = form.querySelector('input[name="cedula"]');
        const pass = form.querySelector('input[name="password"]');
        if (ced && !ced.value) ced.value = @json((string) config('demo.usuario'));
        if (pass && !pass.value) pass.value = @json((string) config('demo.clave'));

        const caja = document.getElementById('demo-registro').content.cloneNode(true);
        form.parentNode.insertBefore(caja, form);
        const errorLogin = document.querySelector('.error-msg');
        const usarClave = !!errorLogin && !@json($errors->has('nombre') || $errors->has('celular') || $errors->has('acepto'));
        form.style.display = usarClave ? '' : 'none';
        if (usarClave) document.getElementById('demo-registro-caja').style.display = 'none';
        else if (errorLogin) errorLogin.style.display = 'none';
        document.getElementById('demo-con-clave').onclick = (e) => {
            e.preventDefault();
            document.getElementById('demo-registro-caja').style.display = 'none';
            form.style.display = '';
        };
    })();
    </script>
@endif
