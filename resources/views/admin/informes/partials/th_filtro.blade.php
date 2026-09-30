{{-- Encabezado de columna que filtra, como en Cobros: el select es el título. --}}
<th>
    <form method="GET" style="margin:0">
        @foreach($ocultos as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <select name="{{ $campo }}" onchange="this.form.submit()" class="th-select {{ $actual ? 'activo' : '' }}" title="Filtrar por {{ $titulo }}">
            <option value="">↓ {{ $titulo }}</option>
            @foreach($opciones as $o)
                <option value="{{ $o->id }}" {{ (string) $actual === (string) $o->id ? 'selected' : '' }}>{{ \Illuminate\Support\Str::limit($texto($o), 20, '…') }} ({{ $o->total }})</option>
            @endforeach
        </select>
    </form>
</th>
