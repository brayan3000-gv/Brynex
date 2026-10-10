@extends('layouts.app')
@section('titulo', 'Marketing')
@section('modulo', 'Nueva campaña')

@push('styles')
<style>
.form-card { background:#fff; border-radius:12px; box-shadow:0 1px 8px rgba(0,0,0,.08); padding:1.5rem 1.75rem; margin-bottom:1.5rem; max-width:760px; }
.form-group { margin-bottom:1rem; }
.form-label { display:block; font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.3rem; }
.form-control { width:100%; padding:.5rem .7rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.83rem; color:#0f172a; }
textarea.form-control { min-height:90px; resize:vertical; }
.form-hint { font-size:.72rem; color:#94a3b8; margin-top:.25rem; }
.btn { padding:.5rem 1.1rem; border-radius:8px; font-size:.83rem; font-weight:600; cursor:pointer; border:none; }
.btn-primary { background:#2563eb; color:#fff; }
.section-title { font-size:.92rem; font-weight:700; color:#0f172a; margin-bottom:.5rem; }
.boton-row { display:flex; gap:.6rem; align-items:flex-start; margin-bottom:.6rem; }
.boton-row .texto-boton { flex:0 0 200px; padding:.5rem .6rem; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; font-size:.8rem; font-weight:600; color:#475569; }
.checkbox-row { display:flex; align-items:center; gap:.5rem; }
</style>
@endpush

@section('contenido')
<div class="contenido" x-data="campanaForm()">
    <div style="margin-bottom:1rem;">
        <a href="{{ route('admin.marketing.campanas.index') }}" style="color:#2563eb;text-decoration:none;font-size:.83rem">← Campañas</a>
    </div>

    @if($errors->any())
        <div class="flash" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.83rem;max-width:760px;">
            @foreach($errors->all() as $e) <div>❌ {{ $e }}</div> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.marketing.campanas.store') }}">
        @csrf

        <div class="form-card">
            <div class="section-title">📣 Datos de la campaña</div>
            <div class="form-group">
                <label class="form-label">Nombre interno</label>
                <input type="text" name="nombre" class="form-control" placeholder="Ej: ARL independientes julio" required value="{{ old('nombre') }}">
            </div>
            <div class="form-group">
                <label class="form-label">Plantilla de WhatsApp aprobada</label>
                <select name="plantilla_id" class="form-control" x-model="plantillaId" @change="actualizarBotones()" required>
                    <option value="">Selecciona una plantilla...</option>
                    @foreach($plantillas as $p)
                        <option value="{{ $p->id }}">{{ $p->nombre_display }} ({{ $p->categoria }})</option>
                    @endforeach
                </select>
                @if($plantillas->isEmpty())
                    <div class="form-hint">No tienes plantillas de marketing aprobadas todavía — créalas en WhatsApp → Plantillas con la categoría Marketing.</div>
                @endif
            </div>
        </div>

        <div class="form-card">
            <div class="section-title">🤖 Contexto para la IA</div>
            <div class="form-group">
                <label class="form-label">¿Qué se está promocionando?</label>
                <textarea name="descripcion_ia" class="form-control" placeholder="Ej: Plan ARL para independientes desde $95.000/mes, cubre riesgos laborales." required>{{ old('descripcion_ia') }}</textarea>
                <div class="form-hint">La IA arranca la conversación con esto en mente, en vez de empezar desde cero.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Objetivo (opcional)</label>
                <input type="text" name="objetivo" class="form-control" placeholder="Ej: cotizar y afiliar / agendar llamada" value="{{ old('objetivo') }}">
            </div>

            <template x-if="botonesPlantilla.length > 0">
                <div class="form-group">
                    <label class="form-label">Guía de botones — qué debe hacer la IA si el prospecto toca cada uno</label>
                    <template x-for="(boton, i) in botonesPlantilla" :key="i">
                        <div class="boton-row">
                            <div class="texto-boton" x-text="boton"></div>
                            <input type="hidden" :name="'boton_texto[' + i + ']'" :value="boton">
                            <input type="text" :name="'boton_instruccion[' + i + ']'" class="form-control"
                                   placeholder="Ej: Cotiza el plan y pregunta el nivel de riesgo ARL">
                        </div>
                    </template>
                </div>
            </template>

            <div class="form-group checkbox-row">
                <input type="checkbox" name="incluir_clientes_vigentes" id="incluir_clientes" value="1">
                <label for="incluir_clientes" style="font-size:.82rem;color:#374151">Incluir también a clientes actuales (por defecto se excluyen). Márcalo si la campaña es para tus propios clientes, por ejemplo con la lista de clientes vigentes.</label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Crear campaña</button>
    </form>
</div>

<script>
function campanaForm() {
    return {
        plantillaId: '{{ old('plantilla_id') }}',
        botonesPlantilla: [],
        plantillasBotones: @json($plantillas->mapWithKeys(fn ($p) => [
            $p->id => collect($p->botones ?? [])->where('tipo', 'QUICK_REPLY')->pluck('texto')->values(),
        ])),
        actualizarBotones() {
            this.botonesPlantilla = this.plantillasBotones[this.plantillaId] || [];
        },
    };
}
</script>
@endsection
