{{-- Una tarjeta de plan del formulario de ingreso. Usa `plan` del x-data que la envuelve. --}}
<label class="plan" :class="plan == '{{ $p->id }}' && 'activo'">
    <input type="radio" name="plan_id" value="{{ $p->id }}" x-model="plan" required>
    <b>{{ $p->nombre }}</b>
    @if($p->descripcion)<small>{{ \Illuminate\Support\Str::limit($p->descripcion, 110) }}</small>@endif
</label>
