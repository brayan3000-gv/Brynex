@extends('layouts.app')
@section('titulo', 'Marketing')
@section('modulo', 'Cargar contactos')

@push('styles')
<style>
.form-card { background:#fff; border-radius:12px; box-shadow:0 1px 8px rgba(0,0,0,.08); padding:1.5rem 1.75rem; margin-bottom:1.5rem; max-width:760px; }
.form-group { margin-bottom:1rem; }
.form-label { display:block; font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.3rem; }
.form-control { width:100%; padding:.5rem .7rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.83rem; color:#0f172a; }
.form-control:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
textarea.form-control { min-height:160px; resize:vertical; font-family:ui-monospace,monospace; }
.form-hint { font-size:.72rem; color:#94a3b8; margin-top:.25rem; }
.btn { padding:.5rem 1.1rem; border-radius:8px; font-size:.83rem; font-weight:600; cursor:pointer; border:none; }
.btn-primary { background:#2563eb; color:#fff; }
.section-title { font-size:.92rem; font-weight:700; color:#0f172a; margin-bottom:.5rem; }
.divider-or { text-align:center; color:#94a3b8; font-size:.78rem; font-weight:600; margin:1.25rem 0; position:relative; }
.divider-or::before, .divider-or::after { content:''; position:absolute; top:50%; width:42%; height:1px; background:#e2e8f0; }
.divider-or::before { left:0; } .divider-or::after { right:0; }
</style>
@endpush

@section('contenido')
<div class="contenido">
    <div style="margin-bottom:1rem;">
        <a href="{{ route('admin.marketing.listas.index') }}" style="color:#2563eb;text-decoration:none;font-size:.83rem">← Listas de contactos</a>
    </div>

    @if($errors->any())
        <div class="flash" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.83rem;max-width:760px;">
            @foreach($errors->all() as $e) <div>❌ {{ $e }}</div> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.marketing.listas.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="form-card">
            <div class="section-title">📋 Lista destino</div>
            <div class="form-group">
                <label class="form-label">Nombre de la lista</label>
                <input type="text" name="nombre_lista" class="form-control" placeholder="Ej: Base fría julio 2026" required value="{{ old('nombre_lista') }}">
                <div class="form-hint">Si ya existe una lista con este nombre, los contactos se agregan a ella.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Descripción (opcional)</label>
                <input type="text" name="descripcion" class="form-control" value="{{ old('descripcion') }}">
            </div>
        </div>

        <div class="form-card">
            <div class="section-title">✍️ Pegar números</div>
            <div class="form-group">
                <label class="form-label">Un contacto por línea</label>
                <textarea name="numeros_texto" class="form-control" placeholder="Solo el número:&#10;3001234567&#10;&#10;O con más datos separados por coma (celular,cedula,nombres,departamento,ciudad,observacion):&#10;3001234567,1234567890,Juan Pérez,Cauca,Popayán,Referido">{{ old('numeros_texto') }}</textarea>
                <div class="form-hint">Solo el celular es obligatorio — los demás datos son opcionales.</div>
            </div>
        </div>

        <div class="divider-or">Y / O</div>

        <div class="form-card">
            <div class="section-title">📎 Subir archivo Excel o CSV</div>
            <div class="form-group">
                <label class="form-label">Columnas: celular (obligatoria), cedula, nombres, departamento, ciudad, observacion</label>
                <input type="file" name="archivo" class="form-control" accept=".xlsx,.xls,.csv">
                <div class="form-hint">La primera fila debe traer los nombres de columna. Si no hay encabezados, se toma la primera columna como celular.</div>
            </div>
        </div>

        <div class="divider-or">Y / O</div>

        <div class="form-card">
            <div class="section-title">👥 Mis clientes vigentes</div>
            <div class="form-group">
                <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.83rem;color:#374151;cursor:pointer">
                    <input type="checkbox" name="clientes_vigentes" value="1" @checked(old('clientes_vigentes')) style="margin-top:.2rem">
                    <span>Agregar a todos los clientes con contrato vigente que tengan celular registrado</span>
                </label>
                <div class="form-hint">Se cargan con su nombre, departamento y ciudad, y la observación «Cliente vigente». Un celular compartido por varios clientes recibe un solo mensaje. Al crear la campaña marca «Incluir también a clientes actuales», o la tanda los va a excluir.</div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Cargar contactos</button>
    </form>
</div>
@endsection
