{{--
    Demo de BryNex: los videos del manual (App\Services\Demo\VideosManual).
      - La bienvenida con la presentación de 2 minutos, la primera vez en el inicio
        (se puede saltar).
      - El botón «🎬 ¿Cómo funciona?» del módulo de esta pantalla.
      - El reproductor lateral, que también abren el panel DEMO y su recorrido
        (window.DemoVideos.abrir).
--}}
@php
    $mapa = collect($modulo['clips'] ?? [])->keyBy('id')->all() + collect($pasos)->keyBy('id')->all()
        + ($comercial ? ['comercial' => $comercial] : []);
@endphp
<style>
    #dv-boton{position:fixed;right:84px;bottom:22px;z-index:2147482500;display:flex;align-items:center;gap:.4rem;
        background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-radius:999px;padding:.5rem .9rem;cursor:pointer;
        font:700 13px/1 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 6px 20px rgba(15,23,42,.18)}
    #dv-boton:hover{background:#f8fafc}
    #dv-panel{position:fixed;top:0;right:0;bottom:0;z-index:2147483100;width:460px;max-width:100vw;background:#fff;color:#0f172a;
        box-shadow:-12px 0 40px rgba(0,0,0,.25);display:none;flex-direction:column;font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}
    #dv-panel.abierto{display:flex}
    #dv-panel .dv-cab{display:flex;justify-content:space-between;align-items:center;padding:.8rem 1rem;border-bottom:1px solid #e2e8f0}
    #dv-panel .dv-cab h3{margin:0;font-size:1rem}
    #dv-panel .dv-x{background:none;border:none;font-size:1.3rem;color:#94a3b8;cursor:pointer}
    #dv-panel .dv-cuerpo{overflow:auto;padding:1rem}
    #dv-panel video{width:100%;border-radius:10px;background:#000;aspect-ratio:16/9}
    #dv-panel .dv-tit{font-weight:800;margin:.6rem 0 .25rem}
    #dv-panel .dv-pasos{margin:0 0 .8rem;padding-left:1.1rem;color:#475569;font-size:.84rem}
    #dv-panel .dv-lista{list-style:none;margin:0;padding:0;border-top:1px solid #f1f5f9}
    #dv-panel .dv-lista button{width:100%;text-align:left;background:none;border:none;border-bottom:1px solid #f1f5f9;padding:.55rem .2rem;cursor:pointer;
        display:flex;justify-content:space-between;gap:.5rem;font:inherit;color:#0f172a}
    #dv-panel .dv-lista button:hover{background:#f8fafc}
    #dv-panel .dv-lista button.actual{color:#1d4ed8;font-weight:700}
    #dv-panel .dv-dur{color:#94a3b8;font-size:.78rem;white-space:nowrap}
    #dv-bienvenida{position:fixed;inset:0;z-index:2147483200;background:rgba(2,6,23,.82);display:flex;align-items:center;justify-content:center;padding:16px;
        font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
    #dv-bienvenida .dv-caja{background:#0f172a;color:#e2e8f0;border-radius:16px;max-width:860px;width:100%;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.5)}
    #dv-bienvenida .dv-texto{padding:1.2rem 1.4rem}
    #dv-bienvenida h2{margin:0 0 .3rem;color:#fff;font-size:1.35rem}
    #dv-bienvenida .dv-medio{position:relative;background:#000;aspect-ratio:16/9}
    #dv-bienvenida .dv-medio img,#dv-bienvenida .dv-medio video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    #dv-bienvenida .dv-play{position:absolute;inset:0;margin:auto;width:84px;height:84px;border-radius:50%;border:none;background:rgba(255,255,255,.92);
        font-size:2rem;cursor:pointer;box-shadow:0 10px 30px rgba(0,0,0,.4)}
    #dv-bienvenida .dv-acc{display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap;padding:1rem 1.4rem}
    #dv-bienvenida .dv-btn{border:none;border-radius:10px;padding:.7rem 1.1rem;font-weight:700;cursor:pointer;font-size:.95rem}
    #dv-bienvenida .dv-prim{background:#16a34a;color:#fff}
    #dv-bienvenida .dv-sec{background:transparent;color:#cbd5e1;border:1px solid #334155}
    @media (max-width:640px){#dv-boton{right:76px;bottom:20px;padding:.45rem .7rem}#dv-boton span{display:none}}
</style>

@if ($modulo)
    <button id="dv-boton" type="button" onclick="DemoVideos.abrir(null)" title="Videos cortos de este módulo">🎬<span>¿Cómo funciona?</span></button>
@endif

<div id="dv-panel" role="dialog" aria-label="Cómo funciona">
    <div class="dv-cab">
        <h3 id="dv-cab-tit">🎬 Cómo funciona</h3>
        <button class="dv-x" type="button" onclick="DemoVideos.cerrar()">✕</button>
    </div>
    <div class="dv-cuerpo">
        <video id="dv-video" controls playsinline preload="metadata"></video>
        <div class="dv-tit" id="dv-tit"></div>
        <ol class="dv-pasos" id="dv-pasos"></ol>
        @if ($modulo)
            <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Videos de {{ $modulo['titulo'] }}</div>
            <ul class="dv-lista" id="dv-lista">
                @foreach ($modulo['clips'] as $c)
                    <li><button type="button" data-id="{{ $c['id'] }}" onclick="DemoVideos.abrir('{{ $c['id'] }}')">
                        <span>▶ {{ $c['titulo'] }}</span><span class="dv-dur">{{ $c['segundos'] }} s</span></button></li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

@if ($bienvenida && $comercial)
<div id="dv-bienvenida" role="dialog" aria-label="Bienvenida">
    <div class="dv-caja">
        <div class="dv-texto">
            <h2>¡Hola{{ $nombre ? ' '.$nombre : '' }}! Bienvenido a BryNex 👋</h2>
            <div style="color:#94a3b8">En 2 minutos te mostramos lo que BryNex hace por tu empresa de seguridad social. Después pruébalo tú: todo es simulado.</div>
        </div>
        <div class="dv-medio" id="dv-b-medio">
            <img src="{{ $comercial['portada'] }}" alt="BryNex en dos minutos">
            <button class="dv-play" type="button" onclick="DemoVideos.verPresentacion()" aria-label="Ver la presentación">▶</button>
        </div>
        <div class="dv-acc">
            <button class="dv-btn dv-sec" type="button" onclick="DemoVideos.saltar()">Saltar, quiero probar →</button>
            <button class="dv-btn dv-prim" type="button" id="dv-b-ver" onclick="DemoVideos.verPresentacion()">▶ Ver la presentación (2 min)</button>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    const CLIPS = @json($mapa);
    const MODULO = @json($modulo ? array_column($modulo['clips'], 'id') : []);
    const panel = document.getElementById('dv-panel');
    const video = document.getElementById('dv-video');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const abrirPanelDemo = () => document.getElementById('demo-panel')?.classList.add('abierto');

    window.DemoVideos = {
        /** Un clip por id; sin id, el primero del módulo de esta pantalla. */
        abrir(id) {
            const c = CLIPS[id || MODULO[0]];
            if (!c) return;
            document.getElementById('demo-panel')?.classList.remove('abierto');
            document.getElementById('dv-cab-tit').textContent = id === 'comercial' ? '🎬 BryNex en dos minutos' : '🎬 Cómo funciona';
            video.src = c.url;
            document.getElementById('dv-tit').textContent = c.titulo;
            document.getElementById('dv-pasos').innerHTML = (c.pasos || []).map(p => `<li>${esc(p)}</li>`).join('');
            document.querySelectorAll('#dv-lista button').forEach(b => b.classList.toggle('actual', b.dataset.id === c.id));
            panel.classList.add('abierto');
            video.play().catch(() => {});
        },
        cerrar() {
            video.pause();
            panel.classList.remove('abierto');
        },
        verPresentacion() {
            const medio = document.getElementById('dv-b-medio');
            if (!medio || !CLIPS.comercial) return this.abrir('comercial');
            medio.innerHTML = `<video src="${esc(CLIPS.comercial.url)}" controls autoplay playsinline></video>`;
            const v = medio.querySelector('video');
            const ver = document.getElementById('dv-b-ver');
            document.querySelector('#dv-bienvenida .dv-sec')?.remove();
            ver.textContent = 'Empezar a probar →';
            ver.onclick = () => this.saltar();
            v.addEventListener('ended', () => { ver.classList.add('dv-prim'); ver.focus(); });
        },
        saltar() {
            const b = document.getElementById('dv-bienvenida');
            b?.querySelector('video')?.pause();
            b?.remove();
            abrirPanelDemo();
        },
    };

    // Con la bienvenida abierta, el panel DEMO espera a que se cierre.
    if (document.getElementById('dv-bienvenida')) {
        document.getElementById('demo-panel')?.classList.remove('abierto');
    }
})();
</script>
