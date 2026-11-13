{{--
    Demo de BryNex: los videos del manual (App\Services\Demo\VideosManual).
      - La bienvenida con la presentación de 2 minutos, la primera vez en el inicio
        (se puede saltar).
      - El botón «🎬 ¿Cómo funciona?» del módulo de esta pantalla: primero el
        capítulo completo del módulo y debajo las tareas puntuales.
      - El reproductor grande y centrado, que también abren el panel DEMO y su
        recorrido (window.DemoVideos.abrir).
--}}
@php
    $capitulo = $modulo['capitulo'] ?? null;
    $lista = array_values(array_filter(array_merge([$capitulo], $modulo['clips'] ?? [])));
    $mapa = collect($lista)->keyBy('id')->all() + collect($pasos)->keyBy('id')->all()
        + ($comercial ? ['comercial' => $comercial] : []);
    $duracion = fn (int $s) => intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
@endphp
<style>
    #dv-boton{position:fixed;right:84px;bottom:22px;z-index:2147482500;display:flex;align-items:center;gap:.4rem;
        background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-radius:999px;padding:.5rem .9rem;cursor:pointer;
        font:700 13px/1 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 6px 20px rgba(15,23,42,.18)}
    #dv-boton:hover{background:#f8fafc}
    #dv-modal{position:fixed;inset:0;z-index:2147483100;background:rgba(2,6,23,.78);display:none;align-items:center;justify-content:center;padding:16px;
        font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}
    #dv-modal.abierto{display:flex}
    #dv-modal .dv-caja{background:#fff;color:#0f172a;border-radius:14px;width:min(1180px,96vw);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;
        box-shadow:0 30px 80px rgba(0,0,0,.45)}
    #dv-modal .dv-cab{display:flex;justify-content:space-between;align-items:center;gap:.6rem;padding:.7rem 1rem;border-bottom:1px solid #e2e8f0}
    #dv-modal .dv-cab h3{margin:0;font-size:1rem}
    #dv-modal .dv-voz{font-size:.78rem;color:#64748b}
    #dv-modal .dv-x{background:none;border:none;font-size:1.4rem;color:#94a3b8;cursor:pointer;line-height:1}
    #dv-modal .dv-cuerpo{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:0;overflow:hidden;min-height:0}
    #dv-modal .dv-ver{padding:1rem;overflow:auto}
    #dv-modal video{width:100%;max-height:68vh;border-radius:10px;background:#000;display:block}
    #dv-modal .dv-tit{font-weight:800;margin:.7rem 0 .25rem;font-size:1.02rem}
    #dv-modal .dv-pasos{margin:0;padding-left:1.1rem;color:#475569;font-size:.86rem}
    #dv-modal .dv-lado{border-left:1px solid #e2e8f0;overflow:auto;padding:.6rem .8rem;background:#f8fafc}
    #dv-modal .dv-grupo{font-size:.72rem;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin:.7rem 0 .3rem}
    #dv-modal .dv-lista{list-style:none;margin:0;padding:0}
    #dv-modal .dv-lista button{width:100%;text-align:left;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:.55rem .6rem;margin-bottom:.4rem;
        cursor:pointer;display:flex;justify-content:space-between;gap:.5rem;font:inherit;font-size:.85rem;color:#0f172a}
    #dv-modal .dv-lista button:hover{border-color:#93c5fd}
    #dv-modal .dv-lista button.actual{border-color:#2563eb;background:#eff6ff;color:#1d4ed8;font-weight:700}
    #dv-modal .dv-lista button.cap{border-width:2px}
    #dv-modal .dv-dur{color:#94a3b8;font-size:.76rem;white-space:nowrap}
    @media (max-width:860px){#dv-modal .dv-cuerpo{grid-template-columns:1fr;overflow:auto}#dv-modal .dv-lado{border-left:none;border-top:1px solid #e2e8f0}}
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
    <button id="dv-boton" type="button" onclick="DemoVideos.abrir(null)" title="Videos de este módulo">🎬<span>¿Cómo funciona?</span></button>
@endif

<div id="dv-modal" role="dialog" aria-label="Cómo funciona" onclick="if (event.target === this) DemoVideos.cerrar()">
    <div class="dv-caja">
        <div class="dv-cab">
            <h3 id="dv-cab-tit">🎬 Cómo funciona</h3>
            <span class="dv-voz">🔊 Los videos tienen voz: sube el volumen</span>
            <button class="dv-x" type="button" onclick="DemoVideos.cerrar()" aria-label="Cerrar">✕</button>
        </div>
        <div class="dv-cuerpo">
            <div class="dv-ver">
                <video id="dv-video" controls playsinline preload="metadata"></video>
                <div class="dv-tit" id="dv-tit"></div>
                <ol class="dv-pasos" id="dv-pasos"></ol>
            </div>
            @if ($lista)
                <div class="dv-lado">
                    @if ($capitulo)
                        <div class="dv-grupo" style="margin-top:.2rem">El módulo completo</div>
                        <ul class="dv-lista">
                            <li><button type="button" class="cap" data-id="{{ $capitulo['id'] }}" onclick="DemoVideos.abrir('{{ $capitulo['id'] }}')">
                                <span>⭐ {{ $capitulo['titulo'] }}</span><span class="dv-dur">{{ $duracion($capitulo['segundos']) }}</span></button></li>
                        </ul>
                    @endif
                    @if (! empty($modulo['clips']))
                        <div class="dv-grupo">Tareas puntuales</div>
                        <ul class="dv-lista">
                            @foreach ($modulo['clips'] as $c)
                                <li><button type="button" data-id="{{ $c['id'] }}" onclick="DemoVideos.abrir('{{ $c['id'] }}')">
                                    <span>▶ {{ $c['titulo'] }}</span><span class="dv-dur">{{ $duracion($c['segundos']) }}</span></button></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
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
    const PRIMERO = @json($lista[0]['id'] ?? null);
    const modal = document.getElementById('dv-modal');
    const video = document.getElementById('dv-video');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const abrirPanelDemo = () => document.getElementById('demo-panel')?.classList.add('abierto');
    // Que vio la presentación queda en su ficha de prospecto (RelevoDemo).
    let presentacionAnotada = false;
    const anotarPresentacion = () => {
        if (presentacionAnotada) return;
        presentacionAnotada = true;
        fetch(@json(route('demo.actividad')), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' }, body: JSON.stringify({ presentacion: true }) }).catch(() => {});
    };

    window.DemoVideos = {
        /** Un video por id; sin id, el capítulo del módulo (o su primer clip). */
        abrir(id) {
            const c = CLIPS[id || PRIMERO];
            if (!c) return;
            document.getElementById('demo-panel')?.classList.remove('abierto');
            document.getElementById('dv-cab-tit').textContent = c.id === 'comercial' ? '🎬 BryNex en dos minutos' : '🎬 Cómo funciona';
            video.src = c.url;
            if (c.id === 'comercial') anotarPresentacion();
            document.getElementById('dv-tit').textContent = c.titulo;
            document.getElementById('dv-pasos').innerHTML = c.capitulo
                ? '<li style="list-style:none;margin-left:-1.1rem">Recorrido completo del módulo, con voz y subtítulos.</li>'
                : (c.pasos || []).map(p => `<li>${esc(p)}</li>`).join('');
            document.querySelectorAll('#dv-modal .dv-lista button').forEach(b => b.classList.toggle('actual', b.dataset.id === c.id));
            modal.classList.add('abierto');
            video.play().catch(() => {});
        },
        cerrar() {
            video.pause();
            modal.classList.remove('abierto');
        },
        verPresentacion() {
            const medio = document.getElementById('dv-b-medio');
            if (!medio || !CLIPS.comercial) return this.abrir('comercial');
            medio.innerHTML = `<video src="${esc(CLIPS.comercial.url)}" controls autoplay playsinline></video>`;
            anotarPresentacion();
            const ver = document.getElementById('dv-b-ver');
            document.querySelector('#dv-bienvenida .dv-sec')?.remove();
            ver.textContent = 'Empezar a probar →';
            ver.onclick = () => this.saltar();
        },
        saltar() {
            const b = document.getElementById('dv-bienvenida');
            b?.querySelector('video')?.pause();
            b?.remove();
            abrirPanelDemo();
        },
    };

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('abierto')) DemoVideos.cerrar(); });

    // Con la bienvenida abierta, el panel DEMO espera a que se cierre.
    if (document.getElementById('dv-bienvenida')) {
        document.getElementById('demo-panel')?.classList.remove('abierto');
    }
})();
</script>
