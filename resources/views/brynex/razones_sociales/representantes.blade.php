@extends('layouts.app')
@section('modulo', 'Representantes legales')
@section('contenido')

{{--
    Una tarjeta por PERSONA. Las empresas de cada una salen de la cédula del
    representante que tienen anotada los aliados en sus razones sociales; las
    claves son personales y sirven para todas esas empresas.
--}}

@php
    $Rep = \App\Models\BrynexRepresentante::class;
    $Cred = \App\Models\BrynexRepresentanteCredencial::class;
    $Svc = \App\Services\BrynexRepresentanteService::class;
    $sinCorreo = $representantes->filter(fn ($r) => ! $r->correo_rut && $r->empresas->isNotEmpty())->count();
@endphp

<div style="max-width:1200px;margin:0 auto;"
     x-data="{
        buscar: '',
        verDatos: false,
        // Objetos y no null: los campos usan x-model y contra null revientan.
        datos: { cedula: '', tipo_documento: 'CC', nombre: '', correo_rut: '', telefono: '', notas: '', nuevo: true },
        verClave: false,
        clave: { cedula: '', nombre: '', tipo_documento: 'CC', persona: '', credencial_id: '', tipo: 'CORREO', entidad: '', usuario: '', link_acceso: '', observacion: '' },
        sugeridas: {{ Js::from($Cred::ENTIDAD_SUGERIDA) }},
        revelada: {},
        coincide(texto) {
            const q = this.buscar.trim().toLowerCase();
            return q === '' || texto.includes(q);
        },
        nuevaClave(rep) {
            this.clave = Object.assign({ credencial_id: '', tipo: 'CORREO', entidad: this.sugeridas.CORREO, usuario: rep.correo_rut || '', link_acceso: '', observacion: '' }, rep);
            this.verClave = true;
        },
        prueba: {},
        async probarBuzon(id) {
            this.prueba[id] = { cargando: true };
            try {
                const r = await fetch('{{ url('brynex/razones-sociales/representantes') }}/' + id + '/probar-buzon', {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                this.prueba[id] = await r.json();
            } catch (e) {
                this.prueba[id] = { ok: false, error: 'No se pudo conectar con BryNex.' };
            }
        },
        async revelar(id) {
            const r = await fetch('{{ url('brynex/razones-sociales/representantes/claves') }}/' + id + '/revelar', { headers: { Accept: 'application/json' } });
            const d = await r.json().catch(() => ({}));
            if (!r.ok) { alert(d.error || 'No se pudo revelar la clave.'); return; }
            this.revelada[id] = d.contrasena ?? '(vacía)';
        }
     }">

    {{-- ── Cabecera ────────────────────────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <a href="{{ route('brynex.razones.index') }}" style="color:#64748b;font-size:0.78rem;text-decoration:none;">← Razones sociales</a>
            <h1 style="font-size:1.45rem;font-weight:800;color:#0d2550;margin:0.3rem 0 0 0;">👤 Representantes legales</h1>
            <p style="color:#64748b;font-size:0.83rem;margin:0.2rem 0 0 0;max-width:760px;">
                Una ficha por persona. Sus claves (correo, DIAN, facturación electrónica) se guardan una sola vez y sirven para
                todas sus empresas. Las empresas salen solas de la cédula del representante que tiene anotada cada aliado.
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <input type="search" x-model="buscar" placeholder="Buscar persona, cédula o empresa…"
                   style="padding:0.5rem 0.7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;min-width:240px;">
            @can('brynex_razones.gestionar')
                <button type="button"
                        @click="datos = { cedula: '', tipo_documento: 'CC', nombre: '', correo_rut: '', telefono: '', notas: '', nuevo: true }; verDatos = true"
                        style="background:#1e3a8a;color:#fff;border:none;padding:0.5rem 0.9rem;border-radius:8px;font-size:0.82rem;font-weight:600;cursor:pointer;">
                    + Agregar representante
                </button>
            @endcan
        </div>
    </div>

    @include('brynex.razones_sociales._alertas')

    {{-- El correo del RUT personal es a donde la DIAN manda el enlace de
         acceso del representante legal; sin él no se puede automatizar la
         habilitación de facturación electrónica. --}}
    @if($sinCorreo)
        <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:0.7rem 0.9rem;border-radius:10px;font-size:0.8rem;margin-bottom:1rem;">
            📧 <strong>{{ $sinCorreo }}</strong> {{ $sinCorreo === 1 ? 'representante no tiene' : 'representantes no tienen' }} el correo de su RUT personal.
            Ahí llega el enlace de la DIAN para entrar como representante legal (facturación electrónica).
        </div>
    @endif

    {{-- ── Tarjetas ────────────────────────────────────────────────── --}}
    <div style="display:grid;gap:1rem;">
        @forelse($representantes as $r)
            @php
                $repJs = [
                    'cedula' => $r->cedula,
                    'nombre' => $r->nombre,
                    'tipo_documento' => $r->tipo_documento,
                    'persona' => $r->nombre,
                    'correo_rut' => $r->correo_rut,
                ];
                $textoBusqueda = mb_strtolower($r->nombre . ' ' . $r->cedula . ' ' . $r->correo_rut . ' ' . $r->empresas->pluck('razon_social')->implode(' ') . ' ' . $r->empresas->pluck('nit')->implode(' '));
            @endphp
            <div id="{{ $Svc::ancla($r->cedula) }}" class="rep-card"
                 x-show="coincide({{ Js::from($textoBusqueda) }})">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:1.05rem;font-weight:800;color:#0d2550;">
                            {{ $r->nombre }}
                            @if($r->nombres_distintos)
                                <span class="rep-tag alerta" title="Los aliados escribieron el nombre de forma distinta. Revísalo y guárdalo bien una vez.">nombre distinto entre aliados</span>
                            @endif
                            @if(! $r->registro)
                                <span class="rep-tag" title="Todavía no se le ha guardado ningún dato; lo que se ve viene de las razones sociales de los aliados.">sin datos propios</span>
                            @endif
                        </div>
                        <div style="color:#64748b;font-size:0.82rem;margin-top:0.2rem;">
                            {{ $r->tipo_documento }} {{ $r->cedula }}
                            @if($r->telefono) · 📱 {{ $r->telefono }} @endif
                        </div>
                        <div style="font-size:0.82rem;margin-top:0.3rem;">
                            @if($r->correo_rut)
                                📧 <span style="color:#0f172a;font-weight:600;">{{ $r->correo_rut }}</span>
                                <span style="color:#94a3b8;">· correo del RUT personal</span>
                            @else
                                <span style="color:#b45309;">📧 Sin correo del RUT personal</span>
                            @endif
                        </div>
                        @if($r->notas)
                            <div style="color:#475569;font-size:0.78rem;margin-top:0.3rem;white-space:pre-line;">{{ $r->notas }}</div>
                        @endif
                    </div>

                    <div style="display:flex;gap:0.4rem;">
                        @can('brynex_razones.gestionar')
                            <button type="button" class="rep-btn"
                                    @click="datos = Object.assign({ telefono: '', notas: '', nuevo: false }, {{ Js::from([
                                        'cedula' => $r->cedula,
                                        'tipo_documento' => $r->tipo_documento,
                                        'nombre' => $r->nombre,
                                        'correo_rut' => $r->correo_rut,
                                        'telefono' => $r->telefono,
                                        'notas' => $r->notas,
                                    ]) }}); verDatos = true">✏️ Datos</button>
                        @endcan
                        @can('brynex_razones.claves')
                            @if($r->registro)
                                <button type="button" class="rep-btn" @click="probarBuzon({{ $r->registro->id }})"
                                        title="Entra al correo (solo lectura) y busca los correos de la DIAN del último mes">📬 Probar buzón</button>
                            @endif
                            <button type="button" class="rep-btn verde" @click="nuevaClave({{ Js::from($repJs) }})">+ Clave</button>
                        @endcan
                    </div>
                </div>

                @if($r->registro)
                    @php $rid = $r->registro->id; @endphp
                    <template x-if="prueba[{{ $rid }}]">
                        <div class="rep-prueba" :class="prueba[{{ $rid }}].ok === false ? 'error' : ''">
                            <template x-if="prueba[{{ $rid }}].cargando"><span>📬 Entrando al buzón…</span></template>
                            <template x-if="prueba[{{ $rid }}].ok === false"><span x-text="'⚠️ ' + prueba[{{ $rid }}].error"></span></template>
                            <template x-if="prueba[{{ $rid }}].ok">
                                <div>
                                    <div>
                                        ✅ Conectó con <b x-text="prueba[{{ $rid }}].cuenta"></b>
                                        <span x-text="prueba[{{ $rid }}].via === 'reenvios' ? '(buzón central de reenvíos)' : ''"></span>
                                        · <span x-text="prueba[{{ $rid }}].correos.length"></span> correo(s) de la DIAN en el último mes.
                                    </div>
                                    <template x-for="c in prueba[{{ $rid }}].correos.slice(0, 5)">
                                        <div style="color:#475569;margin-top:0.2rem;">
                                            <span x-text="c.fecha"></span> · <span x-text="c.asunto"></span>
                                            <span x-show="c.con_enlace" x-text="c.vigente ? ' · 🔗 enlace vigente' : ' · enlace vencido'"></span>
                                            <span x-show="! c.es_suyo" style="color:#94a3b8;"> · de otra persona</span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                @endif

                {{-- Empresas --}}
                <div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.8rem;">
                    @forelse($r->empresas as $e)
                        <a href="{{ route('brynex.razones.show', $e->ficha_id) }}" class="rep-empresa {{ $e->en_conflicto ? 'alerta' : '' }}"
                           title="NIT {{ $e->nit }}{{ $e->en_conflicto ? ' · Los aliados tienen anotados representantes distintos para esta empresa' : '' }}">
                            🏢 {{ $e->razon_social }}
                            @if($e->regimen === 'RST')<span style="color:#64748b;font-weight:500;">· RST</span>@endif
                        </a>
                        @if($e->manual)
                            @can('brynex_razones.gestionar')
                                <form method="POST" action="{{ route('brynex.razones.representantes.asignar') }}" style="display:inline;margin-left:-0.35rem;"
                                      onsubmit="return confirm('¿Quitar el representante puesto a mano? La empresa vuelve a tomar el que tengan anotado los aliados.')">
                                    @csrf
                                    <input type="hidden" name="ficha_id" value="{{ $e->ficha_id }}">
                                    <button type="submit" title="Puesto a mano. Quitar" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:0.72rem;">✋✕</button>
                                </form>
                            @endcan
                        @endif
                    @empty
                        <span style="color:#94a3b8;font-size:0.78rem;">Hoy no figura como representante de ninguna empresa en seguimiento.</span>
                    @endforelse
                </div>

                {{-- Claves --}}
                @if($r->credenciales->isNotEmpty())
                    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;margin-top:0.9rem;">
                        <thead>
                            <tr style="color:#64748b;font-size:0.68rem;text-transform:uppercase;text-align:left;">
                                <th style="padding:0.4rem 0.5rem;font-weight:700;">Portal</th>
                                <th style="padding:0.4rem 0.5rem;font-weight:700;">Usuario</th>
                                <th style="padding:0.4rem 0.5rem;font-weight:700;">Contraseña</th>
                                <th style="padding:0.4rem 0.5rem;font-weight:700;">Observación</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($r->credenciales as $c)
                            <tr style="border-top:1px solid #f1f5f9;">
                                <td style="padding:0.45rem 0.5rem;">
                                    <div style="font-weight:600;color:#0f172a;">{{ $c->tipoEtiqueta() }}</div>
                                    <div style="color:#64748b;font-size:0.75rem;">
                                        {{ $c->entidad }}
                                        @if($c->link_acceso)
                                            · <a href="{{ $c->link_acceso }}" target="_blank" rel="noopener noreferrer" style="color:#1e3a8a;">abrir ↗</a>
                                        @endif
                                    </div>
                                </td>
                                <td style="padding:0.45rem 0.5rem;font-family:ui-monospace,monospace;font-size:0.78rem;color:#334155;">{{ $c->usuario ?: '—' }}</td>
                                <td style="padding:0.45rem 0.5rem;">
                                    @can('brynex_razones.claves')
                                        <template x-if="revelada[{{ $c->id }}]">
                                            <span style="font-family:ui-monospace,monospace;font-size:0.8rem;background:#fef3c7;padding:0.15rem 0.4rem;border-radius:4px;" x-text="revelada[{{ $c->id }}]"></span>
                                        </template>
                                        <template x-if="! revelada[{{ $c->id }}]">
                                            <button type="button" @click="revelar({{ $c->id }})" class="rep-btn chico">👁 Ver</button>
                                        </template>
                                    @else
                                        <span style="color:#94a3b8;">••••••</span>
                                    @endcan
                                </td>
                                <td style="padding:0.45rem 0.5rem;color:#64748b;font-size:0.75rem;">{{ Str::limit($c->observacion, 60) ?: '—' }}</td>
                                <td style="padding:0.45rem 0.5rem;text-align:right;white-space:nowrap;">
                                    @can('brynex_razones.claves')
                                        <button type="button"
                                                @click="clave = Object.assign({{ Js::from($repJs) }}, {{ Js::from([
                                                    'credencial_id' => $c->id,
                                                    'tipo' => $c->tipo,
                                                    'entidad' => $c->entidad,
                                                    'usuario' => $c->usuario,
                                                    'link_acceso' => $c->link_acceso,
                                                    'observacion' => $c->observacion,
                                                ]) }}); verClave = true"
                                                style="background:none;border:none;color:#1e3a8a;cursor:pointer;font-size:0.76rem;font-weight:600;">Editar</button>
                                        <form method="POST" action="{{ route('brynex.razones.representantes.claves.destroy', $c->id) }}"
                                              onsubmit="return confirm('¿Eliminar esta clave?')" style="display:inline;">
                                            @csrf @method('DELETE')
                                            <button type="submit" style="background:none;border:none;color:#cbd5e1;cursor:pointer;font-size:0.76rem;">✕</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <div style="color:#94a3b8;font-size:0.78rem;margin-top:0.8rem;">Sin claves guardadas.</div>
                @endif
            </div>
        @empty
            <div class="rep-card" style="text-align:center;color:#94a3b8;">
                Ninguna razón social en seguimiento tiene anotada la cédula de su representante.
            </div>
        @endforelse
    </div>

    {{-- ── Empresas sin representante ─────────────────────────────── --}}
    @if($sin_representante->isNotEmpty())
        <div style="margin-top:1.5rem;">
            <h3 style="font-size:0.9rem;font-weight:800;color:#334155;margin:0 0 0.4rem 0;">Empresas sin representante anotado</h3>
            <p style="color:#94a3b8;font-size:0.75rem;margin:0 0 0.6rem 0;">
                Ningún aliado tiene la cédula del representante legal en estas razones sociales (o la empresa no está en ningún aliado).
                Asígnalo aquí; queda puesto a mano (✋) y manda sobre lo que anoten después los aliados.
            </p>
            <div style="display:grid;gap:0.45rem;max-width:760px;">
                @foreach($sin_representante as $e)
                    <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
                        <a href="{{ route('brynex.razones.show', $e->ficha_id) }}" class="rep-empresa">🏢 {{ $e->razon_social }}</a>
                        @can('brynex_razones.gestionar')
                            <form method="POST" action="{{ route('brynex.razones.representantes.asignar') }}" style="display:flex;gap:0.35rem;">
                                @csrf
                                <input type="hidden" name="ficha_id" value="{{ $e->ficha_id }}">
                                <select name="cedula" required class="rep-input" style="margin-top:0;width:auto;padding:0.3rem 0.4rem;font-size:0.78rem;">
                                    <option value="">Asignar a…</option>
                                    @foreach($representantes as $r)
                                        <option value="{{ $r->cedula }}">{{ $r->nombre }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="rep-btn chico">Asignar</button>
                            </form>
                        @endcan
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Modal: datos del representante ─────────────────────────── --}}
    <div x-show="verDatos" x-cloak class="rep-modal" @click.self="verDatos = false">
        <div class="rep-modal-caja">
            <h2 style="font-size:1.05rem;font-weight:800;color:#0d2550;margin:0 0 1rem 0;"
                x-text="datos.nuevo ? 'Agregar representante' : 'Datos del representante'"></h2>

            <form method="POST" action="{{ route('brynex.razones.representantes.guardar') }}">
                @csrf
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
                    <label class="rep-label">
                        Tipo de documento *
                        <select name="tipo_documento" x-model="datos.tipo_documento" required class="rep-input">
                            @foreach($Rep::TIPOS_DOCUMENTO as $k => $v)
                                <option value="{{ $k }}">{{ $k }} · {{ $v }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="rep-label">
                        Número *
                        {{-- La cédula es la llave: no se cambia al editar, se
                             agrega otro representante si estaba mal. --}}
                        <input type="text" name="cedula" x-model="datos.cedula" required :readonly="! datos.nuevo"
                               :style="! datos.nuevo ? 'background:#f1f5f9;' : ''" class="rep-input">
                    </label>
                    <label class="rep-label" style="grid-column:span 2;">
                        Nombre completo *
                        <input type="text" name="nombre" x-model="datos.nombre" required class="rep-input">
                    </label>
                    <label class="rep-label">
                        Correo del RUT personal
                        <input type="email" name="correo_rut" x-model="datos.correo_rut" placeholder="ella@gmail.com" class="rep-input">
                    </label>
                    <label class="rep-label">
                        Teléfono
                        <input type="text" name="telefono" x-model="datos.telefono" class="rep-input">
                    </label>
                    <label class="rep-label" style="grid-column:span 2;">
                        Notas
                        <textarea name="notas" x-model="datos.notas" rows="2" class="rep-input"></textarea>
                    </label>
                </div>
                <p style="color:#94a3b8;font-size:0.72rem;margin:0.7rem 0 0 0;">
                    El correo es el que figura en el RUT de la persona natural, no el de la empresa: ahí la DIAN manda el enlace de
                    acceso del representante legal.
                </p>
                <div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-top:1.1rem;">
                    <button type="button" @click="verDatos = false" class="rep-btn">Cancelar</button>
                    <button type="submit" class="rep-btn azul">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Modal: clave ───────────────────────────────────────────── --}}
    <div x-show="verClave" x-cloak class="rep-modal" @click.self="verClave = false">
        <div class="rep-modal-caja">
            <h2 style="font-size:1.05rem;font-weight:800;color:#0d2550;margin:0 0 0.2rem 0;">Clave personal</h2>
            <div style="color:#64748b;font-size:0.8rem;margin-bottom:1rem;" x-text="clave.persona"></div>

            <form method="POST" action="{{ route('brynex.razones.representantes.claves.guardar') }}">
                @csrf
                <input type="hidden" name="cedula" :value="clave.cedula">
                <input type="hidden" name="nombre" :value="clave.nombre">
                <input type="hidden" name="tipo_documento" :value="clave.tipo_documento">
                <input type="hidden" name="credencial_id" :value="clave.credencial_id">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
                    <label class="rep-label">
                        Tipo *
                        <select name="tipo" x-model="clave.tipo" required class="rep-input"
                                @change="if (! clave.credencial_id) clave.entidad = sugeridas[clave.tipo] ?? ''">
                            @foreach($Cred::TIPOS as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="rep-label">
                        Portal / entidad *
                        <input type="text" name="entidad" x-model="clave.entidad" required class="rep-input">
                    </label>
                    <label class="rep-label">
                        Usuario
                        <input type="text" name="usuario" x-model="clave.usuario" autocomplete="off" class="rep-input">
                    </label>
                    <label class="rep-label">
                        Contraseña
                        <input type="password" name="contrasena" autocomplete="new-password" placeholder="Dejar vacío = no cambiar" class="rep-input">
                    </label>
                    <div x-show="clave.tipo === 'CORREO'" style="grid-column:span 2;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:8px;padding:0.55rem 0.7rem;font-size:0.74rem;font-weight:400;">
                        Para que BryNex lea solo los correos de la DIAN: en Usuario va el <b>Gmail</b> y en Contraseña una
                        <b>contraseña de aplicación</b> (16 letras), no la clave normal. Se crea en
                        <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer" style="color:#1e3a8a;">myaccount.google.com/apppasswords</a>
                        con la verificación en dos pasos activa. Sin esa clave, BryNex lee el buzón central
                        <b>{{ config('representantes.buzon_reenvios') }}</b>, adonde deben llegar (o reenviarse) los correos de la DIAN.
                    </div>
                    <label class="rep-label" style="grid-column:span 2;">
                        Link de acceso
                        <input type="url" name="link_acceso" x-model="clave.link_acceso" placeholder="https://…" class="rep-input">
                    </label>
                    <label class="rep-label" style="grid-column:span 2;">
                        Observación
                        <input type="text" name="observacion" x-model="clave.observacion" class="rep-input">
                    </label>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-top:1.1rem;">
                    <button type="button" @click="verClave = false" class="rep-btn">Cancelar</button>
                    <button type="submit" class="rep-btn verde">Guardar clave</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.rep-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.1rem 1.2rem; scroll-margin-top:90px; }
.rep-card:target { border-color:#93c5fd; box-shadow:0 0 0 3px #dbeafe; }
.rep-tag { display:inline-block; vertical-align:middle; background:#f1f5f9; color:#64748b; font-size:0.66rem; font-weight:700; padding:0.12rem 0.5rem; border-radius:20px; margin-left:0.3rem; }
.rep-tag.alerta { background:#fef2f2; color:#b91c1c; }
.rep-empresa { display:inline-flex; gap:0.3rem; background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; font-size:0.78rem; font-weight:600; padding:0.28rem 0.7rem; border-radius:20px; text-decoration:none; }
.rep-empresa:hover { border-color:#93c5fd; }
.rep-empresa.alerta { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.rep-btn { background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; padding:0.4rem 0.8rem; border-radius:8px; font-size:0.8rem; font-weight:600; cursor:pointer; }
.rep-btn.chico { padding:0.18rem 0.55rem; font-size:0.74rem; }
.rep-btn.verde { background:#047857; color:#fff; border-color:#047857; }
.rep-btn.azul { background:#1e3a8a; color:#fff; border-color:#1e3a8a; }
.rep-prueba { margin-top:0.7rem; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:8px; padding:0.55rem 0.75rem; font-size:0.78rem; }
.rep-prueba.error { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
.rep-modal { position:fixed; inset:0; background:rgba(15,23,42,0.55); display:flex; align-items:center; justify-content:center; z-index:60; padding:1rem; }
.rep-modal-caja { background:#fff; border-radius:16px; max-width:540px; width:100%; padding:1.5rem; max-height:92vh; overflow:auto; }
.rep-label { font-size:0.78rem; font-weight:700; color:#334155; }
.rep-input { width:100%; margin-top:0.25rem; padding:0.5rem; border:1px solid #cbd5e1; border-radius:8px; font-weight:400; font-size:0.85rem; box-sizing:border-box; }
@media (max-width: 640px) {
    .rep-modal-caja form > div { grid-template-columns:1fr !important; }
    .rep-modal-caja label { grid-column:auto !important; }
}
</style>
@endsection
