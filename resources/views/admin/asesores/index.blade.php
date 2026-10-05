@extends('layouts.app')
@section('modulo','Asesores')

@section('contenido')
<div style="background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

    @include('admin.partials.table-header', [
        'titulo'    => '🤝 Asesores Comerciales',
        'subtitulo' => 'Gestión de comisiones y reporte mensual',
        'btnTexto'  => 'Nuevo Asesor',
        'btnRuta'   => route('admin.asesores.create'),
    ])

<div style="margin-bottom:1.5rem;display:flex;gap:1rem;">
        <a href="{{ route('admin.asesores.reporte_mensual') }}"
            style="background:#f8fafc;border:1px solid #cbd5e1;padding:0.6rem 1.25rem;border-radius:8px;color:#334155;text-decoration:none;font-size:0.85rem;font-weight:600;display:inline-flex;align-items:center;gap:0.4rem;transition:background 0.2s;"
            onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
            📊 Reporte Mensual Liquidación
        </a>
        <a href="{{ route('admin.asesores.como_van') }}"
            style="background:#f8fafc;border:1px solid #cbd5e1;padding:0.6rem 1.25rem;border-radius:8px;color:#334155;text-decoration:none;font-size:0.85rem;font-weight:600;display:inline-flex;align-items:center;gap:0.4rem;transition:background 0.2s;"
            onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
            🧭 Cómo van
        </a>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">Asesor</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">Cédula</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">Contacto</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">Comisiones</th>
                    <th style="padding:0.7rem 1rem;text-align:right;color:#475569;font-weight:600;">Saldo Pdte.</th>
                    <th style="padding:0.7rem 1rem;text-align:center;color:#475569;font-weight:600;">Estado</th>
                    <th style="padding:0.7rem 1rem;text-align:center;color:#475569;font-weight:600;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($asesores as $asesor)
                <tr style="border-bottom:1px solid #f1f5f9;{{ $asesor->trashed() ? 'opacity:0.5;' : '' }}">
                    <td style="padding:0.65rem 1rem;">
                        <a href="{{ route('admin.asesores.show', $asesor) }}" style="font-weight:600;color:#2563eb;text-decoration:none;">
                            {{ $asesor->nombre }}
                        </a>
                        <div style="font-size:0.75rem;color:#94a3b8;">Ingreso: {{ $asesor->fecha_ingreso ? $asesor->fecha_ingreso->format('d/m/Y') : '—' }}</div>
                    </td>
                    <td style="padding:0.65rem 1rem;font-family:monospace;color:#475569;">{{ $asesor->cedula }}</td>
                    <td style="padding:0.65rem 1rem;">
                        <div style="color:#334155;">{{ $asesor->celular ?? $asesor->telefono ?? '—' }}</div>
                        <div style="font-size:0.75rem;color:#94a3b8;">{{ $asesor->ciudad ?? '—' }}</div>
                    </td>
                    <td style="padding:0.65rem 1rem;font-size:0.78rem;">
                        <div><span style="display:inline-block;width:12px;color:#8b5cf6;">■</span> Afil: <strong>{{ $asesor->comisionAfiliacionLabel() }}</strong></div>
                        <div style="margin-top:0.1rem;"><span style="display:inline-block;width:12px;color:#0891b2;">■</span> Admon: <strong>{{ $asesor->comisionAdmonLabel() }}</strong></div>
                    </td>
                    <td style="padding:0.65rem 1rem;text-align:right;">
                        @if($asesor->total_pendiente > 0)
                            <span style="color:#dc2626;font-weight:700;">${{ number_format($asesor->total_pendiente, 0, ',', '.') }}</span>
                        @else
                            <span style="color:#16a34a;font-weight:600;">$0</span>
                        @endif
                    </td>
                    <td style="padding:0.65rem 1rem;text-align:center;">
                        @if($asesor->trashed())
                            <span style="background:#fee2e2;color:#dc2626;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.72rem;font-weight:600;">Inactivo</span>
                        @elseif($asesor->activo)
                            <span style="background:#dcfce7;color:#16a34a;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.72rem;font-weight:600;">Activo</span>
                        @else
                            <span style="background:#fef9c3;color:#ca8a04;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.72rem;font-weight:600;">Pausado</span>
                        @endif
                    </td>
                    <td style="padding:0.65rem 1rem;text-align:center;white-space:nowrap;">
                        @if($asesor->trashed())
                            <form method="POST" action="{{ route('admin.asesores.restore', $asesor->id) }}" style="display:inline;">
                                @csrf @method('PATCH')
                                <button type="submit" style="background:#dcfce7;border:none;color:#16a34a;padding:0.35rem 0.75rem;border-radius:6px;font-size:0.76rem;font-weight:600;cursor:pointer;">
                                    ↩ Restaurar
                                </button>
                            </form>
                        @else
                            <a href="{{ route('admin.asesores.edit', $asesor) }}"
                                style="background:#dbeafe;border:none;color:#2563eb;padding:0.35rem 0.75rem;border-radius:6px;font-size:0.76rem;font-weight:600;text-decoration:none;display:inline-block;margin-right:0.25rem;">
                                ✏️ Editar
                            </a>
                            <form method="POST" action="{{ route('admin.asesores.destroy', $asesor) }}" style="display:inline;"
                                onsubmit="return confirm('¿Desactivar el asesor {{ $asesor->nombre }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" style="background:#fee2e2;border:none;color:#dc2626;padding:0.35rem 0.75rem;border-radius:6px;font-size:0.76rem;font-weight:600;cursor:pointer;">
                                    🗑 Desactivar
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center;padding:2rem;color:#94a3b8;">
                        No hay asesores registrados. <a href="{{ route('admin.asesores.create') }}" style="color:#2563eb;">Registrar el primero →</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
