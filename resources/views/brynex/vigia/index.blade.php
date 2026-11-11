@extends('layouts.app')
@section('modulo', 'Vigía subtipo 4')
@section('contenido')

<div style="max-width:1200px;margin:0 auto;" x-data="vigiaMonitor()" x-init="init()">

    {{-- Cabecera --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.5rem;font-weight:800;color:#0d2550;margin:0;">
                🛰️ Vigía del subtipo 4 — minuto a minuto
            </h1>
            <p style="color:#64748b;font-size:0.83rem;margin:0.2rem 0 0 0;">
                Validación de la UGPP (2.655) vs. caída del portal. Lee el log del sondeo automático.
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:0.6rem;background:#fff;padding:0.5rem 0.9rem;border-radius:10px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <span style="display:inline-block;width:9px;height:9px;border-radius:50%;"
                  :style="cargando ? 'background:#f59e0b;' : 'background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,0.2);'"></span>
            <span style="font-size:0.78rem;color:#475569;font-weight:600;" x-text="cargando ? 'Actualizando…' : ('En vivo · ' + ahora)"></span>
        </div>
    </div>

    {{-- Estado del último sondeo --}}
    <template x-if="ultimo">
        <div style="margin-bottom:1rem;border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;"
             :style="bannerEstilo(ultimo.estado)">
            <div style="font-size:1.6rem;" x-text="iconoEstado(ultimo.estado)"></div>
            <div style="flex:1;min-width:220px;">
                <div style="font-size:1.05rem;font-weight:800;" x-text="tituloEstado(ultimo.estado)"></div>
                <div style="font-size:0.8rem;opacity:0.85;margin-top:0.15rem;">
                    Último sondeo <span x-text="ultimo.hora"></span>
                    <span x-show="edadSeg !== null">· hace <span x-text="edadSeg"></span>s</span>
                    <span x-show="ultimo.lat !== null"> · <span x-text="ultimo.lat"></span> ms</span>
                    <span x-show="ultimo.estado === 'caido' && ultimo.motivo"> · <span x-text="ultimo.motivo"></span></span>
                </div>
            </div>
        </div>
    </template>

    {{-- KPIs del día --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.8rem;margin-bottom:1.25rem;">
        <template x-for="k in kpis" :key="k.label">
            <div style="background:#fff;border-radius:12px;padding:0.9rem 1rem;box-shadow:0 2px 8px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.03em;color:#94a3b8;font-weight:700;" x-text="k.label"></div>
                <div style="font-size:1.5rem;font-weight:800;margin-top:0.2rem;" :style="'color:' + k.color" x-text="k.valor"></div>
            </div>
        </template>
    </div>

    {{-- Tabla minuto a minuto --}}
    <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                <thead>
                    <tr style="background:#f8fafc;color:#64748b;text-align:left;">
                        <th style="padding:0.6rem 1rem;font-weight:700;">Hora</th>
                        <th style="padding:0.6rem 1rem;font-weight:700;">Latencia</th>
                        <th style="padding:0.6rem 1rem;font-weight:700;">Portal</th>
                        <th style="padding:0.6rem 1rem;font-weight:700;">2.655</th>
                        <th style="padding:0.6rem 1rem;font-weight:700;">Cédulas</th>
                        <th style="padding:0.6rem 1rem;font-weight:700;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(s, i) in sondeos" :key="s.fecha + s.hora + i">
                        <tr :style="'border-top:1px solid #f1f5f9;' + filaEstilo(s.estado)">
                            <td style="padding:0.5rem 1rem;font-variant-numeric:tabular-nums;color:#334155;" x-text="s.hora"></td>
                            <td style="padding:0.5rem 1rem;font-variant-numeric:tabular-nums;" :style="'color:' + colorLat(s.lat)">
                                <span x-text="s.lat !== null ? s.lat + ' ms' : '—'"></span>
                            </td>
                            <td style="padding:0.5rem 1rem;">
                                <span :style="s.portal_ok ? 'color:#059669;font-weight:700;' : 'color:#dc2626;font-weight:700;'"
                                      x-text="s.portal_ok ? 'OK' : 'CAÍDO'"></span>
                            </td>
                            <td style="padding:0.5rem 1rem;font-variant-numeric:tabular-nums;color:#334155;">
                                <span x-show="s.total !== null" x-text="s.n655 + '/' + s.total"></span>
                                <span x-show="s.total === null" style="color:#94a3b8;">—</span>
                            </td>
                            <td style="padding:0.5rem 1rem;">
                                <div style="display:flex;gap:3px;">
                                    <template x-for="c in s.cedulas" :key="c.cedula">
                                        <span :title="c.cedula"
                                              :style="'width:10px;height:10px;border-radius:50%;display:inline-block;' + (c.limpio ? 'background:#22c55e;' : 'background:#e2e8f0;border:1px solid #cbd5e1;')"></span>
                                    </template>
                                    <span x-show="!s.cedulas.length" style="color:#cbd5e1;">—</span>
                                </div>
                            </td>
                            <td style="padding:0.5rem 1rem;">
                                <span :style="badgeEstilo(s.estado)" x-text="etiquetaEstado(s.estado)"></span>
                                <span x-show="s.estado==='caido' && s.motivo" style="color:#94a3b8;font-size:0.72rem;margin-left:0.4rem;" x-text="s.motivo"></span>
                            </td>
                        </tr>
                    </template>
                    <template x-if="!sondeos.length">
                        <tr><td colspan="6" style="padding:2rem;text-align:center;color:#94a3b8;">Sin sondeos en el log todavía.</td></tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <p style="color:#94a3b8;font-size:0.72rem;margin-top:0.8rem;">
        El sondeo corre cada minuto por cron. Esta vista se refresca sola cada 20 s · solo lectura, no liquida nada.
    </p>
</div>

<script>
function vigiaMonitor() {
    return {
        ahora: '', ultimo: null, edadSeg: null, resumen: {}, sondeos: [], cargando: false,
        init() {
            this.cargar();
            setInterval(() => this.cargar(), 20000);
        },
        cargar() {
            this.cargando = true;
            fetch('{{ route('brynex.vigia.datos') }}', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => {
                    this.ahora = d.ahora;
                    this.ultimo = d.ultimo;
                    this.edadSeg = d.edad_seg;
                    this.resumen = d.resumen;
                    this.sondeos = d.sondeos;
                })
                .catch(() => {})
                .finally(() => this.cargando = false);
        },
        get kpis() {
            const r = this.resumen || {};
            return [
                { label: 'Huecos hoy', valor: r.huecos_hoy ?? 0, color: (r.huecos_hoy > 0 ? '#059669' : '#0d2550') },
                { label: 'Caídas portal hoy', valor: r.caidas_portal_hoy ?? 0, color: (r.caidas_portal_hoy > 0 ? '#dc2626' : '#0d2550') },
                { label: 'Sondeos hoy', valor: r.sondeos_hoy ?? 0, color: '#0d2550' },
                { label: 'Latencia prom. hoy', valor: (r.lat_promedio_hoy != null ? r.lat_promedio_hoy + ' ms' : '—'), color: '#0d2550' },
            ];
        },
        iconoEstado(e) { return e === 'hueco' ? '🟢' : (e === 'caido' ? '🔴' : '🟡'); },
        tituloEstado(e) {
            if (e === 'hueco') return 'HUECO — la validación UGPP cayó (se puede liquidar)';
            if (e === 'caido') return 'Portal caído — no es hueco, no hay nada que liquidar';
            return 'Normal — la UGPP sigue rechazando el subtipo 4';
        },
        etiquetaEstado(e) { return e === 'hueco' ? 'HUECO' : (e === 'caido' ? 'Portal caído' : 'Normal'); },
        bannerEstilo(e) {
            if (e === 'hueco') return 'background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.35);color:#065f46;';
            if (e === 'caido') return 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#991b1b;';
            return 'background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.25);color:#1e3a8a;';
        },
        filaEstilo(e) {
            if (e === 'hueco') return 'background:rgba(34,197,94,0.07);';
            if (e === 'caido') return 'background:rgba(239,68,68,0.05);';
            return '';
        },
        badgeEstilo(e) {
            const base = 'border-radius:999px;padding:0.15rem 0.6rem;font-size:0.7rem;font-weight:700;';
            if (e === 'hueco') return base + 'background:rgba(34,197,94,0.15);color:#15803d;border:1px solid rgba(34,197,94,0.3);';
            if (e === 'caido') return base + 'background:rgba(239,68,68,0.12);color:#b91c1c;border:1px solid rgba(239,68,68,0.25);';
            return base + 'background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;';
        },
        colorLat(l) {
            if (l === null) return '#94a3b8';
            if (l < 1200) return '#059669';
            if (l > 3000) return '#b45309';
            return '#334155';
        },
    };
}
</script>
@endsection
