<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BryNex — Iniciar Sesión</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #0a1628 0%, #0d2550 50%, #1a3a72 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        /* Partículas de fondo */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(59,130,246,0.08) 0%, transparent 60%),
                radial-gradient(circle at 80% 80%, rgba(99,102,241,0.08) 0%, transparent 60%);
            pointer-events: none;
        }

        .card {
            background: rgba(255,255,255,0.04);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 2.5rem 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }

        .logo-wrap {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-wrap img {
            height: 70px;
            object-fit: contain;
        }

        .titulo {
            color: rgba(255,255,255,0.6);
            font-size: 0.8rem;
            font-weight: 400;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            text-align: center;
            margin-top: 0.5rem;
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(99,130,246,0.4), transparent);
            margin: 1.5rem 0;
        }

        .form-group { margin-bottom: 1.25rem; }

        label {
            display: block;
            color: rgba(255,255,255,0.7);
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 0.4rem;
        }

        .input-wrap { position: relative; }

        .input-wrap .icon {
            position: absolute;
            left: 0.9rem;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.35);
            font-size: 1rem;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.6rem;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            color: #fff;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }

        input:focus {
            border-color: #3b82f6;
            background: rgba(59,130,246,0.08);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        input::placeholder { color: rgba(255,255,255,0.25); }

        .remember {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: rgba(255,255,255,0.55);
            font-size: 0.82rem;
            margin-bottom: 1.5rem;
            cursor: pointer;
        }

        .remember input { width: auto; padding: 0; }

        .btn-login {
            width: 100%;
            padding: 0.85rem;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s, background 0.2s;
            box-shadow: 0 4px 20px rgba(37,99,235,0.4);
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(37,99,235,0.55);
        }

        .btn-login:active { transform: translateY(0); }

        .error-msg {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 8px;
            color: #fca5a5;
            font-size: 0.82rem;
            padding: 0.6rem 0.9rem;
            margin-bottom: 1rem;
        }

        .footer-txt {
            text-align: center;
            color: rgba(255,255,255,0.25);
            font-size: 0.72rem;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo-wrap">
            <img src="{{ asset('img/logo-brynex.png') }}" alt="BryNex">
            <p class="titulo">Asesores en Seguridad Social</p>
        </div>

        <div class="divider"></div>

        @if ($errors->any())
            <div class="error-msg">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.submit') }}">
            @csrf

            <div class="form-group">
                <label for="cedula">Cédula o NIT</label>
                <div class="input-wrap">
                    <span class="icon">👤</span>
                    <input
                        type="text"
                        id="cedula"
                        name="cedula"
                        value="{{ old('cedula') }}"
                        placeholder="Cédula, o NIT si eres empresa"
                        autocomplete="username"
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <div class="input-wrap">
                    <span class="icon">🔒</span>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                    >
                </div>
            </div>

            <label class="remember">
                <input type="checkbox" name="remember"> Recordarme
            </label>

            <button type="submit" class="btn-login">Ingresar</button>

            {{-- Huella del equipo. Se combina con la cookie de dispositivo para
                 reconocer la máquina aunque cambie de red — ver
                 RegistroAccesoService. Si el navegador no ejecuta JS queda
                 vacía y el acceso se registra igual, solo que sin esta señal. --}}
            <input type="hidden" name="huella" id="huella">
        </form>

        <p class="footer-txt">© {{ date('Y') }} BryNex · Sistema de Gestión</p>
    </div>

    <script>
    // Huella del equipo: rasgos estables del navegador y del hardware. Cambia
    // si es otra máquina, no si cambió la IP — que es justo lo contrario de lo
    // que aporta la dirección de red. El más específico de todos es el
    // renderizador WebGL: identifica el modelo de tarjeta gráfica.
    (function () {
        var partes = [];
        var push = function (fn) {
            try { partes.push(String(fn())); } catch (e) { partes.push('?'); }
        };

        push(function () { return navigator.userAgent; });
        push(function () { return navigator.language + '|' + (navigator.languages || []).join(','); });
        push(function () { return screen.width + 'x' + screen.height + 'x' + screen.colorDepth; });
        push(function () { return window.devicePixelRatio; });
        push(function () { return Intl.DateTimeFormat().resolvedOptions().timeZone; });
        push(function () { return new Date().getTimezoneOffset(); });
        push(function () { return navigator.hardwareConcurrency; });
        push(function () { return navigator.deviceMemory; });
        push(function () { return navigator.maxTouchPoints; });

        push(function () {
            var gl = document.createElement('canvas').getContext('webgl');
            if (!gl) { return 'no-webgl'; }
            var info = gl.getExtension('WEBGL_debug_renderer_info');
            if (!info) { return gl.getParameter(gl.VERSION); }
            return gl.getParameter(info.UNMASKED_VENDOR_WEBGL) + '|' + gl.getParameter(info.UNMASKED_RENDERER_WEBGL);
        });

        // Hash FNV-1a de 32 bits: comprime la huella a algo corto y opaco. No
        // es criptográfico — el servidor le aplica sha256 antes de guardarla.
        var texto = partes.join('~');
        var h = 0x811c9dc5;
        for (var i = 0; i < texto.length; i++) {
            h ^= texto.charCodeAt(i);
            h = (h + (h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24)) >>> 0;
        }

        var campo = document.getElementById('huella');
        if (campo) { campo.value = h.toString(16) + '-' + texto.length; }
    })();
    </script>
</body>
</html>
