@extends('layouts.app')

@section('titulo', 'Finanzas')
@section('modulo', 'Préstamos Otorgados')

@section('contenido')
@include('finanzas.partials._responsive_fin')
{{-- El buscador filtra mientras se escribe: cada palabra escrita debe aparecer
     en el nombre, la cédula o el celular, sin importar mayúsculas ni tildes. --}}
<div class="finanzas-container" x-data="{
    buscar: '',
    coincide(texto) {
        const palabras = this.buscar.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim().split(/\s+/).filter(Boolean);
        return palabras.every(p => texto.includes(p));
    }
}">

    @component('finanzas.partials._header_banner', [
        'titulo' => '🤝 Préstamos a Terceros',
        'subtitulo' => 'Monitorea capital, intereses acumulados, y días transcurridos de vencimiento con semáforo de alerta en mora.',
        'breadcrumb' => [
            'Finanzas Personales' => route('finanzas.dashboard'),
            'Préstamos' => null
        ]
    ])
        @slot('opciones')
            <div class="period-selector-bx" style="margin: 0; display: inline-flex; gap: 0.25rem;">
                <a href="{{ route('finanzas.prestamos.index', ['estado' => 'activo']) }}" class="btn-state-filtro {{ $estado === 'activo' ? 'activo' : '' }}">
                    ⏳ Activos / Mora
                </a>
                <a href="{{ route('finanzas.prestamos.index', ['estado' => 'pagado']) }}" class="btn-state-filtro {{ $estado === 'pagado' ? 'activo' : '' }}">
                    ✅ Pagados
                </a>
                <a href="{{ route('finanzas.prestamos.index', ['estado' => 'castigado']) }}" class="btn-state-filtro {{ $estado === 'castigado' ? 'activo castigado-tab' : '' }}">
                    ⛔ Inactivos
                </a>
            </div>
            
            <a href="{{ route('finanzas.expedientes.index') }}" class="btn-fin-link">📄 Préstamos Formales</a>
            <a href="{{ route('finanzas.cuenta-corriente.index') }}" class="btn-fin-link success">💼 Cuenta Corriente (Servicios)</a>
            <a href="{{ route('finanzas.prestamos.create') }}" class="btn-fin success">
                ➕ Nuevo Préstamo
            </a>
        @endslot
    @endcomponent

    {{-- Buscador de Préstamos --}}
    <div style="margin-top: 1.25rem; margin-bottom: 0.5rem; max-width: 480px;">
        <div style="position: relative; display: flex; align-items: center;">
            <span style="position: absolute; left: 0.75rem; color: #94a3b8; font-size: 0.9rem;">🔍</span>
            <input type="text" 
                   x-model="buscar" 
                   placeholder="Buscar deudor por nombre, cédula o celular..." 
                   autofocus
                   style="width: 100%; padding: 0.6rem 0.9rem 0.6rem 2.2rem; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 0.85rem; outline: none; box-sizing: border-box;">
        </div>
    </div>

    {{-- Grid de Préstamos --}}
    <div class="prestamos-grid">
        @forelse($prestamos as $p)
            @php
                $esCastigado = $p->estado === 'castigado';
                // Semáforo anclado a la fecha de corte real, la misma que anuncia el
                // recordatorio de WhatsApp. Antes se calculaba sobre `dias_mora`, que
                // cuenta desde el último abono y pintaba en rojo a deudores al día.
                $vencido      = $p->esta_vencido;
                $diasVencidos = $p->dias_vencidos;
                $diasParaCorte = $p->dias_para_corte;
                $fechaCorte    = $p->fecha_corte->format('d/m');

                $claseMora = 'ok';
                $colorMora = '#22c55e';
                if ($esCastigado) {
                    $colorMora = '#9ca3af'; // Gris para inactivos
                } elseif ($vencido) {
                    $claseMora = $diasVencidos > 5 ? 'danger' : 'warning';
                    $colorMora = $diasVencidos > 5 ? '#ef4444' : '#f59e0b';
                } elseif ($diasParaCorte <= 5) {
                    $claseMora = 'warning';
                    $colorMora = '#f59e0b';
                }
            @endphp
            <div class="prestamo-card {{ $esCastigado ? 'card-castigada' : '' }}" 
                 x-data="{ 
                     enviando: false, 
                     ultimoEnvio: '{{ $p->ultimo_mensaje_cobro ? $p->ultimo_mensaje_cobro->created_at->format('d/m/Y H:i') : '' }}',
                     enviarCobro(id) {
                         this.enviando = true;
                         fetch('{{ route('finanzas.prestamos.whatsapp', '__ID__') }}'.replace('__ID__', id), {
                             method: 'POST',
                             headers: {
                                 'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                 'Accept': 'application/json',
                                 'X-Requested-With': 'XMLHttpRequest'
                             }
                         })
                         .then(res => {
                             if (!res.ok) {
                                 return res.json().then(data => { throw new Error(data.message || 'Error al enviar'); });
                             }
                             return res.json();
                         })
                         .then(data => {
                             this.ultimoEnvio = data.fecha_envio;
                             alert('✅ ' + data.message);
                         })
                         .catch(err => {
                             alert('❌ ' + err.message);
                         })
                         .finally(() => {
                             this.enviando = false;
                         });
                     }
                 }"
                 data-buscar="{{ Str::lower(Str::ascii($p->nombre_deudor . ' ' . $p->cedula_deudor . ' ' . $p->telefono_deudor)) }}"
                 x-show="coincide($el.dataset.buscar)"
                 style="border-top: 4px solid {{ $colorMora }}">
                <div class="pc-header">
                    <div>
                        <h3>👤 {{ nombre_oracion($p->nombre_deudor) }}</h3>
                        <small>Ref: {{ $p->descripcion ?: 'Préstamo' }}</small>
                    </div>
                    @if($p->estado === 'castigado')
                        <span class="badge-err-bx" style="background:rgba(156,163,175,0.15); border-color:#d1d5db; color:#4b5563;">⛔ Inactivo</span>
                    @elseif($p->estado === 'pagado')
                        <span class="badge-ok-bx">Pagado</span>
                    @elseif($vencido && $diasVencidos > 5)
                        <span class="badge-err-bx" style="background:rgba(239,68,68,0.1); border-color:#fca5a5; color:#b91c1c;">Vencido: {{ $diasVencidos }} días</span>
                    @elseif($vencido)
                        <span class="badge-ok-bx" style="background:rgba(245,158,11,0.1); color:#b45309; border-color:#fde68a;">Vencido: {{ $diasVencidos }} {{ $diasVencidos === 1 ? 'día' : 'días' }}</span>
                    @elseif($diasParaCorte <= 5)
                        <span class="badge-ok-bx" style="background:rgba(245,158,11,0.1); color:#b45309; border-color:#fde68a;">Corte en {{ $diasParaCorte }}d ({{ $fechaCorte }})</span>
                    @else
                        <span class="badge-ok-bx" style="background:rgba(34,197,94,0.1); color:#166534;">Al día · corte {{ $fechaCorte }}</span>
                    @endif
                </div>

                <div class="pc-body">
                    <div class="pc-item">
                        <span class="pci-label">Desembolso</span>
                        <span class="pci-val">${{ number_format($p->monto_original, 0, ',', '.') }}</span>
                    </div>
                    <div class="pc-item">
                        <span class="pci-label">Tasa Interés</span>
                        <span class="pci-val">{{ $p->tasa_interes_mensual }}% mensual</span>
                    </div>
                    <div class="pc-item" style="border-top:1px dashed #e2e8f0; padding-top:0.4rem; margin-top:0.4rem;">
                        <span class="pci-label" style="font-weight:700; color:#334155;">Saldo Actual (Capital + Int.)</span>
                        <span class="pci-val" style="font-weight:800; color:#0f172a; font-size:1.05rem;">
                            ${{ number_format($p->saldo_actual, 0, ',', '.') }}
                        </span>
                    </div>
                    <div x-show="ultimoEnvio" x-cloak style="background: rgba(34, 197, 94, 0.04); border: 1px solid rgba(34, 197, 94, 0.12); border-radius: 8px; padding: 0.4rem 0.6rem; font-size: 0.72rem; color: #475569; margin-top: 0.6rem; display: flex; align-items: center; gap: 0.4rem; justify-content: space-between;">
                        <span style="font-weight: 700; color: #166534; white-space: nowrap;">🟢 Último Cobro WA:</span>
                        <span style="font-weight: 600; color: #15803d;" x-text="ultimoEnvio"></span>
                    </div>
                </div>

                <div class="pc-footer">
                    <a href="{{ route('finanzas.prestamos.show', $p->id) }}" class="btn-fin-card primary">
                        👁️ Ver Ficha
                    </a>
                    @if($p->estado !== 'pagado' && $p->estado !== 'castigado')
                        @if($p->telefono_deudor)
                            <button @click="enviarCobro({{ $p->id }})" 
                                    class="btn-fin-card success" 
                                    :disabled="enviando"
                                    style="display: inline-flex; align-items: center; justify-content: center;">
                                <span x-show="!enviando">{{ $vencido ? '🔴 Cobrar WhatsApp' : '🟢 Recordar WhatsApp' }}</span>
                                <span x-show="enviando">⏳ Enviando...</span>
                            </button>
                        @else
                            <button @click="alert('Este deudor no tiene un número de celular registrado. Por favor, edita su ficha para agregar su número.')" class="btn-fin-card success" style="opacity: 0.65;">
                                {{ $vencido ? '🔴 Cobrar WhatsApp' : '🟢 Recordar WhatsApp' }}
                            </button>
                        @endif
                    @endif
                    @if($p->estado === 'castigado')
                        <span class="btn-fin-card" style="background:rgba(156,163,175,0.1); color:#6b7280; border:1px solid #d1d5db; font-size:0.72rem;">📄 Saldo pendiente contable</span>
                    @endif
                </div>
            </div>
        @empty
            <div style="grid-column:1/-1; text-align:center; padding:3rem; background:#fff; border-radius:14px; border:1px solid #e2e8f0; color:#64748b;">
                No hay préstamos registrados en este estado.
            </div>
        @endforelse
    </div>

</div>
@endsection

@push('styles')
<style>
.finanzas-container { max-width: 1040px; margin: 0 auto; padding: 0.5rem; }

/* Top Bar & Breadcrumb */

/* Header Section */



/* Filtro de Estado */
.btn-state-filtro { display: inline-block; padding: 0.45rem 0.9rem; text-decoration: none; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-size: 0.78rem; font-weight: 600; transition: all 0.15s; }
.btn-state-filtro:hover { border-color: #94a3b8; }
.btn-state-filtro.activo { background: var(--azul-btn); color: #fff; border-color: var(--azul-btn); }
.btn-state-filtro.activo.castigado-tab { background: #6b7280; border-color: #6b7280; }

/* Tarjeta Castigada */
.card-castigada { opacity: 0.75; filter: grayscale(0.35); }

/* Grid de Tarjetas */
.prestamos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 1.25rem; margin-top: 1.5rem; }
.prestamo-card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 1.25rem; box-shadow: 0 4px 14px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between; height: 100%; transition: all 0.2s; position: relative; }
.prestamo-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.07); }

.pc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.85rem; }
.pc-header h3 { font-size: 0.98rem; font-weight: 700; color: #0f172a; }
.pc-header small { font-size: 0.72rem; color: #64748b; display: block; margin-top: 0.15rem; }

.pc-body { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 1.15rem; }
.pc-item { display: flex; justify-content: space-between; font-size: 0.8rem; }
.pci-label { color: #64748b; }
.pci-val { color: #334155; font-weight: 600; }

.pc-footer { display: flex; gap: 0.5rem; border-top: 1px solid #f1f5f9; padding-top: 0.85rem; margin-top: auto; }
.btn-fin-card { flex: 1; padding: 0.45rem; border: none; border-radius: 8px; font-size: 0.78rem; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
.btn-fin-card.primary { background: rgba(59,130,246,0.08); color: var(--azul-btn); border: 1px solid rgba(59,130,246,0.15); }
.btn-fin-card.primary:hover { background: rgba(59,130,246,0.15); }
.btn-fin-card.success { background: rgba(34,197,94,0.08); color: #166534; border: 1px solid rgba(34,197,94,0.15); }
.btn-fin-card.success:hover { background: rgba(34,197,94,0.15); }
.btn-fin-card.success:disabled { opacity: 0.4; cursor: not-allowed; }

.badge-ok-bx { background: rgba(34,197,94,0.08); color: #166534; border: 1px solid rgba(34,197,94,0.25); border-radius: 6px; padding: 0.15rem 0.5rem; font-size: 0.68rem; font-weight: 600; }
.badge-err-bx { border: 1px solid; border-radius: 6px; padding: 0.15rem 0.5rem; font-size: 0.68rem; font-weight: 600; }
</style>
@endpush

@push('styles')
@include('finanzas.partials._responsive_movil')
@endpush
