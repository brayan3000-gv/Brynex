<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Por personas · Cobro en visita · BryNex</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --azul-oscuro: #0a1628; --azul: #2563eb; --azul-suave: #eff6ff;
            --fondo: #f0f4f8; --tarjeta: #fff; --tinta: #0f172a; --tinta-2: #334155; --tenue: #64748b;
            --borde: #e2e8f0; --verde: #059669; --verde-suave: #ecfdf5; --ambar: #b45309; --ambar-suave: #fffbeb;
            --rojo: #dc2626; --rojo-suave: #fef2f2; --morado: #7c3aed; --morado-suave: #f5f3ff;
        }
        * { box-sizing: border-box; }
        [x-cloak] { display: none !important; }
        html, body { height: 100%; }
        body { display: flex; flex-direction: column; margin: 0; font-family: 'Inter', system-ui, sans-serif; background: var(--fondo); color: var(--tinta); font-size: 14px; line-height: 1.45; -webkit-font-smoothing: antialiased; }
        .num { font-variant-numeric: tabular-nums; }
        .cab { background: var(--azul-oscuro); color: #fff; padding: 14px 16px; }
        .cab-in { max-width: 1280px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
        .cab h1 { margin: 0; font-size: 1.1rem; font-weight: 800; flex: 1; min-width: 200px; }
        .cab a { color: #cbd5e1; text-decoration: none; font-size: .85rem; font-weight: 600; }
        .cab a:hover { color: #fff; }
        main { flex: 1; min-height: 0; display: flex; flex-direction: column; width: 100%; max-width: 1280px; margin: 0 auto; padding: 16px; }
        .barra { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
        .entrada { border: 1.5px solid var(--borde); border-radius: 10px; padding: 9px 12px; background: #fff; font: inherit; min-width: 0; }
        .entrada:focus { outline: none; border-color: var(--azul); }
        .entrada.empresa { flex: 1 1 320px; font-weight: 600; }
        .entrada.filtro { flex: 0 1 260px; }
        .check { display: flex; align-items: center; gap: 6px; font-size: .84rem; color: var(--tinta-2); }
        .resumen { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .dato { background: var(--tarjeta); border: 1px solid var(--borde); border-radius: 12px; padding: 10px 12px; }
        .dato small { display: block; color: var(--tenue); font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        .dato b { font-size: 1.15rem; font-weight: 800; }
        .progreso { height: 4px; background: var(--borde); border-radius: 99px; overflow: hidden; margin: -6px 0 12px; }
        .progreso div { height: 100%; background: var(--azul); transition: width .3s; }
        .caja-tabla { flex: 1 1 auto; min-height: 260px; background: var(--tarjeta); border: 1px solid var(--borde); border-radius: 12px; overflow: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background: #1e3a8a; color: #fff; font-size: .74rem; text-transform: uppercase; letter-spacing: .03em; text-align: left; padding: 9px 10px; white-space: nowrap; position: sticky; top: 0; cursor: pointer; user-select: none; }
        th.der, td.der { text-align: right; }
        th .flecha { opacity: .7; font-size: .7rem; }
        td { padding: 8px 10px; border-bottom: 1px solid var(--borde); vertical-align: top; }
        tbody tr:hover td { background: #f8fafc; }
        tfoot td { font-weight: 800; background: #f1f5f9; border-top: 2px solid #cbd5e1; position: sticky; bottom: 0; }
        .nom { font-weight: 600; }
        .sub { color: var(--tenue); font-size: .75rem; }
        .link { background: none; border: 0; padding: 2px 4px; margin: -2px -4px; border-radius: 6px; font: inherit; font-weight: 700; cursor: pointer; color: var(--azul); }
        .link:hover { background: var(--azul-suave); }
        .link.empresa { color: var(--morado); }
        .link.empresa:hover { background: var(--morado-suave); }
        .cero { color: #cbd5e1; }
        .falta { color: var(--rojo); font-weight: 800; }
        .chip { display: inline-block; border-radius: 99px; padding: 2px 9px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
        .chip.verde { background: var(--verde-suave); color: var(--verde); }
        .chip.ambar { background: var(--ambar-suave); color: var(--ambar); }
        .chip.motivo { white-space: normal; border-radius: 10px; line-height: 1.3; padding: 4px 9px; display: inline-block; max-width: 220px; }
        .veces { display: block; color: var(--tenue); font-size: .72rem; font-weight: 700; margin-top: 1px; }
        .chip.gris { background: #f1f5f9; color: var(--tenue); }
        .ayuda { text-align: center; color: var(--tenue); padding: 40px 16px; }
        .cargando { display: inline-block; width: 14px; height: 14px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: gira .7s linear infinite; vertical-align: -2px; }
        @keyframes gira { to { transform: rotate(360deg); } }

        .fondo { position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 40; }
        .modal { position: fixed; z-index: 41; left: 50%; top: 5vh; transform: translateX(-50%); width: min(980px, calc(100vw - 32px)); max-height: 90vh; overflow: auto; background: #fff; border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        .modal-cab { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px; border-bottom: 1px solid var(--borde); position: sticky; top: 0; background: #fff; }
        .modal-cab h2 { margin: 0; font-size: 1.05rem; }
        .cerrar { background: #f1f5f9; border: 0; border-radius: 8px; width: 32px; height: 32px; cursor: pointer; font-size: 1rem; }
        .modal-cuerpo { padding: 14px 18px 18px; }
        .pestanas { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
        .pestanas button { border: 1.5px solid var(--borde); background: #fff; border-radius: 99px; padding: 6px 14px; font: inherit; font-weight: 700; font-size: .82rem; cursor: pointer; color: var(--tinta-2); }
        .pestanas button.activa { border-color: var(--azul); background: var(--azul-suave); color: var(--azul); }
        .pestanas button.activa.empresa { border-color: var(--morado); background: var(--morado-suave); color: var(--morado); }
        .modal table { min-width: 760px; }
        .modal th { background: #f1f5f9; color: var(--tinta-2); cursor: default; position: static; }
        .modal td a { color: var(--azul); font-weight: 600; text-decoration: none; white-space: nowrap; }
        .modal td a:hover { text-decoration: underline; }
        .nota-favor { margin-top: 12px; background: var(--verde-suave); color: var(--verde); border-radius: 10px; padding: 9px 12px; font-weight: 600; font-size: .85rem; }
    </style>
</head>
<body x-data="personas()" x-init="iniciar()" @keydown.escape.window="cerrarDetalle()">

<header class="cab">
    <div class="cab-in">
        <h1>📋 Por personas · lo abonado y lo que falta</h1>
        <a href="{{ route('admin.visita.index') }}">← Cobro en visita</a>
        <a href="{{ route('dashboard') }}">Panel</a>
    </div>
</header>

<main>
    <div class="barra">
        <input class="entrada empresa" list="lista-empresas" placeholder="🏢 Elige la empresa" x-model="empresaTexto" @change="elegirEmpresa()">
        <datalist id="lista-empresas">
            @foreach($empresas as $e)<option value="{{ $e['nombre'] }}"></option>@endforeach
        </datalist>
        <input class="entrada filtro" type="search" placeholder="🔎 Nombre o cédula" x-model="filtro" x-show="filas.length" x-cloak>
        <label class="check" x-show="filas.length" x-cloak><input type="checkbox" x-model="ocultarCompletos"> Ocultar los que ya completaron</label>
        <button type="button" class="entrada" style="cursor:pointer;font-weight:700" x-show="empresaId" x-cloak @click="cargar()" title="Volver a calcular">↻ Actualizar</button>
    </div>

    <template x-if="empresaId && filas.length">
        <div>
            <div class="resumen">
                <div class="dato"><small>Personas</small><b class="num" x-text="filas.length"></b></div>
                <div class="dato"><small>Ya completaron</small><b class="num" style="color:var(--verde)" x-text="completos()"></b></div>
                <div class="dato"><small>Total a pagar</small><b class="num" x-text="plata(total('total_mes'))"></b></div>
                <div class="dato"><small>Abono persona</small><b class="num" style="color:var(--azul)" x-text="plata(total('abono_persona'))"></b></div>
                <div class="dato"><small>Abono empresa</small><b class="num" style="color:var(--morado)" x-text="plata(total('abono_empresa'))"></b></div>
                <div class="dato"><small>Falta en total</small><b class="num" style="color:var(--rojo)" x-text="plata(total('falta'))"></b></div>
            </div>
            <div class="progreso" x-show="calculados() < filas.length"><div :style="'width:' + (100 * calculados() / filas.length) + '%'"></div></div>
        </div>
    </template>

    <div class="ayuda" x-show="!empresaId">Elige una empresa para ver a su gente con contrato vigente.</div>
    <div class="ayuda" x-show="empresaId && cargando" x-cloak><span class="cargando"></span> Cargando…</div>
    <div class="ayuda" x-show="empresaId && !cargando && !filas.length" x-cloak>Esta empresa no tiene personas con contrato vigente.</div>

    <div class="caja-tabla" x-show="filas.length" x-cloak>
        <table>
            <thead>
                <tr>
                    <th class="der" style="cursor:default;width:1%">#</th>
                    <th @click="ordenar('nombre')">Nombre y cédula <span class="flecha" x-text="flecha('nombre')"></span></th>
                    <th @click="ordenar('periodo')">Próximo pago <span class="flecha" x-text="flecha('periodo')"></span></th>
                    <th class="der" @click="ordenar('total_mes')">Total a pagar <span class="flecha" x-text="flecha('total_mes')"></span></th>
                    <th class="der" @click="ordenar('abono_persona')">Abono persona <span class="flecha" x-text="flecha('abono_persona')"></span></th>
                    <th class="der" @click="ordenar('abono_empresa')">Abono empresa <span class="flecha" x-text="flecha('abono_empresa')"></span></th>
                    <th class="der" @click="ordenar('falta')">Falta <span class="flecha" x-text="flecha('falta')"></span></th>
                    <th @click="ordenar('estado')">Estado <span class="flecha" x-text="flecha('estado')"></span></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(f, i) in visibles()" :key="f.contrato_id">
                    <tr>
                        <td class="der num sub" x-text="i + 1"></td>
                        <td>
                            <div class="nom" x-text="nombrePropio(f.nombre) || 'Sin nombre'"></div>
                            <div class="sub num" x-text="'CC ' + f.cedula"></div>
                        </td>
                        <td>
                            <template x-if="!f.calc"><span class="cargando" style="color:var(--tenue)"></span></template>
                            <span x-show="f.calc && !f.calc.error" x-text="f.calc && f.calc.periodo"></span>
                        </td>
                        <td class="der num" x-text="ok(f) ? plata(f.calc.total_mes) : ''"></td>
                        <td class="der num">
                            <template x-if="ok(f)">
                                <div>
                                    <button type="button" class="link" :class="!f.calc.abono_persona && 'cero'" @click="abrirDetalle(f, 'persona')" x-text="plata(f.calc.abono_persona)"></button>
                                    <span class="veces" x-show="f.calc.abonos_persona > 0" x-text="'x' + f.calc.abonos_persona" :title="f.calc.abonos_persona === 1 ? 'Un abono' : f.calc.abonos_persona + ' abonos'"></span>
                                </div>
                            </template>
                        </td>
                        <td class="der num">
                            <template x-if="ok(f)">
                                <button type="button" class="link empresa" :class="!f.calc.abono_empresa && 'cero'" @click="abrirDetalle(f, 'empresa')" x-text="plata(f.calc.abono_empresa)"></button>
                            </template>
                        </td>
                        <td class="der num">
                            <span x-show="ok(f) && f.calc.falta > 0" class="falta" x-text="ok(f) ? plata(f.calc.falta) : ''"></span>
                            <span x-show="ok(f) && f.calc.falta === 0" class="cero">$0</span>
                        </td>
                        <td>
                            <template x-if="completo(f)"><span class="chip verde">✓ Completo</span></template>
                            <template x-if="ok(f) && f.calc.falta > 0 && f.calc.facturable"><span class="chip gris">Debe</span></template>
                            <template x-if="ok(f) && !f.calc.facturable"><span class="chip ambar motivo" x-html="motivo(f.calc.motivo)"></span></template>
                            <template x-if="f.calc && f.calc.error"><span class="chip ambar" x-text="f.calc.error"></span></template>
                        </td>
                    </tr>
                </template>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" x-text="'Total · ' + visibles().length + (visibles().length === 1 ? ' persona' : ' personas')"></td>
                    <td class="der num" x-text="plata(total('total_mes', visibles()))"></td>
                    <td class="der num" style="color:var(--azul)" x-text="plata(total('abono_persona', visibles()))"></td>
                    <td class="der num" style="color:var(--morado)" x-text="plata(total('abono_empresa', visibles()))"></td>
                    <td class="der num" style="color:var(--rojo)" x-text="plata(total('falta', visibles()))"></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="sub" x-show="filas.some(f => f.varios)" x-cloak style="margin-top:8px">
        Quien tiene dos contratos sale en dos filas con los mismos abonos (son de la cédula); en los totales de abonos se cuentan una vez.
    </p>
</main>

{{-- ── Detalle de los abonos ──────────────────────────────────── --}}
<template x-if="detalle">
<div>
    <div class="fondo" @click="cerrarDetalle()"></div>
    <section class="modal" role="dialog" aria-modal="true">
        <div class="modal-cab">
            <div style="flex:1;min-width:0">
                <h2 x-text="nombrePropio(detalle.fila.nombre) || ('CC ' + detalle.fila.cedula)"></h2>
                <div class="sub" x-text="'CC ' + detalle.fila.cedula + (ok(detalle.fila) ? ' · ' + detalle.fila.calc.periodo + ': ' + plata(detalle.fila.calc.total_mes) + ' · falta ' + plata(detalle.fila.calc.falta) : '')"></div>
            </div>
            <button class="cerrar" @click="cerrarDetalle()" aria-label="Cerrar">✕</button>
        </div>
        <div class="modal-cuerpo">
            <div class="pestanas">
                <button type="button" :class="detalle.ver === 'persona' && 'activa'" @click="detalle.ver = 'persona'"
                        x-text="'De la persona · ' + plata(sumaLibre('persona'))"></button>
                <button type="button" class="empresa" :class="detalle.ver === 'empresa' && 'activa'" @click="detalle.ver = 'empresa'"
                        x-text="'De la empresa · ' + plata(sumaLibre('empresa'))"></button>
            </div>

            <div class="ayuda" x-show="!detalle.datos" style="padding:24px"><span class="cargando"></span> Cargando…</div>
            <div class="ayuda" x-show="detalle.error" style="padding:24px;color:var(--rojo)" x-text="detalle.error"></div>

            <template x-if="detalle.datos">
                <div>
                    <div class="ayuda" style="padding:20px" x-show="!detalle.datos[detalle.ver].length"
                         x-text="detalle.ver === 'persona' ? 'La persona no tiene abonos libres.' : 'La empresa no tiene abonos libres para esta persona.'"></div>
                    <div style="overflow-x:auto" x-show="detalle.datos[detalle.ver].length">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th><th>Forma</th><th>Cuenta</th><th>Referencia</th>
                                    <th class="der">Valor</th><th class="der">Libre</th>
                                    <th x-text="detalle.ver === 'empresa' ? 'Para el mes · de' : 'Recibió'"></th>
                                    <th>Soporte</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="a in detalle.datos[detalle.ver]" :key="a.id">
                                    <tr>
                                        <td class="num" x-text="a.fecha"></td>
                                        <td>
                                            <span class="chip" :class="a.efectivo ? 'verde' : 'gris'" x-text="(a.efectivo ? '💵 ' : '🏦 ') + a.forma"></span>
                                            <div class="sub" x-show="a.nota" x-text="a.nota"></div>
                                        </td>
                                        <td x-text="a.cuenta || (a.efectivo ? '—' : 'Sin cuenta')"></td>
                                        <td class="num" x-text="a.referencia || '—'"></td>
                                        <td class="der num" x-text="plata(a.valor)"></td>
                                        <td class="der num" style="font-weight:700" x-text="plata(a.disponible)"></td>
                                        <td>
                                            <template x-if="detalle.ver === 'empresa'">
                                                <div><div x-text="a.para || '—'"></div><div class="sub" x-text="a.de"></div></div>
                                            </template>
                                            <template x-if="detalle.ver === 'persona'">
                                                <div><div x-text="a.recibio || '—'"></div><div class="sub" x-show="a.contrato" x-text="a.contrato"></div></div>
                                            </template>
                                        </td>
                                        <td>
                                            <a x-show="a.soporte_url" :href="a.soporte_url" target="_blank" rel="noopener">🖼 Ver soporte</a>
                                            <span class="sub" x-show="!a.soporte_url" x-text="a.efectivo ? 'Efectivo' : 'Sin soporte'"></span>
                                            <div><a x-show="a.recibo_url" :href="a.recibo_url" target="_blank" rel="noopener" style="font-size:.78rem">📄 Recibo</a></div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="nota-favor" x-show="detalle.ver === 'persona' && ok(detalle.fila) && detalle.fila.calc.saldo_favor > 0"
                         x-text="'Además tiene ' + plata(ok(detalle.fila) ? detalle.fila.calc.saldo_favor : 0) + ' de saldo a favor de recibos anteriores, que también se descuenta del pago.'"></div>
                </div>
            </template>
        </div>
    </section>
</div>
</template>

<script>
function personas() {
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const RUTAS = {
        faltantes: @json(route('admin.visita.faltantes')),
        calcular: @json(route('admin.visita.faltantes.calcular')),
        abonos: @json(route('admin.visita.abonos')),
    };
    const EMPRESAS = @json($empresas);
    const leerLS = (k) => { try { return localStorage.getItem(k); } catch (_) { return null; } };
    const recordar = (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (_) {} };

    return {
        empresaTexto: '', empresaId: null,
        filas: [], cargando: false, turno: 0,
        filtro: '', ocultarCompletos: false,
        orden: { campo: 'nombre', asc: true },
        detalle: null,

        iniciar() {
            const pedida = new URLSearchParams(location.search).get('empresa_id') || leerLS('visita.empresa');
            const e = EMPRESAS.find(x => String(x.id) === String(pedida));
            if (e) { this.empresaTexto = e.nombre; this.empresaId = e.id; this.cargar(); }
        },

        plata(v) { return '$' + Number(v || 0).toLocaleString('es-CO'); },
        ok(f) { return f && f.calc && !f.calc.error; },
        // «KEVIN ALEJANDRO DE LA CRUZ» → «Kevin Alejandro de la Cruz».
        nombrePropio(n) {
            const menores = ['de', 'del', 'la', 'las', 'los', 'y'];
            return String(n || '').trim().toLowerCase().split(/\s+/)
                .map((p, i) => (i > 0 && menores.includes(p)) ? p : p.charAt(0).toUpperCase() + p.slice(1))
                .join(' ');
        },

        elegirEmpresa() {
            const e = EMPRESAS.find(x => x.nombre === this.empresaTexto.trim());
            this.empresaId = e ? e.id : null;
            recordar('visita.empresa', e ? String(e.id) : null);
            const u = new URL(location.href);
            e ? u.searchParams.set('empresa_id', e.id) : u.searchParams.delete('empresa_id');
            history.replaceState(null, '', u);
            this.filas = [];
            if (e) this.cargar();
        },

        async pedir(url) {
            const r = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } });
            if (r.status === 419 || r.status === 401) { alert('Tu sesión expiró. Vuelve a entrar.'); location.reload(); throw new Error('sesion'); }
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.mensaje || data.message || 'Error ' + r.status);
            return data;
        },

        // Los contratos de la empresa y, por tandas de 10, lo que falta en cada uno.
        async cargar() {
            const turno = ++this.turno;
            this.cargando = true;
            this.filas = [];
            try {
                const d = await this.pedir(RUTAS.faltantes + '?' + new URLSearchParams({ empresa_id: this.empresaId }));
                if (turno !== this.turno) return;
                this.filas = d.contratos.map(c => ({ ...c, calc: null }));
            } catch (e) {
                if (turno === this.turno) alert(e.message);
                return;
            } finally {
                if (turno === this.turno) this.cargando = false;
            }
            const ids = this.filas.map(f => f.contrato_id);
            const tandas = [];
            for (let i = 0; i < ids.length; i += 10) tandas.push(ids.slice(i, i + 10));
            const trabajar = async () => {
                while (tandas.length && turno === this.turno) {
                    const tanda = tandas.shift();
                    try {
                        const d = await this.pedir(RUTAS.calcular + '?' + tanda.map(id => 'ids[]=' + id).join('&'));
                        if (turno !== this.turno) return;
                        d.faltantes.forEach(r => { const f = this.filas.find(x => x.contrato_id === r.contrato_id); if (f) f.calc = r; });
                    } catch (e) {
                        if (turno !== this.turno) return;
                        tanda.forEach(id => { const f = this.filas.find(x => x.contrato_id === id); if (f) f.calc = { error: 'No se pudo calcular.' }; });
                    }
                }
            };
            await Promise.all([trabajar(), trabajar(), trabajar()]);
        },

        motivo(m) {
            const esc = (t) => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            const [uno, ...resto] = String(m || 'Se factura en la oficina.').split(': ');
            return '⚠ ' + esc(uno) + (resto.length ? ':<br>' + esc(resto.join(': ')) : '');
        },
        calculados() { return this.filas.filter(f => f.calc).length; },
        completo(f) { return this.ok(f) && f.calc.falta === 0 && f.calc.facturable; },
        completos() { return this.filas.filter(f => this.completo(f)).length; },

        // Los abonos son de la cédula: con dos contratos se cuentan una vez.
        total(campo, filas = this.filas) {
            const vistas = new Set();
            return filas.reduce((t, f) => {
                if (!this.ok(f)) return t;
                if (campo === 'abono_persona' || campo === 'abono_empresa') {
                    if (vistas.has(f.cedula)) return t;
                    vistas.add(f.cedula);
                }
                return t + (f.calc[campo] || 0);
            }, 0);
        },

        valor(f, campo) {
            if (campo === 'nombre') return (f.nombre || '').toLowerCase();
            if (campo === 'cedula') return Number(f.cedula) || 0;
            if (!this.ok(f)) return this.orden.asc ? Infinity : -Infinity;
            if (campo === 'periodo') return (f.calc.anio || 0) * 100 + (f.calc.mes || 0);
            if (campo === 'estado') return this.completo(f) ? 0 : (f.calc.facturable ? 1 : 2);
            return f.calc[campo] || 0;
        },
        ordenar(campo) {
            this.orden = this.orden.campo === campo ? { campo, asc: !this.orden.asc } : { campo, asc: campo === 'nombre' || campo === 'cedula' };
        },
        flecha(campo) { return this.orden.campo === campo ? (this.orden.asc ? '▲' : '▼') : ''; },

        visibles() {
            const q = this.filtro.trim().toLowerCase();
            const { campo, asc } = this.orden;
            return this.filas
                .filter(f => !q || (f.nombre || '').toLowerCase().includes(q) || String(f.cedula).includes(q))
                .filter(f => !this.ocultarCompletos || !this.completo(f))
                .slice()
                .sort((a, b) => {
                    const x = this.valor(a, campo), y = this.valor(b, campo);
                    return (x < y ? -1 : x > y ? 1 : 0) * (asc ? 1 : -1);
                });
        },

        async abrirDetalle(fila, ver) {
            this.detalle = { fila, ver, datos: null, error: '' };
            try {
                const d = await this.pedir(RUTAS.abonos + '?' + new URLSearchParams({ contrato_id: fila.contrato_id }));
                if (this.detalle && this.detalle.fila === fila) this.detalle.datos = d;
            } catch (e) {
                if (this.detalle && this.detalle.fila === fila) this.detalle.error = e.message;
            }
        },
        cerrarDetalle() { this.detalle = null; },
        sumaLibre(ver) {
            if (!this.detalle) return 0;
            if (this.detalle.datos) return this.detalle.datos[ver].reduce((t, a) => t + a.disponible, 0);
            const f = this.detalle.fila;
            return this.ok(f) ? (ver === 'persona' ? f.calc.abono_persona - (f.calc.saldo_favor || 0) : f.calc.abono_empresa) : 0;
        },
    };
}
</script>
</body>
</html>
