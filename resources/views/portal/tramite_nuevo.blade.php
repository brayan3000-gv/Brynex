@extends('layouts.portal')

@php
    $titulos = [
        'ingreso' => ['Ingresar un trabajador', 'Con estos datos lo afiliamos. Revisamos todo antes de crear el contrato.'],
        'retiro' => ['Retirar un trabajador', 'Nos dices quién sale y desde cuándo. Hacemos el retiro y te avisamos.'],
        'incapacidad' => ['Reportar una incapacidad', 'Sube el certificado. La radicamos ante la entidad y aquí ves en qué va el cobro.'],
        'otra' => ['Otra solicitud', 'Cuéntanos qué necesitas.'],
    ];
    [$titulo, $subtitulo] = $titulos[$tipo];
@endphp

@section('titulo', $titulo)

@section('contenido')
<div class="pt-titulo aparece">
    <div>
        <a href="{{ route('portal.tramites.nuevo') }}" class="volver">@include('portal._icono', ['n' => 'izq', 'clase' => 'ic-sm']) Nuevo trámite</a>
        <h1>{{ $titulo }}</h1>
        <p>{{ $subtitulo }}</p>
    </div>
</div>

@if($errors->any())
    <div class="aviso aviso-warn aparece" style="--i:1">
        @include('portal._icono', ['n' => 'info', 'clase' => 'ic-sm'])
        <div>
            <b>Revisa estos datos:</b>
            <ul style="margin:.3rem 0 0;padding-left:1.1rem">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('portal.tramites.guardar', $tipo) }}" enctype="multipart/form-data"
      class="formulario" x-data="{ enviando: false }" @submit="enviando = true">
    @csrf

    @if($tipo === 'ingreso')
        {{-- ── Ingreso ───────────────────────────────────────────── --}}
        <section class="tarjeta bloque aparece" style="--i:1">
            <h2><span class="num-paso">1</span> Datos de la persona</h2>
            <div class="rejilla">
                <label class="campo">
                    <span>Tipo de documento</span>
                    <select name="tipo_doc" required>
                        @foreach($tiposDoc as $k => $v)
                            <option value="{{ $k }}" @selected(old('tipo_doc', 'CC') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="campo">
                    <span>Número de documento</span>
                    <input name="cedula" value="{{ old('cedula') }}" inputmode="numeric" pattern="\d{4,15}" required>
                </label>
                <label class="campo"><span>Primer nombre</span><input name="primer_nombre" value="{{ old('primer_nombre') }}" required></label>
                <label class="campo"><span>Segundo nombre <em>opcional</em></span><input name="segundo_nombre" value="{{ old('segundo_nombre') }}"></label>
                <label class="campo"><span>Primer apellido</span><input name="primer_apellido" value="{{ old('primer_apellido') }}" required></label>
                <label class="campo"><span>Segundo apellido <em>opcional</em></span><input name="segundo_apellido" value="{{ old('segundo_apellido') }}"></label>
                <label class="campo"><span>Fecha de nacimiento</span><input type="date" name="fecha_nacimiento" value="{{ old('fecha_nacimiento') }}" max="{{ now()->subYears(14)->format('Y-m-d') }}" required></label>
                <div class="campo">
                    <span>Sexo</span>
                    <div class="segmentos">
                        <label><input type="radio" name="genero" value="F" @checked(old('genero') === 'F') required><i>Femenino</i></label>
                        <label><input type="radio" name="genero" value="M" @checked(old('genero') === 'M')><i>Masculino</i></label>
                    </div>
                </div>
                <label class="campo"><span>Celular</span><input name="celular" value="{{ old('celular') }}" inputmode="numeric" pattern="3\d{9}" placeholder="3001234567" required></label>
                <label class="campo"><span>Correo <em>opcional</em></span><input type="email" name="correo" value="{{ old('correo') }}"></label>
            </div>
            <div class="rejilla" x-data="ciudadesPortal(@js($ciudades), {{ (int) old('departamento_id') }}, {{ (int) old('municipio_id') }})">
                <label class="campo">
                    <span>Departamento <em>opcional</em></span>
                    <select name="departamento_id" x-model.number="depto" @change="muni = 0">
                        <option value="0">—</option>
                        @foreach($departamentos as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="campo">
                    <span>Municipio <em>opcional</em></span>
                    <select name="municipio_id" x-model.number="muni" :disabled="!depto">
                        <option value="0">—</option>
                        <template x-for="c in lista" :key="c.id"><option :value="c.id" x-text="c.nombre" :selected="c.id == muni"></option></template>
                    </select>
                </label>
                <label class="campo ancho"><span>Dirección <em>opcional</em></span><input name="direccion" value="{{ old('direccion') }}"></label>
            </div>
        </section>

        <section class="tarjeta bloque aparece" style="--i:2">
            <h2><span class="num-paso">2</span> El trabajo</h2>
            <div class="planes" x-data="{ plan: '{{ old('plan_id') }}' }">
                @foreach($planes as $p)
                    <label class="plan" :class="plan == '{{ $p->id }}' && 'activo'">
                        <input type="radio" name="plan_id" value="{{ $p->id }}" x-model="plan" required>
                        <b>{{ $p->nombre }}</b>
                        @if($p->descripcion)<small>{{ \Illuminate\Support\Str::limit($p->descripcion, 110) }}</small>@endif
                    </label>
                @endforeach
            </div>
            <div class="rejilla">
                <label class="campo"><span>Fecha de ingreso</span><input type="date" name="fecha_ingreso" value="{{ old('fecha_ingreso', now()->format('Y-m-d')) }}" required></label>
                <label class="campo"><span>Cargo que desempeña</span><input name="cargo" value="{{ old('cargo') }}" placeholder="Ej: Auxiliar de bodega" required></label>
                <label class="campo ancho"><span>Algo más que debamos saber <em>opcional</em></span><textarea name="observacion" rows="2">{{ old('observacion') }}</textarea></label>
            </div>
            <p class="nota">El salario, el nivel de riesgo y la EPS y el fondo de pensión los confirmamos nosotros al revisar.</p>
        </section>

        <section class="tarjeta bloque aparece" style="--i:3">
            <h2><span class="num-paso">3</span> Documento de identidad</h2>
            @include('portal._archivo', ['nombre' => 'doc_cedula', 'requerido' => true, 'texto' => 'Foto o PDF del documento, por ambos lados'])
        </section>

    @elseif($tipo === 'retiro')
        {{-- ── Retiro ────────────────────────────────────────────── --}}
        <section class="tarjeta bloque aparece" style="--i:1">
            <h2><span class="num-paso">1</span> ¿Quién sale?</h2>
            @include('portal._trabajador')
            <div class="rejilla">
                <label class="campo"><span>Fecha de retiro (último día que trabaja)</span><input type="date" name="fecha_retiro" value="{{ old('fecha_retiro') }}" required></label>
                <label class="campo ancho"><span>Motivo</span><textarea name="motivo" rows="2" required placeholder="Ej: terminación de contrato, renuncia…">{{ old('motivo') }}</textarea></label>
            </div>
        </section>
        <section class="tarjeta bloque aparece" style="--i:2">
            <h2><span class="num-paso">2</span> Soporte <em>opcional</em></h2>
            @include('portal._archivo', ['nombre' => 'soporte', 'requerido' => false, 'texto' => 'Carta de renuncia o terminación, si la tienes'])
        </section>

    @elseif($tipo === 'incapacidad')
        {{-- ── Incapacidad ───────────────────────────────────────── --}}
        <section class="tarjeta bloque aparece" style="--i:1">
            <h2><span class="num-paso">1</span> ¿De quién es?</h2>
            @include('portal._trabajador')
            <div class="rejilla">
                <label class="campo">
                    <span>Tipo</span>
                    <select name="tipo_incapacidad" required>
                        @foreach($tiposIncapacidad as $k => $v)
                            <option value="{{ $k }}" @selected(old('tipo_incapacidad', 'enfermedad_general') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="campo"><span>Fecha de inicio</span><input type="date" name="fecha_inicio" value="{{ old('fecha_inicio') }}" required></label>
                <label class="campo"><span>Días</span><input type="number" name="dias" min="1" max="540" value="{{ old('dias') }}" required></label>
                <label class="campo ancho"><span>¿Qué pasó? <em>opcional</em></span><textarea name="descripcion" rows="2">{{ old('descripcion') }}</textarea></label>
            </div>
        </section>
        <section class="tarjeta bloque aparece" style="--i:2">
            <h2><span class="num-paso">2</span> Documentos</h2>
            @include('portal._archivo', ['nombre' => 'doc_incapacidad', 'requerido' => true, 'texto' => 'Certificado de incapacidad'])
            <div style="height:.7rem"></div>
            @include('portal._archivo', ['nombre' => 'doc_otros[]', 'requerido' => false, 'multiple' => true, 'texto' => 'Historia clínica, epicrisis u otros (opcional, hasta 5)'])
        </section>

    @else
        {{-- ── Otra solicitud ────────────────────────────────────── --}}
        <section class="tarjeta bloque aparece" style="--i:1">
            <h2><span class="num-paso">1</span> ¿Qué necesitas?</h2>
            <div class="rejilla">
                <label class="campo">
                    <span>Asunto</span>
                    <select name="asunto" required>
                        @foreach($asuntos as $k => $v)
                            <option value="{{ $k }}" @selected(old('asunto') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            @include('portal._trabajador', ['opcional' => true])
            <div class="rejilla">
                <label class="campo ancho"><span>Cuéntanos</span><textarea name="descripcion" rows="4" required>{{ old('descripcion') }}</textarea></label>
            </div>
        </section>
        <section class="tarjeta bloque aparece" style="--i:2">
            <h2><span class="num-paso">2</span> Adjunto <em>opcional</em></h2>
            @include('portal._archivo', ['nombre' => 'adjunto', 'requerido' => false, 'texto' => 'Un documento que nos ayude'])
        </section>
    @endif

    <div class="acciones aparece" style="--i:4">
        <a href="{{ route('portal.tramites') }}" class="btn btn-sec">Cancelar</a>
        <button type="submit" class="btn" :disabled="enviando">
            <span x-show="!enviando">Enviar solicitud</span>
            <span x-show="enviando" x-cloak class="girando">Enviando…</span>
        </button>
    </div>
</form>
@endsection

@push('estilos')
<style>
    .volver { display: inline-flex; align-items: center; gap: .25rem; font-size: .8rem; font-weight: 700; color: var(--acento); text-decoration: none; margin-bottom: .4rem; }
    .formulario { max-width: 860px; }
    .bloque { padding: 1.1rem 1.1rem 1.2rem; margin-bottom: .9rem; }
    .bloque h2 { font-size: .95rem; margin: 0 0 .9rem; display: flex; align-items: center; gap: .5rem; }
    .bloque h2 em, .campo em { font-style: normal; font-weight: 500; color: var(--tenue); font-size: .75rem; }
    .num-paso { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-size: .75rem;
        background: linear-gradient(135deg, var(--azul-oscuro), var(--azul-vivo)); color: #fff; }
    .rejilla { display: grid; grid-template-columns: 1fr; gap: .7rem .8rem; margin-bottom: .7rem; }
    .campo { display: block; min-width: 0; }
    .campo > span { display: block; font-size: .75rem; font-weight: 700; color: var(--tinta-2); margin-bottom: .3rem; }
    .campo input, .campo select, .campo textarea { width: 100%; font: inherit; font-size: .9rem; border: 1.5px solid var(--borde);
        border-radius: 10px; padding: .62rem .75rem; background: #fff; color: var(--tinta); transition: border-color .15s, box-shadow .15s; }
    .campo input:focus, .campo select:focus, .campo textarea:focus { outline: 0; border-color: var(--acento); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
    .campo textarea { resize: vertical; }
    .segmentos { display: flex; gap: .4rem; }
    .segmentos label { flex: 1; }
    .segmentos input { position: absolute; opacity: 0; pointer-events: none; }
    .segmentos i { display: block; text-align: center; font-style: normal; font-size: .85rem; font-weight: 600; padding: .6rem;
        border: 1.5px solid var(--borde); border-radius: 10px; cursor: pointer; transition: all .15s; }
    .segmentos input:checked + i { background: var(--azul-btn); border-color: var(--azul-btn); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.25); }
    .planes { display: grid; grid-template-columns: 1fr; gap: .5rem; margin-bottom: .9rem; }
    .plan { display: block; border: 1.5px solid var(--borde); border-radius: 11px; padding: .7rem .8rem; cursor: pointer; position: relative; transition: all .15s; }
    .plan:hover { border-color: #93c5fd; }
    .plan input { position: absolute; opacity: 0; }
    .plan b { display: block; font-size: .88rem; }
    .plan small { display: block; font-size: .74rem; color: var(--tenue); margin-top: .15rem; }
    .plan.activo { border-color: var(--azul-btn); background: #eff6ff; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
    .nota { font-size: .78rem; color: var(--tenue); margin: 0; }
    .acciones { display: flex; justify-content: flex-end; gap: .6rem; margin-top: .4rem; }
    .acciones .btn { padding: .75rem 1.3rem; }
    .btn:disabled { opacity: .65; cursor: wait; transform: none; box-shadow: none; }
    .girando::before { content: ''; display: inline-block; width: 12px; height: 12px; margin-right: .4rem; vertical-align: -1px;
        border: 2px solid rgba(255,255,255,.5); border-top-color: #fff; border-radius: 50%; animation: gira .7s linear infinite; }
    @keyframes gira { to { transform: rotate(360deg); } }
    @media (min-width: 640px) {
        .rejilla { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .campo.ancho { grid-column: 1 / -1; }
        .planes { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 639px) {
        .acciones { position: sticky; bottom: calc(var(--alto-barra) + env(safe-area-inset-bottom) + .5rem); }
        .acciones .btn { flex: 1; }
    }
</style>
@endpush

@push('scripts')
<script>
    function ciudadesPortal(ciudades, depto, muni) {
        return {
            ciudades, depto, muni,
            get lista() { return this.ciudades.filter(c => Number(c.departamento_id) === Number(this.depto)); },
        };
    }
</script>
@endpush
