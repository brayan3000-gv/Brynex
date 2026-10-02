@extends('layouts.app')
@section('modulo', 'Claves y Accesos')
@section('titulo', 'Buscador Global de Claves')

@push('styles')
<style>
/* ── Estilos Premium del Buscador Global de Claves ── */
.claves-container {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    padding: 1.5rem;
    margin-bottom: 2rem;
    transition: all 0.3s;
}

.claves-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8f 100%);
    padding: 1.25rem 1.5rem;
    border-radius: 12px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.claves-title h1 {
    font-size: 1.4rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

.claves-title p {
    font-size: 0.78rem;
    color: #94a3b8;
    margin-top: 0.2rem;
}

.btn-nueva-global {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: #1c1917;
    border: none;
    border-radius: 10px;
    padding: 0.55rem 1.25rem;
    font-size: 0.82rem;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(245,158,11,0.3);
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.btn-nueva-global:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(245,158,11,0.4);
}

/* Filtros */
.claves-filtros {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.75rem;
    align-items: end;
}

.filtro-grp {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.filtro-grp label {
    font-size: 0.68rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.filtro-inp {
    padding: 0.5rem 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.82rem;
    outline: none;
    background: #fff;
    color: #0f172a;
    transition: all 0.15s;
    width: 100%;
}

.filtro-inp:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.btn-filtrar-global {
    background: #1e40af;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-filtrar-global:hover {
    background: #1d4ed8;
}

.btn-limpiar-global {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.5rem 1.25rem;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
    transition: all 0.15s;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-limpiar-global:hover {
    background: #e2e8f0;
}

/* Tabla */
.tbl-wrap-global {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: #fff;
}

.tbl-global {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    white-space: nowrap;
}

.tbl-global thead th {
    background: #0f172a;
    color: #fff;
    padding: 0.75rem 0.85rem;
    font-weight: 700;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    text-align: left;
}

.tbl-global tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s;
}

.tbl-global tbody tr:hover {
    background: #f8fafc;
}

.tbl-global td {
    padding: 0.65rem 0.85rem;
    vertical-align: middle;
}

/* Badges Vinculo */
.badge-vinculo {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.badge-vinculo.rs { background: #dbeafe; color: #1e40af; }
.badge-vinculo.cli { background: #fef3c7; color: #b45309; }
.badge-vinculo.emp { background: #e0f2fe; color: #0369a1; }

.ca-th { padding:0.5rem 0.65rem;font-size:0.72rem;font-weight:700;color:#92400e;white-space:nowrap;text-align:left; }
.ca-td { padding:0.38rem 0.65rem;color:#1c1917;vertical-align:middle; }
.ca-lbl { display:block;font-size:0.7rem;font-weight:700;color:#475569;margin-bottom:0.18rem;text-transform:uppercase;letter-spacing:0.02em; }
.ca-inp { width:100%;padding:0.38rem 0.5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;color:#0f172a;box-sizing:border-box; }
.ca-inp:focus { outline:none;border-color:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,0.2); }
</style>
@endpush

@section('contenido')
<div class="claves-container" style="{{ request()->has('iframe') ? 'border:none;box-shadow:none;padding:0.25rem;' : '' }}">

    {{-- Header (Solo si no es iframe) --}}
    @unless(request()->has('iframe'))
    <div class="claves-header">
        <div class="claves-title">
            <h1>🔑 Buscador Global de Claves y Accesos</h1>
            <p>Visualiza y administra de forma centralizada todas las credenciales registradas del aliado</p>
        </div>
        <button onclick="abrirModalClaveGlobal()" class="btn-nueva-global">
            ➕ Registrar Clave
        </button>
    </div>
    @endunless

    {{-- Filtros --}}
    <form method="GET" action="{{ route('admin.clave_accesos.global') }}">
        @if(request()->has('iframe'))
            <input type="hidden" name="iframe" value="1">
        @endif
        <div class="claves-filtros">
            {{-- Razón Social --}}
            <div class="filtro-grp">
                <label>Razón Social</label>
                <select name="razon_social_id" class="filtro-inp">
                    <option value="">— Todas —</option>
                    @foreach($razones as $r)
                        <option value="{{ $r->id }}" {{ request('razon_social_id') == $r->id ? 'selected' : '' }}>
                            {{ $r->razon_social }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Tipo --}}
            <div class="filtro-grp">
                <label>Tipo</label>
                <select name="tipo" class="filtro-inp">
                    <option value="">— Todos —</option>
                    <option value="Portal" {{ request('tipo') === 'Portal' ? 'selected' : '' }}>Portal Web</option>
                    <option value="Correo" {{ request('tipo') === 'Correo' ? 'selected' : '' }}>Correo Electrónico</option>
                    <option value="EPS" {{ request('tipo') === 'EPS' ? 'selected' : '' }}>EPS</option>
                    <option value="ARL" {{ request('tipo') === 'ARL' ? 'selected' : '' }}>ARL</option>
                    <option value="AFP" {{ request('tipo') === 'AFP' ? 'selected' : '' }}>AFP / Pensión</option>
                    <option value="CAJA" {{ request('tipo') === 'CAJA' ? 'selected' : '' }}>Caja de Compensación</option>
                    <option value="DIAN" {{ request('tipo') === 'DIAN' ? 'selected' : '' }}>DIAN</option>
                    <option value="MinTrabajo" {{ request('tipo') === 'MinTrabajo' ? 'selected' : '' }}>Min. Trabajo (PILA)</option>
                    <option value="Banco" {{ request('tipo') === 'Banco' ? 'selected' : '' }}>Banco / Entidad Financiera</option>
                    <option value="Operadores" {{ request('tipo') === 'Operadores' ? 'selected' : '' }}>Operadores</option>
                    <option value="Otro" {{ request('tipo') === 'Otro' ? 'selected' : '' }}>Otro</option>
                </select>
            </div>

            {{-- Entidad --}}
            <div class="filtro-grp">
                <label>Entidad / Portal</label>
                <input type="text" name="entidad" value="{{ request('entidad') }}" class="filtro-inp" placeholder="Ej: Arl Sura, Compensar...">
            </div>

            {{-- Búsqueda General --}}
            <div class="filtro-grp">
                <label>Búsqueda general</label>
                <input type="text" name="buscar" value="{{ request('buscar') }}" class="filtro-inp" placeholder="Usuario, observación, correo...">
            </div>

            {{-- Botones --}}
            <div style="display:flex;gap:0.4rem;grid-column: span 1;align-items:end;">
                <button type="submit" class="btn-filtrar-global" style="flex:1;" title="Filtrar">🔍</button>
                <a href="{{ route('admin.clave_accesos.global', request()->has('iframe') ? ['iframe' => 1] : []) }}" class="btn-limpiar-global" style="flex:1;">🔄 Limpiar</a>
            </div>
            @if(request()->has('iframe'))
            <div style="grid-column: span 1; display:flex; justify-content: flex-end; align-items:end;">
                <button type="button" onclick="abrirModalClaveGlobal()" class="btn-nueva-global" style="height:38px; width:100%; justify-content:center;">
                    ➕ Nueva Clave
                </button>
            </div>
            @endif
        </div>
    </form>

    {{-- Notificación inline --}}
    <div id="glb-claves-notif" style="display:none;margin-bottom:1rem;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;"></div>

    {{-- Tabla --}}
    <div class="tbl-wrap-global">
        <table class="tbl-global">
            <thead>
                <tr style="background:#fef9c3;border-bottom:2px solid #fde68a;">
                    <th class="ca-th">Tipo</th>
                    <th class="ca-th">Entidad / Portal</th>
                    <th class="ca-th">Usuario</th>
                    <th class="ca-th">Contraseña</th>
                    <th class="ca-th" style="text-align:center;">Link</th>
                    <th class="ca-th">Correo</th>
                    <th class="ca-th">Vinculado A</th>
                    <th class="ca-th">Observación</th>
                    <th class="ca-th" style="text-align:center;">Estado</th>
                    <th class="ca-th" style="text-align:center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @if($claves->isEmpty())
                    <tr>
                        <td colspan="10" style="text-align:center;padding:3rem;color:#94a3b8;">
                            <div style="font-size:2.5rem;margin-bottom:0.5rem;">🔑</div>
                            <strong>No se encontraron claves o accesos con los filtros actuales.</strong>
                        </td>
                    </tr>
                @else
                    @php
                        // La clave es del usuario que entra al portal, no de la empresa:
                        // la misma persona administra varias razones sociales con el
                        // mismo login. Se muestran agrupadas para que se vea de una que
                        // la clave es una sola y se edite en un único sitio.
                        //
                        // En Sura el grupo cruza ARL y EPS: es el mismo usuario para
                        // ambos, y el NIT lo pregunta después de entrar.
                        $sinc = \App\Services\ClavePortalSincronizador::class;

                        $gruposSura = $claves
                            ->filter(fn ($x) => $sinc::grupoDe($x->tipo, $x->entidad, $x->usuario) !== null)
                            ->groupBy(fn ($x) => $sinc::grupoDe($x->tipo, $x->entidad, $x->usuario))
                            ->filter(fn ($g) => $g->count() > 1);

                        $idsAgrupados = $gruposSura->flatten()->pluck('id')->all();
                    @endphp

                    @foreach($gruposSura as $claveGrupo => $grupo)
                    @php
                        // La que representa al grupo tiene que ser una que TENGA clave:
                        // con la primera a secas, si esa fila venía vacía la pantalla
                        // mostraba "—" y no había forma de ver ninguna de las del grupo.
                        $primera     = $grupo->first(fn ($x) => trim((string) $x->contrasena) !== '') ?: $grupo->first();
                        $usuarioSura = trim((string) $primera->usuario);
                        $esSura      = $sinc::esSura($primera->entidad);
                        $tipos       = $grupo->pluck('tipo')->map(fn ($t) => strtoupper(trim((string) $t)))->unique();
                        $colG        = $colores[$primera->tipo] ?? ['#f1f5f9','#475569'];
                        $sinPermG  = $primera->contrasena === '__oculta__';
                        $maskedG   = $primera->contrasena && ! $sinPermG
                            ? str_repeat('•', min(strlen($primera->contrasena), 8)) . ' 👁'
                            : '—';
                        // Que a una fila le falte la clave no es lo mismo que tener dos
                        // claves distintas, y avisar de un conflicto que no existe hace
                        // dudar de una clave que está bien.
                        $conClaveG  = $grupo->map(fn ($x) => trim((string) $x->contrasena))->filter();
                        $distintas  = $conClaveG->unique()->count() > 1;
                        $sinClaveG  = $grupo->filter(fn ($x) => trim((string) $x->contrasena) === '')->count();
                    @endphp
                    <tr style="border-bottom:1px solid #fde68a;background:#fffbeb;">
                        <td>
                            @foreach($tipos as $t)
                                @php $ct = $colores[ucfirst(strtolower($t))] ?? ($colores[$t] ?? ['#f1f5f9','#475569']); @endphp
                                <span style="background:{{ $ct[0] }};color:{{ $ct[1] }};padding:0.15rem 0.5rem;border-radius:999px;font-size:0.68rem;font-weight:700;">{{ $t }}</span>
                            @endforeach
                        </td>
                        <td style="font-weight:700;">{{ $sinc::nombreGrupo($primera->tipo, $primera->entidad) }}
                            @if($grupo->contains(fn ($g) => $g->de_otro_aliado ?? false))
                            <span style="background:#e0e7ff;color:#3730a3;padding:0.1rem 0.4rem;border-radius:999px;font-size:0.62rem;font-weight:700;"
                                  title="Hay claves de este grupo cargadas por otro aliado que comparte la empresa">↔ compartida</span>
                            @endif
                        </td>
                        <td style="font-family:monospace;font-size:0.77rem;font-weight:700;">{{ $usuarioSura }}</td>
                        <td>
                            @if($sinPermG)
                                <span style="color:#94a3b8;font-size:0.77rem;">🔒 guardada</span>
                            @elseif($primera->contrasena)
                                <span style="font-family:monospace;font-size:0.77rem;cursor:pointer;"
                                      onclick="verPassGlobal(this, {{ $primera->id }}, '{{ base64_encode($primera->contrasena) }}')"
                                      title="Click para revelar">{{ $maskedG }}</span>
                            @else
                                <span style="color:#cbd5e1;">—</span>
                            @endif
                        </td>
                        <td colspan="4" style="font-size:0.73rem;color:#92400e;">
                            <button type="button" onclick="alternarGrupoSura('{{ md5($claveGrupo) }}', this)"
                                    style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:0.2rem 0.6rem;
                                           font-size:0.72rem;font-weight:700;color:#92400e;cursor:pointer;">
                                ▸ {{ $grupo->count() }} razones sociales
                            </button>
                            @if($distintas)
                                <span style="margin-left:.5rem;color:#b91c1c;font-weight:700;">⚠️ tienen claves distintas — al guardar aquí se unifican</span>
                            @elseif($sinClaveG)
                                <span style="margin-left:.5rem;color:#b45309;font-weight:700;">
                                    {{ $sinClaveG }} sin clave — al guardar aquí se les pone esta
                                </span>
                            @else
                                <span style="margin-left:.5rem;">Una sola clave{{ $esSura ? ' para ARL y EPS' : '' }}: al cambiarla aquí se cambia en todas.</span>
                            @endif
                        </td>
                        <td style="text-align:center;">
                            <span style="background:#dcfce7;color:#16a34a;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">ACTIVO</span>
                        </td>
                        <td style="text-align:center;white-space:nowrap;">
                            <button onclick="abrirModalClaveGlobal({{ json_encode($primera) }})"
                                    style="background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#92400e;"
                                    title="Editar la clave de este usuario (se aplica a sus {{ $grupo->count() }} razones sociales)">✏️</button>
                        </td>
                    </tr>

                    {{-- Las razones sociales de ese usuario, ocultas hasta que se despliegan --}}
                    @foreach($grupo as $hija)
                    <tr class="grupo-sura-{{ preg_replace('/\D/', '', $usuarioSura) }}" style="display:none;background:#fefce8;border-bottom:1px solid #fef3c7;">
                        <td></td>
                        <td colspan="2" style="font-size:0.75rem;color:#78350f;padding-left:1.2rem;">
                            ↳ {{ $hija->razonSocial->razon_social ?? ($hija->empresa->empresa ?? 'sin vínculo') }}
                            @if($hija->de_otro_aliado ?? false)
                            <span style="background:#e0e7ff;color:#3730a3;padding:0.1rem 0.4rem;border-radius:999px;font-size:0.62rem;font-weight:700;"
                                  title="La cargó {{ $hija->cargada_por }}">↔ {{ $hija->cargada_por }}</span>
                            @endif
                            @if($tipos->count() > 1)
                                <span style="color:#92400e;font-weight:700;"> · {{ strtoupper($hija->tipo) }}</span>
                            @endif
                        </td>
                        <td style="font-family:monospace;font-size:0.74rem;color:#78350f;">
                            @if($sinPermG)
                                🔒
                            @elseif(trim((string) $hija->contrasena) === trim((string) $primera->contrasena))
                                misma clave
                            @elseif(trim((string) $hija->contrasena) !== '')
                                {{-- Distinta: hay que poder verla, que es justo la que se
                                     va a perder si alguien unifica desde la fila de arriba. --}}
                                <span style="cursor:pointer;color:#b91c1c;font-weight:700;"
                                      onclick="verPassGlobal(this, {{ $hija->id }}, '{{ base64_encode($hija->contrasena) }}')"
                                      title="Distinta a la del grupo. Click para revelar">⚠️ {{ str_repeat('•', min(strlen($hija->contrasena), 8)) }} 👁</span>
                            @else
                                <span style="color:#cbd5e1;">sin clave</span>
                            @endif
                        </td>
                        <td style="text-align:center;">
                            @if($hija->link_acceso)
                            <a href="{{ $hija->link_acceso }}" target="_blank"
                               style="background:#eff6ff;color:#2563eb;padding:0.18rem 0.5rem;border-radius:5px;font-size:0.7rem;font-weight:600;border:1px solid #bfdbfe;text-decoration:none;">🔗 Abrir</a>
                            @endif
                        </td>
                        <td style="font-size:0.73rem;color:#78350f;">{{ $hija->correo_entidad ?? '' }}</td>
                        <td colspan="2" style="font-size:0.72rem;color:#92400e;">{{ $hija->observacion ?? '' }}</td>
                        <td style="text-align:center;">
                            @if($hija->activo)
                                <span style="background:#dcfce7;color:#16a34a;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">ACTIVO</span>
                            @else
                                <span style="background:#fee2e2;color:#dc2626;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">INACTIVO</span>
                            @endif
                        </td>
                        <td style="text-align:center;white-space:nowrap;">
                            <button onclick="abrirModalClaveGlobal({{ json_encode($hija) }})" style="background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#92400e;" title="Editar">✏️</button>
                            <button onclick="eliminarClaveGlobal({{ $hija->id }})" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#dc2626;" title="Eliminar">🗑</button>
                        </td>
                    </tr>
                    @endforeach
                    @endforeach

                    @foreach($claves as $c)
                    @continue(in_array($c->id, $idsAgrupados))
                    @php
                        $colores = [
                            'Portal' => ['#eff6ff','#1d4ed8'], 'Correo' => ['#fef3c7','#92400e'],
                            'EPS' => ['#dcfce7','#15803d'], 'ARL' => ['#fce7f3','#9d174d'],
                            'AFP' => ['#e0e7ff','#3730a3'], 'CAJA' => ['#fff7ed','#c2410c'],
                            'DIAN' => ['#fef9c3','#713f12'], 'MinTrabajo' => ['#f0fdf4','#166534'],
                            'Banco' => ['#f5f3ff','#6d28d9'], 'Operadores' => ['#f3e8ff','#7e22ce'], 'Otro' => ['#f1f5f9','#475569']
                        ];
                        $col = $colores[$c->tipo] ?? ['#f1f5f9','#475569'];
                        // '__oculta__' lo pone el controlador cuando el usuario
                        // no tiene el permiso restringido claves_acceso.ver_contrasena:
                        // sí sabe que hay clave guardada, pero no la recibe.
                        $sinPermiso = $c->contrasena === '__oculta__';
                        $masked = $c->contrasena && ! $sinPermiso
                            ? str_repeat('•', min(strlen($c->contrasena), 8)) . ' 👁'
                            : '—';
                    @endphp
                    <tr style="border-bottom:1px solid #fef3c7;">
                        {{-- Tipo --}}
                        <td>
                            <span style="background:{{ $col[0] }};color:{{ $col[1] }};padding:0.15rem 0.5rem;border-radius:999px;font-size:0.68rem;font-weight:700;">
                                {{ $c->tipo }}
                            </span>
                        </td>
                        {{-- Entidad --}}
                        <td style="font-weight:600;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $c->entidad }}">
                            {{ $c->entidad }}
                        </td>
                        {{-- Usuario --}}
                        <td style="font-family:monospace;font-size:0.77rem;">
                            {{ $c->usuario ?? '—' }}
                        </td>
                        {{-- Contraseña --}}
                        <td>
                            @if($sinPermiso)
                            <span style="color:#94a3b8;font-size:0.77rem;" title="Necesitas el permiso «Ver la contraseña en claro»">
                                🔒 guardada
                            </span>
                            @elseif($c->contrasena)
                            <span style="font-family:monospace;font-size:0.77rem;cursor:pointer;"
                                  onclick="verPassGlobal(this, {{ $c->id }}, '{{ base64_encode($c->contrasena) }}')"
                                  title="Click para revelar">
                                {{ $masked }}
                            </span>
                            @else
                            <span style="color:#cbd5e1;">—</span>
                            @endif
                        </td>
                        {{-- Link --}}
                        <td style="text-align:center;">
                            @if($c->link_acceso)
                            <a href="{{ $c->link_acceso }}" target="_blank"
                               style="background:#eff6ff;color:#2563eb;padding:0.18rem 0.5rem;border-radius:5px;font-size:0.7rem;font-weight:600;border:1px solid #bfdbfe;text-decoration:none;">🔗 Abrir</a>
                            @else
                            <span style="color:#cbd5e1;">—</span>
                            @endif
                        </td>
                        {{-- Correo --}}
                        <td style="font-size:0.75rem;max-width:135px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $c->correo_entidad }}">
                            {{ $c->correo_entidad ?? '—' }}
                        </td>
                        {{-- Vinculado A --}}
                        <td>
                            @if($c->razonSocial)
                                <span class="badge-vinculo rs" title="{{ $c->razonSocial->razon_social }}">🏢 {{ $c->razonSocial->razon_social }}</span>
                                {{-- La clave es de la empresa: si la cargó otro aliado que
                                     comparte esa razón social, se ve igual y se puede corregir,
                                     pero conviene saber a quién avisar. --}}
                                @if($c->de_otro_aliado ?? false)
                                <span style="background:#e0e7ff;color:#3730a3;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.63rem;font-weight:700;margin-left:0.25rem;"
                                      title="La cargó {{ $c->cargada_por }}; es la misma empresa ante la entidad">↔ {{ $c->cargada_por }}</span>
                                @endif
                            @elseif($c->cliente)
                                <span class="badge-vinculo cli" title="{{ nombre_oracion($c->cliente->primer_nombre) }} {{ nombre_oracion($c->cliente->primer_apellido) }}">👤 {{ nombre_oracion($c->cliente->primer_nombre) }} {{ nombre_oracion($c->cliente->primer_apellido) }}</span>
                            @elseif($c->empresa)
                                <span class="badge-vinculo emp" title="{{ $c->empresa->empresa }}">🏭 {{ $c->empresa->empresa }}</span>
                            @else
                                <span style="color:#94a3b8;">—</span>
                            @endif
                        </td>
                        {{-- Observación --}}
                        <td style="font-size:0.73rem;color:#64748b;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $c->observacion }}">
                            {{ $c->observacion ?? '' }}
                        </td>
                        {{-- Estado --}}
                        <td style="text-align:center;">
                            @if($c->activo)
                                <span style="background:#dcfce7;color:#16a34a;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">ACTIVO</span>
                            @else
                                <span style="background:#fee2e2;color:#dc2626;padding:0.12rem 0.45rem;border-radius:999px;font-size:0.65rem;font-weight:700;">INACTIVO</span>
                            @endif
                        </td>
                        {{-- Acciones --}}
                        <td style="text-align:center;white-space:nowrap;">
                            <button onclick="abrirModalClaveGlobal({{ json_encode($c) }})" style="background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#92400e;" title="Editar">✏️</button>
                            <button onclick="verHistorialClave({{ $c->id }})" style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#3730a3;" title="Quién la cambió y qué había antes">🕘</button>
                            <button onclick="eliminarClaveGlobal({{ $c->id }})" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:5px;padding:0.18rem 0.55rem;font-size:0.7rem;font-weight:600;cursor:pointer;color:#dc2626;" title="Eliminar">🗑</button>
                        </td>
                    </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ MODAL: Crear / Editar Clave Global ═══════════════════════════════ --}}
<div id="glb-ca-modal-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1100;
            align-items:center;justify-content:center;backdrop-filter:blur(2px);"
     onclick="if(event.target===this) cerrarModalClaveGlobal()">
    <div style="background:#fff;border-radius:16px;padding:0;width:560px;max-width:96vw;
                box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
        {{-- Modal header --}}
        <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:0.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:0.9rem;font-weight:800;color:#1c1917;" id="glb-ca-modal-titulo">🔑 Nueva Clave</div>
            <button onclick="cerrarModalClaveGlobal()" style="background:rgba(255,255,255,0.25);border:none;border-radius:7px;width:28px;height:28px;cursor:pointer;font-size:0.9rem;font-weight:700;color:#1c1917;">✕</button>
        </div>
        {{-- Modal body --}}
        <div style="padding:1.25rem;">
            <input type="hidden" id="glb-ca-modal-id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                {{-- Vincular A --}}
                <div style="grid-column:span 2;">
                    <label class="ca-lbl">Vincular a Razón Social *</label>
                    <select id="glb-ca-f-rs-id" class="ca-inp">
                        <option value="">— Ninguna (Clave General) —</option>
                        @foreach($razones as $r)
                            <option value="{{ $r->id }}">{{ $r->razon_social }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Tipo --}}
                <div>
                    <label class="ca-lbl">Tipo *</label>
                    <select id="glb-ca-f-tipo" class="ca-inp">
                        <option value="Portal">Portal Web</option>
                        <option value="Correo">Correo Electrónico</option>
                        <option value="EPS">EPS</option>
                        <option value="ARL">ARL</option>
                        <option value="AFP">AFP / Pensión</option>
                        <option value="CAJA">Caja de Compensación</option>
                        <option value="DIAN">DIAN</option>
                        <option value="MinTrabajo">Min. Trabajo (PILA)</option>
                        <option value="Banco">Banco / Entidad Financiera</option>
                        <option value="Operadores">Operadores</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                {{-- Entidad --}}
                <div>
                    <label class="ca-lbl">Entidad / Portal *</label>
                    <input type="text" id="glb-ca-f-entidad" class="ca-inp" placeholder="Ej: Portal EPS Sura, Gmail...">
                    @include('admin.partials._entidad_clave')
                    <script>EntidadClave.mejorar('glb-ca-f-tipo', 'glb-ca-f-entidad');</script>
                </div>
                {{-- Usuario --}}
                <div>
                    <label class="ca-lbl">Usuario / Login</label>
                    <input type="text" id="glb-ca-f-usuario" class="ca-inp" placeholder="Nombre de usuario o email">
                </div>
                {{-- Contraseña --}}
                <div>
                    <label class="ca-lbl">Contraseña</label>
                    <div style="display:flex;gap:0.3rem;align-items:center;">
                        <input type="password" id="glb-ca-f-contrasena" class="ca-inp" placeholder="••••••••" style="flex:1;">
                        <button type="button" onclick="togglePassGlobal()"
                                style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:0.35rem 0.5rem;cursor:pointer;font-size:0.8rem;flex-shrink:0;"
                                title="Mostrar/Ocultar">👁</button>
                    </div>
                </div>
                {{-- Link --}}
                <div style="grid-column:span 2;">
                    <label class="ca-lbl">Link / URL de acceso</label>
                    <input type="text" id="glb-ca-f-link" class="ca-inp" placeholder="https://...">
                </div>
                {{-- Correo entidad --}}
                <div>
                    <label class="ca-lbl">Correo de la entidad</label>
                    <input type="text" id="glb-ca-f-correo" class="ca-inp" placeholder="contacto@entidad.com">
                </div>
                {{-- Activo --}}
                <div style="display:flex;align-items:center;gap:0.5rem;padding-top:1.2rem;">
                    <input type="checkbox" id="glb-ca-f-activo" style="width:16px;height:16px;cursor:pointer;" checked>
                    <label for="glb-ca-f-activo" style="font-size:0.8rem;font-weight:600;color:#475569;cursor:pointer;">Activo</label>
                </div>
                {{-- Observación --}}
                <div style="grid-column:span 2;">
                    <label class="ca-lbl">Observación</label>
                    <textarea id="glb-ca-f-obs" class="ca-inp" rows="2" placeholder="Notas adicionales..." style="resize:vertical;"></textarea>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-top:1rem;">
                <button onclick="cerrarModalClaveGlobal()"
                        style="padding:0.45rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:0.82rem;font-weight:500;cursor:pointer;">
                    Cancelar
                </button>
                <button onclick="guardarClaveGlobal()"
                        style="padding:0.45rem 1.2rem;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:8px;
                               color:#1c1917;font-size:0.84rem;font-weight:800;cursor:pointer;box-shadow:0 2px 8px rgba(245,158,11,0.4);">
                    💾 Guardar
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const CSRF_GLB = document.querySelector('meta[name="csrf-token"]')?.content;

/**
 * Despliega u oculta las entradas de un usuario en un portal.
 *
 * Están agrupadas porque la clave es del usuario: mostrarlas sueltas invitaba a
 * cambiarla en una sola y dejar las demás con la vieja.
 */
function alternarGrupoSura(grupo, boton) {
    const filas = document.querySelectorAll('.grupo-sura-' + grupo);
    const abierto = boton.textContent.trim().startsWith('▾');

    filas.forEach(f => f.style.display = abierto ? 'none' : '');
    boton.textContent = (abierto ? '▸ ' : '▾ ') + boton.textContent.trim().slice(2);
}

function verPassGlobal(el, id, b64) {
    var actual = el.dataset.visible === '1';
    if (actual) {
        el.textContent = '•'.repeat(8) + ' 👁';
        el.dataset.visible = '0';
    } else {
        try { el.textContent = atob(b64) + ' 👁'; } catch(e) { el.textContent = '👁'; }
        el.dataset.visible = '1';
    }
}

function togglePassGlobal() {
    var inp = document.getElementById('glb-ca-f-contrasena');
    inp.type = inp.type === 'password' ? 'text' : 'password';
}

function abrirModalClaveGlobal(clave = null) {
    var modal = document.getElementById('glb-ca-modal-overlay');
    modal.style.display = 'flex';

    if (clave && clave.id) {
        document.getElementById('glb-ca-modal-titulo').textContent  = '✏️ Editar Clave #' + clave.id;
        document.getElementById('glb-ca-modal-id').value            = clave.id;
        document.getElementById('glb-ca-f-rs-id').value             = clave.razon_social_id || '';
        document.getElementById('glb-ca-f-tipo').value              = clave.tipo || 'Portal';
        document.getElementById('glb-ca-f-entidad').value           = clave.entidad || ''; EntidadClave.refrescar('glb-ca-f-entidad');
        document.getElementById('glb-ca-f-usuario').value           = clave.usuario || '';
        document.getElementById('glb-ca-f-contrasena').value        = clave.contrasena || '';
        document.getElementById('glb-ca-f-link').value              = clave.link_acceso || '';
        document.getElementById('glb-ca-f-correo').value            = clave.correo_entidad || '';
        document.getElementById('glb-ca-f-obs').value               = clave.observacion || '';
        document.getElementById('glb-ca-f-activo').checked          = clave.activo == 1 || clave.activo === true;
    } else {
        document.getElementById('glb-ca-modal-titulo').textContent = '🔑 Nueva Clave';
        document.getElementById('glb-ca-modal-id').value           = '';
        document.getElementById('glb-ca-f-rs-id').value            = '';
        document.getElementById('glb-ca-f-tipo').value             = 'Portal';
        document.getElementById('glb-ca-f-entidad').value          = ''; EntidadClave.refrescar('glb-ca-f-entidad');
        document.getElementById('glb-ca-f-usuario').value          = '';
        document.getElementById('glb-ca-f-contrasena').value       = '';
        document.getElementById('glb-ca-f-link').value             = '';
        document.getElementById('glb-ca-f-correo').value           = '';
        document.getElementById('glb-ca-f-obs').value              = '';
        document.getElementById('glb-ca-f-activo').checked         = true;
    }
}

function cerrarModalClaveGlobal() {
    document.getElementById('glb-ca-modal-overlay').style.display = 'none';
}

function guardarClaveGlobal() {
    var id      = document.getElementById('glb-ca-modal-id').value;
    var rsId    = document.getElementById('glb-ca-f-rs-id').value;
    var entidad = document.getElementById('glb-ca-f-entidad').value.trim();
    var tipo    = document.getElementById('glb-ca-f-tipo').value;

    if (!entidad) {
        mostrarNotifGlobal('Ingresa el nombre de la entidad o portal.', 'error');
        return;
    }

    var body = {
        _token:          CSRF_GLB,
        tipo:            tipo,
        entidad:         entidad,
        usuario:         document.getElementById('glb-ca-f-usuario').value.trim(),
        contrasena:      document.getElementById('glb-ca-f-contrasena').value,
        link_acceso:     document.getElementById('glb-ca-f-link').value.trim(),
        correo_entidad:  document.getElementById('glb-ca-f-correo').value.trim(),
        observacion:     document.getElementById('glb-ca-f-obs').value.trim(),
        activo:          document.getElementById('glb-ca-f-activo').checked ? 1 : 0,
    };

    if (rsId) {
        body.razon_social_id = rsId;
    }

    var url    = id ? '/admin/clave-accesos/' + id : '/admin/clave-accesos';
    var method = id ? 'PUT' : 'POST';

    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': CSRF_GLB
        },
        body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(function(res) {
        if (res.success) {
            cerrarModalClaveGlobal();
            mostrarNotifGlobal(res.message || 'Guardado correctamente.', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            mostrarNotifGlobal('Error: ' + (res.message || 'Error al guardar.'), 'error');
        }
    })
    .catch(function() {
        mostrarNotifGlobal('Error de conexión al guardar.', 'error');
    });
}

/**
 * Quién cambió esta clave y qué había antes.
 *
 * Importa desde que la clave es de la empresa: la misma razón social la
 * comparten varios aliados y cualquiera puede corregirla, así que cuando un
 * trámite deja de funcionar lo primero es saber quién la tocó.
 */
async function verHistorialClave(id) {
    let cambios;

    try {
        const r = await fetch(`/admin/clave-accesos/${id}/historial`, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) throw new Error('No se pudo consultar el historial.');
        cambios = await r.json();
    } catch (e) {
        alert(e.message);
        return;
    }

    if (!cambios.length) {
        alert('Esta clave no se ha modificado desde que se lleva el registro.');
        return;
    }

    const filas = cambios.map(c => {
        const clave = c.contrasena_anterior === '__oculta__'
            ? '🔒'
            : (c.contrasena_anterior && c.contrasena_anterior !== c.contrasena_nueva
                ? `antes: ${c.contrasena_anterior}`
                : '');

        return `<tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:0.4rem 0.6rem;white-space:nowrap;">${c.cuando}</td>
            <td style="padding:0.4rem 0.6rem;">${c.quien}${c.aliado ? ` <span style="color:#64748b;">(${c.aliado})</span>` : ''}</td>
            <td style="padding:0.4rem 0.6rem;">${c.resumen}</td>
            <td style="padding:0.4rem 0.6rem;font-family:monospace;font-size:0.72rem;color:#b45309;">${clave}</td>
        </tr>`;
    }).join('');

    const caja = document.createElement('div');
    caja.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1200;display:flex;align-items:center;justify-content:center;';
    caja.onclick = (ev) => { if (ev.target === caja) caja.remove(); };
    caja.innerHTML = `<div style="background:#fff;border-radius:12px;max-width:820px;width:92%;max-height:80vh;overflow:auto;padding:1.2rem;">
        <h3 style="margin:0 0 0.8rem;font-size:1rem;">🕘 Historial de la clave</h3>
        <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
            <thead><tr style="background:#f8fafc;text-align:left;">
                <th style="padding:0.4rem 0.6rem;">Cuándo</th><th style="padding:0.4rem 0.6rem;">Quién</th>
                <th style="padding:0.4rem 0.6rem;">Qué cambió</th><th style="padding:0.4rem 0.6rem;">Contraseña anterior</th>
            </tr></thead>
            <tbody>${filas}</tbody>
        </table>
        <div style="text-align:right;margin-top:0.9rem;">
            <button onclick="this.closest('div[style*=fixed]').remove()" style="background:#1e293b;color:#fff;border:0;border-radius:7px;padding:0.4rem 1rem;cursor:pointer;">Cerrar</button>
        </div>
    </div>`;

    document.body.appendChild(caja);
}

function eliminarClaveGlobal(id) {
    if (!confirm('¿Eliminar esta clave de acceso? Esta acción no se puede deshacer.')) return;

    fetch('/admin/clave-accesos/' + id, {
        method: 'DELETE',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': CSRF_GLB
        }
    })
    .then(r => r.json())
    .then(function(res) {
        if (res.success) {
            mostrarNotifGlobal(res.message || 'Eliminada correctamente.', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            mostrarNotifGlobal('Error al eliminar.', 'error');
        }
    })
    .catch(() => mostrarNotifGlobal('Error de conexión.', 'error'));
}

function mostrarNotifGlobal(msg, tipo) {
    var el = document.getElementById('glb-claves-notif');
    el.style.display = 'block';
    if (tipo === 'success') {
        el.style.cssText = 'display:block;margin-bottom:1rem;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:#065f46;';
        el.textContent = '✅ ' + msg;
    } else {
        el.style.cssText = 'display:block;margin-bottom:1rem;padding:0.45rem 0.85rem;border-radius:7px;font-size:0.8rem;font-weight:600;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;';
        el.textContent = '❌ ' + msg;
    }
    setTimeout(function(){ el.style.display = 'none'; }, 4000);
}
</script>
@endpush
@endsection
