<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a1628">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Cobro en visita · BryNex</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --azul-oscuro: #0a1628; --azul-medio: #0d2550; --azul: #2563eb; --azul-suave: #eff6ff;
            --fondo: #f0f4f8; --tarjeta: #fff; --tinta: #0f172a; --tinta-2: #334155; --tenue: #64748b;
            --borde: #e2e8f0; --verde: #059669; --verde-suave: #ecfdf5; --ambar: #b45309; --ambar-suave: #fffbeb;
            --rojo: #dc2626; --rojo-suave: #fef2f2; --radio: 14px; --sombra: 0 2px 8px rgba(15,23,42,.07);
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        [x-cloak] { display: none !important; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: var(--fondo); color: var(--tinta);
            font-size: 15px; line-height: 1.45; -webkit-font-smoothing: antialiased; }
        .num { font-variant-numeric: tabular-nums; }
        button, input, select { font: inherit; color: inherit; }
        .envoltura { max-width: 560px; margin: 0 auto; padding: 0 14px calc(90px + env(safe-area-inset-bottom)); }

        /* Encabezado */
        .cab { background: linear-gradient(135deg, var(--azul-oscuro), var(--azul-medio) 60%, #1e40af); color: #fff;
            padding: calc(10px + env(safe-area-inset-top)) 0 14px; position: sticky; top: 0; z-index: 20; box-shadow: 0 2px 12px rgba(0,0,0,.3); }
        .cab-in { max-width: 560px; margin: 0 auto; padding: 0 14px; }
        .cab-fila { display: flex; align-items: center; gap: 10px; }
        .cab h1 { font-size: 1.05rem; margin: 0; flex: 1; font-weight: 800; }
        .cab a.volver { color: #bfdbfe; text-decoration: none; font-size: .8rem; font-weight: 600; }
        .caja { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; margin-top: 10px; }
        .caja div { background: rgba(255,255,255,.1); border-radius: 10px; padding: 7px 10px; }
        .caja small { display: block; font-size: .66rem; color: #93c5fd; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
        .caja b { font-size: .98rem; }
        .caja button { background: rgba(255,255,255,.14); border: 0; color: #fff; border-radius: 10px; padding: 0 12px; font-weight: 700; font-size: .78rem; cursor: pointer; }

        .empresa { margin-top: 10px; display: flex; gap: 8px; }
        .empresa input { flex: 1; min-width: 0; border: 0; border-radius: 10px; padding: 9px 12px; background: rgba(255,255,255,.95); color: var(--tinta); font-size: .88rem; }
        .empresa button { border: 0; border-radius: 10px; padding: 0 12px; background: rgba(255,255,255,.14); color: #fff; font-weight: 700; cursor: pointer; }

        /* Buscador */
        .buscador { position: sticky; top: var(--alto-cab, 150px); z-index: 10; background: var(--fondo); padding: 12px 0 8px; }
        .buscador input { width: 100%; border: 1.5px solid var(--borde); background: #fff; border-radius: 12px; padding: 13px 14px; font-size: 1rem; box-shadow: var(--sombra); }
        .buscador input:focus { outline: none; border-color: var(--azul); }
        .ayuda { color: var(--tenue); font-size: .82rem; text-align: center; padding: 24px 10px; }

        .lista { display: flex; flex-direction: column; gap: 8px; }
        .item { background: var(--tarjeta); border-radius: 12px; padding: 12px 14px; box-shadow: var(--sombra); display: flex; align-items: center; gap: 12px;
            border: 1.5px solid transparent; cursor: pointer; text-align: left; width: 100%; }
        .item:active { border-color: var(--azul); }
        .avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--azul-suave); color: var(--azul); display: grid; place-items: center; font-weight: 800; font-size: .85rem; flex-shrink: 0; }
        .item .nom { font-weight: 700; font-size: .93rem; }
        .item .sub { color: var(--tenue); font-size: .78rem; }
        .chip { display: inline-block; font-size: .68rem; font-weight: 700; padding: 1px 7px; border-radius: 99px; background: var(--azul-suave); color: var(--azul); }
        .chip.gris { background: #f1f5f9; color: var(--tenue); }
        .chip.verde { background: var(--verde-suave); color: var(--verde); }

        /* Hoja (panel a pantalla completa en celular) */
        .hoja-fondo { position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 40; }
        .hoja { position: fixed; left: 0; right: 0; bottom: 0; max-height: 94vh; overflow-y: auto; background: var(--fondo); z-index: 41;
            border-radius: 18px 18px 0 0; padding: 0 14px calc(20px + env(safe-area-inset-bottom)); max-width: 560px; margin: 0 auto;
            box-shadow: 0 -10px 30px rgba(0,0,0,.2); }
        .hoja-cab { position: sticky; top: 0; background: var(--fondo); padding: 14px 0 10px; display: flex; align-items: flex-start; gap: 10px; z-index: 2; }
        .hoja-cab h2 { margin: 0; font-size: 1.05rem; flex: 1; }
        .cerrar { border: 0; background: #e2e8f0; width: 34px; height: 34px; border-radius: 50%; font-size: 1.1rem; cursor: pointer; flex-shrink: 0; }

        .tarjeta { background: var(--tarjeta); border-radius: var(--radio); padding: 14px; box-shadow: var(--sombra); margin-bottom: 12px; }
        .fila { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; font-size: .87rem; }
        .fila span:first-child { color: var(--tinta-2); }
        .fila.resta span:last-child { color: var(--verde); }
        .fila-toque { width: 100%; background: none; border: 0; padding: 3px 0; cursor: pointer; text-align: left; }
        .anticipos { background: #f8fafc; border: 1px solid var(--borde); border-radius: 10px; padding: 2px 10px; margin: 4px 0 6px; }
        .anticipo { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid var(--borde); font-size: .82rem; }
        .anticipo:last-child { border-bottom: 0; }
        .anticipo-btns { display: flex; gap: 4px; }
        .anticipo-btns a, .anticipo-btns button { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 9px;
            border: 1px solid var(--borde); background: #fff; text-decoration: none; font-size: 1rem; cursor: pointer; }
        .sep { border-top: 1px dashed var(--borde); margin: 8px 0; }
        .falta { display: flex; justify-content: space-between; align-items: baseline; margin-top: 6px; }
        .falta b { font-size: 1.5rem; }
        .aviso { border-radius: 10px; padding: 9px 11px; font-size: .8rem; font-weight: 600; margin-top: 10px; }
        .aviso.ambar { background: var(--ambar-suave); color: var(--ambar); border: 1px solid #fcd34d; }
        .aviso.verde { background: var(--verde-suave); color: var(--verde); border: 1px solid #6ee7b7; }
        .aviso.rojo { background: var(--rojo-suave); color: var(--rojo); border: 1px solid #fca5a5; }
        .aviso.azul { background: var(--azul-suave); color: #1e40af; border: 1px solid #bfdbfe; }

        .btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; border: 0; border-radius: 12px; padding: 14px;
            font-weight: 800; font-size: 1rem; cursor: pointer; background: var(--azul); color: #fff; text-decoration: none; }
        .btn:disabled { opacity: .5; cursor: default; }
        .btn.verde { background: var(--verde); }
        .btn.whatsapp { background: #16a34a; }
        .btn.claro { background: #fff; color: var(--tinta); border: 1.5px solid var(--borde); }
        .btns { display: grid; gap: 8px; margin-top: 12px; }
        .btns.dos { grid-template-columns: 1fr 1fr; }

        label.campo { display: block; font-size: .75rem; font-weight: 700; color: var(--tinta-2); margin: 12px 0 5px; text-transform: uppercase; letter-spacing: .03em; }
        .entrada { width: 100%; border: 1.5px solid var(--borde); border-radius: 12px; padding: 12px 13px; background: #fff; font-size: 1rem; }
        .entrada:focus { outline: none; border-color: var(--azul); }
        .entrada.plata { font-size: 1.35rem; font-weight: 800; }
        .foto { display: flex; align-items: center; gap: 10px; border: 1.5px dashed #94a3b8; border-radius: 12px; padding: 12px; background: #fff; cursor: pointer; font-weight: 600; font-size: .88rem; color: var(--tinta-2); }
        .foto img { width: 54px; height: 54px; object-fit: cover; border-radius: 8px; }
        .previa { margin-top: 14px; border-radius: 12px; padding: 12px; font-size: .88rem; font-weight: 600; }

        .mov { display: flex; justify-content: space-between; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--borde); font-size: .85rem; text-decoration: none; color: inherit; }
        .mov:last-child { border-bottom: 0; }
        .exito { text-align: center; padding: 10px 0 4px; }
        .exito .ok { width: 62px; height: 62px; border-radius: 50%; background: var(--verde-suave); color: var(--verde); display: grid; place-items: center; font-size: 2rem; margin: 0 auto 8px; }
        .cargando { display: inline-block; width: 16px; height: 16px; border: 2.5px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: gira .7s linear infinite; }
        @keyframes gira { to { transform: rotate(360deg); } }

        @media (min-width: 700px) {
            .hoja { top: 4vh; bottom: 4vh; border-radius: 18px; max-height: none; }
        }
    </style>
</head>
<body x-data="visita()" x-init="iniciar()">

<header class="cab" x-ref="cab">
    <div class="cab-in">
        <div class="cab-fila">
            <h1>🛵 Cobro en visita</h1>
            <a class="volver" href="{{ route('dashboard') }}">← Panel</a>
        </div>
        <div class="caja">
            <div><small>Efectivo hoy</small><b class="num" x-text="plata(hoy.efectivo)"></b></div>
            <div><small>Transferencias</small><b class="num" x-text="plata(hoy.transferencias)"></b></div>
            <button type="button" @click="verHoy = true" x-text="hoy.cobros + ' cobros'"></button>
        </div>
        <div class="empresa">
            <input list="lista-empresas" placeholder="🏢 Empresa que visitas (opcional)" x-model="empresaTexto" @change="elegirEmpresa()">
            <button type="button" x-show="empresaId" x-cloak @click="empresaTexto=''; elegirEmpresa()">✕</button>
        </div>
        <datalist id="lista-empresas">
            @foreach($empresas as $e)<option value="{{ $e['nombre'] }}"></option>@endforeach
        </datalist>
    </div>
</header>

<main class="envoltura">
    <div class="buscador">
        <input type="search" x-ref="buscar" placeholder="🔎 Nombre o cédula del cliente" x-model="texto"
               @input.debounce.350ms="buscar()" autocomplete="off" enterkeyhint="search">
    </div>

    <div class="ayuda" x-show="!buscando && !resultados.length" x-text="mensajeVacio()"></div>
    <div class="ayuda" x-show="buscando" x-cloak><span class="cargando"></span></div>

    <div class="lista">
        <template x-for="c in resultados" :key="c.cedula">
            <button type="button" class="item" @click="abrirCliente(c.cedula)">
                <div class="avatar" x-text="iniciales(c.nombre)"></div>
                <div style="flex:1;min-width:0">
                    <div class="nom" x-text="c.nombre"></div>
                    <div class="sub">
                        <span x-text="'CC ' + c.cedula"></span>
                        <span x-show="c.empresa" x-text="' · ' + c.empresa"></span>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:3px">
                    <span class="chip" :class="c.vigentes ? '' : 'gris'" x-text="c.vigentes ? (c.vigentes + (c.vigentes > 1 ? ' contratos' : ' contrato')) : 'sin vigente'"></span>
                    <span class="chip verde" x-show="c.anticipos" x-text="'💰 ' + plata(c.anticipos)"></span>
                </div>
            </button>
        </template>
    </div>
</main>

{{-- ── Ficha del cliente ─────────────────────────────────────── --}}
<template x-if="cliente">
<div>
    <div class="hoja-fondo" @click="cerrarCliente()"></div>
    <section class="hoja">
        <div class="hoja-cab">
            <div style="flex:1;min-width:0">
                <h2 x-text="cliente.nombre"></h2>
                <div class="sub" style="color:var(--tenue);font-size:.8rem">
                    <span x-text="'CC ' + cliente.cedula"></span>
                    <span x-show="cliente.empresa" x-text="' · ' + cliente.empresa"></span>
                    <span x-show="cliente.celular" x-text="' · 📱 ' + cliente.celular"></span>
                </div>
            </div>
            <button class="cerrar" @click="cerrarCliente()">✕</button>
        </div>

        {{-- Anticipos de la persona (cédula) y de su empresa (NIT), antes de escoger contrato --}}
        <template x-for="g in gruposAnticipos()" :key="g.titulo">
            <div class="tarjeta" style="border:1.5px solid #6ee7b7">
                <button type="button" class="fila-toque" style="display:flex;justify-content:space-between;align-items:baseline;gap:8px" @click="g.abierto = !g.abierto; abiertos[g.titulo] = g.abierto">
                    <span>
                        <span style="font-weight:800" x-text="'💰 ' + g.titulo"></span>
                        <span style="display:block;color:var(--tenue);font-size:.75rem" x-text="g.sub + ' · ' + g.items.length + (g.items.length === 1 ? ' anticipo' : ' anticipos') + ' ' + (abiertos[g.titulo] ? '▴' : '▾ ver cuáles')"></span>
                    </span>
                    <b class="num" style="color:var(--verde);font-size:1.15rem" x-text="plata(g.items.reduce((t, a) => t + a.disponible, 0))"></b>
                </button>
                <div class="anticipos" x-show="abiertos[g.titulo]" x-cloak>
                    <template x-for="a in g.items" :key="a.id">
                        <div class="anticipo">
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700" x-text="a.fecha + ' · ' + a.forma"></div>
                                <div style="color:var(--tenue);font-size:.74rem">
                                    <span x-text="'N.º ANT-' + a.id"></span>
                                    <span x-show="a.contrato" x-text="' · ' + a.contrato"></span>
                                    <span x-show="a.recibio" x-text="' · recibió ' + a.recibio"></span>
                                    <span x-show="a.disponible < a.valor" x-text="' · de ' + plata(a.valor) + ', ya se usó ' + plata(a.valor - a.disponible)"></span>
                                </div>
                            </div>
                            <b class="num" x-text="plata(a.disponible)"></b>
                            <div class="anticipo-btns">
                                <a :href="a.recibo_url" target="_blank" title="Ver recibo">📄</a>
                                <button type="button" title="Enviar recibo por WhatsApp" :disabled="a.enviando" @click="reenviarAnticipo(a)" x-text="a.enviado ? '✓' : (a.enviando ? '…' : '📲')"></button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <div class="ayuda" x-show="!contratos.length">Este cliente no tiene contratos.</div>

        <template x-for="k in contratos" :key="k.id">
            <div class="tarjeta">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
                    <div>
                        <div style="font-weight:800" x-text="k.razon_social"></div>
                        <div style="color:var(--tenue);font-size:.78rem" x-text="[k.modalidad, k.plan].filter(Boolean).join(' · ')"></div>
                    </div>
                    <span class="chip" :class="k.estado === 'vigente' ? '' : 'gris'" x-text="k.estado"></span>
                </div>

                <template x-if="k.resumen">
                    <div style="margin-top:10px">
                        <div style="font-weight:700;font-size:.9rem;margin-bottom:4px" x-text="'Mes a cobrar: ' + k.resumen.periodo"></div>
                        <div class="fila" x-show="k.resumen.desglose.ss"><span>Seguridad social</span><span class="num" x-text="plata(k.resumen.desglose.ss)"></span></div>
                        <div class="fila" x-show="k.resumen.desglose.afiliacion"><span>Afiliación</span><span class="num" x-text="plata(k.resumen.desglose.afiliacion)"></span></div>
                        <div class="fila" x-show="k.resumen.desglose.admon"><span>Administración</span><span class="num" x-text="plata(k.resumen.desglose.admon)"></span></div>
                        <div class="fila" x-show="k.resumen.desglose.seguro"><span>Seguro</span><span class="num" x-text="plata(k.resumen.desglose.seguro)"></span></div>
                        <div class="fila" x-show="k.resumen.desglose.iva"><span>IVA</span><span class="num" x-text="plata(k.resumen.desglose.iva)"></span></div>
                        <div class="fila" x-show="k.resumen.desglose.mora"><span>Mora</span><span class="num" x-text="plata(k.resumen.desglose.mora)"></span></div>
                        <div class="fila" style="font-weight:700"><span>Total del mes</span><span class="num" x-text="plata(k.resumen.total_mes)"></span></div>
                        <div class="fila resta" x-show="k.resumen.saldo_favor"><span>Saldo a favor</span><span class="num" x-text="'− ' + plata(k.resumen.saldo_favor)"></span></div>
                        <div class="fila resta" x-show="k.resumen.anticipos"><span>Anticipos a su favor</span><span class="num" x-text="'− ' + plata(k.resumen.anticipos)"></span></div>
                        <div class="sep"></div>
                        <div class="falta"><span style="font-weight:700">Falta pagar</span><b class="num" x-text="plata(k.resumen.falta)"></b></div>
                        <div style="color:var(--tenue);font-size:.72rem" x-show="k.resumen.mora_info" x-text="k.resumen.mora_info"></div>

                        <div class="aviso ambar" x-show="!k.resumen.facturable" x-text="'Lo que pague queda como anticipo. ' + (k.resumen.motivo_no_factura || '')"></div>
                        <div class="aviso rojo" x-show="k.resumen.cartera" x-text="'Tiene un préstamo pendiente de ' + plata(k.resumen.cartera) + ' (se cobra en Préstamos).'"></div>

                        <div class="btns">
                            <button class="btn" @click="abrirCobro(k)" x-text="k.resumen.facturable && k.resumen.falta === 0 ? '✅ Facturar con lo que ya pagó' : '💵 Cobrar'"></button>
                        </div>
                    </div>
                </template>
                <div class="aviso rojo" x-show="k.error" x-text="k.error"></div>
            </div>
        </template>
        <div class="ayuda" x-show="cargandoCliente"><span class="cargando"></span></div>
    </section>
</div>
</template>

{{-- ── Cobro ─────────────────────────────────────────────────── --}}
<template x-if="cobro">
<div>
    <div class="hoja-fondo" style="z-index:50" @click="!guardando && !resultado && (cobro = null)"></div>
    <section class="hoja" style="z-index:51">
        <div class="hoja-cab">
            <div style="flex:1;min-width:0">
                <h2 x-text="resultado ? 'Cobro registrado' : 'Cobrar'"></h2>
                <div style="color:var(--tenue);font-size:.8rem" x-text="cliente.nombre + ' · ' + cobro.k.razon_social"></div>
            </div>
            <button class="cerrar" x-show="!guardando" @click="cerrarCobro()">✕</button>
        </div>

        {{-- Formulario --}}
        <div x-show="!resultado">
            <div class="tarjeta" style="display:flex;justify-content:space-between;align-items:baseline">
                <span style="font-weight:700" x-text="'Falta pagar · ' + cobro.k.resumen.periodo"></span>
                <b class="num" style="font-size:1.3rem" x-text="plata(cobro.k.resumen.falta)"></b>
            </div>

            <label class="campo">💵 Efectivo</label>
            <input class="entrada plata num" inputmode="numeric" placeholder="$0" :value="formato(cobro.efectivo)" @input="cobro.efectivo = leer($event)">

            <label class="campo">🏦 Transferencia</label>
            <input class="entrada plata num" inputmode="numeric" placeholder="$0" :value="formato(cobro.transferencia)" @input="cobro.transferencia = leer($event)">

            <div x-show="cobro.transferencia > 0">
                <label class="campo">Cuenta que recibió</label>
                <select class="entrada" x-model="cobro.banco">
                    <option value="">Escoge la cuenta…</option>
                    <template x-for="b in bancos" :key="b.id"><option :value="b.id" x-text="b.nombre"></option></template>
                </select>
                <label class="campo">Referencia (opcional)</label>
                <input class="entrada" x-model="cobro.referencia" maxlength="100" placeholder="N.º de la transferencia">
                <label class="campo">Foto del comprobante (opcional)</label>
                <label class="foto">
                    <input type="file" accept="image/*" capture="environment" style="display:none" @change="tomarFoto($event)">
                    <template x-if="cobro.fotoUrl"><img :src="cobro.fotoUrl"></template>
                    <span x-text="cobro.foto ? 'Foto lista · tocar para cambiar' : '📷 Tomar foto al comprobante'"></span>
                </label>
            </div>

            <label class="campo">📱 WhatsApp para el recibo</label>
            <input class="entrada num" inputmode="tel" x-model="cobro.celular" placeholder="3001234567" maxlength="15">

            <label class="campo">Nota (opcional)</label>
            <input class="entrada" x-model="cobro.nota" maxlength="200" placeholder="Ej: puesto 14">

            <div class="previa" :class="previa().clase" x-text="previa().texto"></div>

            <div class="aviso rojo" x-show="error" x-text="error"></div>

            <div class="btns">
                <button class="btn verde" :disabled="!puedeGuardar() || guardando" @click="guardar()">
                    <span class="cargando" x-show="guardando"></span>
                    <span x-text="guardando ? 'Registrando…' : 'Registrar ' + plata(cobro.efectivo + cobro.transferencia)"></span>
                </button>
            </div>
        </div>

        {{-- Resultado --}}
        <template x-if="resultado">
            <div>
                <div class="exito">
                    <div class="ok">✓</div>
                    <div style="font-weight:800;font-size:1.05rem" x-text="resultado.mensaje"></div>
                </div>

                <div class="aviso" :class="envio.clase" x-show="envio.texto">
                    <span class="cargando" x-show="envio.enviando" style="vertical-align:-3px;margin-right:6px"></span>
                    <span x-text="envio.texto"></span>
                </div>

                <div class="btns">
                    <button class="btn whatsapp" x-show="!envio.ok" :disabled="envio.enviando" @click="enviarRecibo()">
                        <span x-text="envio.intentado ? '↻ Reintentar WhatsApp' : '📲 Enviar por WhatsApp'"></span>
                    </button>
                </div>
                <div class="btns dos">
                    <a class="btn claro" :href="resultado.recibo_url" target="_blank">📄 Ver recibo</a>
                    <button class="btn claro" @click="compartir()">↗ Compartir</button>
                </div>
                <div class="btns">
                    <button class="btn" @click="siguiente()">Siguiente cliente →</button>
                </div>
            </div>
        </template>
    </section>
</div>
</template>

{{-- ── Cobros de hoy ─────────────────────────────────────────── --}}
<template x-if="verHoy">
<div>
    <div class="hoja-fondo" @click="verHoy = false"></div>
    <section class="hoja">
        <div class="hoja-cab">
            <h2>Tu caja de hoy</h2>
            <button class="cerrar" @click="verHoy = false">✕</button>
        </div>
        <div class="tarjeta">
            <div class="fila"><span>Efectivo recibido</span><b class="num" x-text="plata(hoy.efectivo)"></b></div>
            <div class="fila"><span>Transferencias</span><b class="num" x-text="plata(hoy.transferencias)"></b></div>
            <div class="sep"></div>
            <div class="fila"><span>Cobrado en visitas</span><b class="num" x-text="plata(hoy.total_visita)"></b></div>
            <div style="color:var(--tenue);font-size:.74rem;margin-top:6px">Es la misma caja del cuadre diario: lo cobrado aquí se cierra allá.</div>
        </div>
        <div class="tarjeta" x-show="hoy.movimientos.length">
            <template x-for="(m, i) in hoy.movimientos" :key="i">
                <a class="mov" :href="m.url" target="_blank">
                    <div>
                        <div style="font-weight:700" x-text="m.nombre"></div>
                        <div style="color:var(--tenue);font-size:.76rem" x-text="m.hora + ' · ' + m.tipo"></div>
                    </div>
                    <b class="num" x-text="plata(m.valor)"></b>
                </a>
            </template>
        </div>
        <div class="ayuda" x-show="!hoy.movimientos.length">Todavía no has cobrado nada hoy.</div>
    </section>
</div>
</template>

<script>
function visita() {
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const URL = {
        buscar: @json(route('admin.visita.buscar')),
        cliente: @json(url('visita/cliente')),
        cobrar: @json(route('admin.visita.cobrar')),
        enviar: @json(route('admin.visita.recibo.enviar')),
    };
    const EMPRESAS = @json($empresas);
    const recordar = (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (_) {} };
    const leerLS = (k) => { try { return localStorage.getItem(k); } catch (_) { return null; } };

    return {
        hoy: @json($hoy),
        bancos: @json($bancos),
        empresaTexto: '', empresaId: null,
        texto: '', resultados: [], buscando: false,
        cliente: null, contratos: [], cargandoCliente: false,
        anticipos: [], anticiposEmpresa: [], abiertos: {},
        cobro: null, guardando: false, error: '', resultado: null,
        envio: { texto: '', clase: '', enviando: false, ok: false, intentado: false },
        verHoy: false,

        iniciar() {
            const e = EMPRESAS.find(x => String(x.id) === leerLS('visita.empresa'));
            if (e) { this.empresaTexto = e.nombre; this.empresaId = e.id; this.buscar(); }
            const fijarAlto = () => document.documentElement.style.setProperty('--alto-cab', this.$refs.cab.offsetHeight + 'px');
            fijarAlto(); window.addEventListener('resize', fijarAlto);
        },

        plata(v) { return '$' + Number(v || 0).toLocaleString('es-CO'); },
        formato(v) { return v ? Number(v).toLocaleString('es-CO') : ''; },
        leer(ev) {
            const n = parseInt(String(ev.target.value).replace(/\D/g, '') || '0', 10);
            ev.target.value = n ? n.toLocaleString('es-CO') : '';
            return n;
        },
        iniciales(n) { return (n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase(); },
        mensajeVacio() {
            if (this.texto.trim().length >= 3) return 'No hay clientes con ese nombre o cédula.';
            return this.empresaId ? 'Esta empresa no tiene clientes con contrato vigente. Busca por nombre o cédula.' : 'Escribe al menos 3 letras del nombre o números de la cédula.';
        },

        elegirEmpresa() {
            const e = EMPRESAS.find(x => x.nombre === this.empresaTexto.trim());
            this.empresaId = e ? e.id : null;
            if (!e) this.empresaTexto = '';
            recordar('visita.empresa', e ? String(e.id) : null);
            this.buscar();
        },

        async pedir(url, opciones = {}) {
            const r = await fetch(url, { credentials: 'same-origin', ...opciones,
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, ...(opciones.headers || {}) } });
            if (r.status === 419 || r.status === 401) { alert('Tu sesión expiró. Vuelve a entrar.'); location.reload(); throw new Error('sesion'); }
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw Object.assign(new Error(data.mensaje || data.message || 'Error ' + r.status), { data });
            return data;
        },

        async buscar() {
            const q = this.texto.trim();
            if (q.length < 3 && !this.empresaId) { this.resultados = []; return; }
            this.buscando = true;
            try {
                const p = new URLSearchParams({ q });
                if (this.empresaId) p.set('empresa_id', this.empresaId);
                const d = await this.pedir(URL.buscar + '?' + p);
                if (q === this.texto.trim()) this.resultados = d.clientes;
            } catch (e) { console.warn(e); }
            finally { this.buscando = false; }
        },

        async abrirCliente(cedula) {
            this.cliente = { cedula, nombre: 'Cargando…' };
            this.contratos = []; this.cargandoCliente = true;
            try {
                const d = await this.pedir(URL.cliente + '/' + encodeURIComponent(cedula));
                this.cliente = d.cliente; this.contratos = d.contratos;
                this.anticipos = d.anticipos || []; this.anticiposEmpresa = d.anticipos_empresa || [];
            } catch (e) { alert(e.message); this.cliente = null; }
            finally { this.cargandoCliente = false; }
        },
        cerrarCliente() { this.cliente = null; this.contratos = []; this.anticipos = []; this.anticiposEmpresa = []; this.abiertos = {}; },
        gruposAnticipos() {
            const g = [];
            if (this.anticipos.length) g.push({ titulo: 'Anticipos a su favor', sub: 'CC ' + this.cliente.cedula, items: this.anticipos });
            if (this.anticiposEmpresa.length) g.push({ titulo: 'Anticipos de ' + (this.cliente.empresa || 'la empresa'),
                sub: 'NIT ' + (this.cliente.empresa_nit || '—') + ' · sin repartir', items: this.anticiposEmpresa });
            return g;
        },

        abrirCobro(k) {
            this.error = ''; this.resultado = null;
            this.envio = { texto: '', clase: '', enviando: false, ok: false, intentado: false };
            this.cobro = {
                k, efectivo: 0, transferencia: 0, banco: this.bancos.length === 1 ? String(this.bancos[0].id) : (leerLS('visita.banco') || ''),
                referencia: '', foto: null, fotoUrl: null, celular: this.cliente.celular || '', nota: '',
                token: (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random()),
            };
        },
        cerrarCobro() {
            const huboResultado = !!this.resultado;
            this.cobro = null; this.resultado = null;
            if (huboResultado) this.abrirCliente(this.cliente.cedula);
        },

        previa() {
            const r = this.cobro.k.resumen, pago = this.cobro.efectivo + this.cobro.transferencia;
            if (!r.facturable) return { clase: 'aviso ambar', texto: pago ? 'Queda como anticipo: ' + this.plata(pago) + '.' : 'Escribe cuánto recibiste. Quedará como anticipo.' };
            if (r.falta === 0 && !pago) return { clase: 'aviso verde', texto: 'Ya tiene cubierto ' + r.periodo + ' con lo que había dado: se factura sin recibir plata.' };
            if (!pago) return { clase: 'aviso azul', texto: 'Escribe cuánto recibiste en efectivo o transferencia.' };
            if (pago < r.falta) return { clase: 'aviso ambar', texto: 'No alcanza para ' + r.periodo + ' (faltan ' + this.plata(r.falta - pago) + '). Queda como anticipo.' };
            const sobra = pago - r.falta;
            if (!sobra) return { clase: 'aviso verde', texto: 'Se factura ' + r.periodo + ' completo.' };
            const sobraTransfer = Math.max(0, this.cobro.transferencia - r.falta);
            return { clase: 'aviso verde', texto: 'Se factura ' + r.periodo + '. Sobran ' + this.plata(sobra) + (sobraTransfer
                ? ': ' + this.plata(sobraTransfer) + ' de la transferencia quedan como saldo a favor' + (sobra - sobraTransfer ? ' y ' + this.plata(sobra - sobraTransfer) + ' de efectivo como anticipo.' : '.')
                : ' que quedan como anticipo.') };
        },
        puedeGuardar() {
            const c = this.cobro, pago = c.efectivo + c.transferencia;
            if (c.transferencia > 0 && !c.banco) return false;
            return pago > 0 || (c.k.resumen.facturable && c.k.resumen.falta === 0);
        },

        async tomarFoto(ev) {
            const f = ev.target.files && ev.target.files[0];
            if (!f) return;
            this.cobro.foto = await this.comprimir(f);
            this.cobro.fotoUrl = URL_obj(this.cobro.foto);
        },
        // Las fotos del celular pesan 4-8 MB: con mala señal no suben. Se
        // reducen a 1600 px en JPEG antes de mandarlas.
        async comprimir(archivo) {
            if (!archivo.type.startsWith('image/') || archivo.size < 600000) return archivo;
            try {
                const img = await createImageBitmap(archivo);
                const escala = Math.min(1, 1600 / Math.max(img.width, img.height));
                const lienzo = document.createElement('canvas');
                lienzo.width = Math.round(img.width * escala); lienzo.height = Math.round(img.height * escala);
                lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);
                const blob = await new Promise(ok => lienzo.toBlob(ok, 'image/jpeg', 0.82));
                return blob ? new File([blob], 'comprobante.jpg', { type: 'image/jpeg' }) : archivo;
            } catch (_) { return archivo; }
        },

        async guardar() {
            const c = this.cobro;
            this.guardando = true; this.error = '';
            if (c.banco) recordar('visita.banco', c.banco);
            const fd = new FormData();
            fd.append('contrato_id', c.k.id);
            fd.append('efectivo', c.efectivo);
            fd.append('transferencia', c.transferencia);
            if (c.transferencia > 0) { fd.append('banco_cuenta_id', c.banco); fd.append('referencia', c.referencia); }
            if (c.transferencia > 0 && c.foto) fd.append('foto', c.foto);
            fd.append('celular', c.celular);
            fd.append('nota', [this.empresaId ? this.empresaTexto : '', c.nota].filter(Boolean).join(' · '));
            fd.append('token', c.token);
            try {
                const d = await this.pedir(URL.cobrar, { method: 'POST', body: fd });
                this.resultado = d;
                this.hoy = d.hoy;
                if (d.celular) this.cliente.celular = d.celular;
                const i = this.contratos.findIndex(x => x.id === d.contrato.id);
                if (i >= 0) this.contratos[i] = d.contrato;
                if (c.celular || d.celular) this.enviarRecibo();
                else this.envio = { texto: 'El cliente no tiene celular: escribe uno para mandarle el recibo, o usa Compartir.', clase: 'ambar', enviando: false, ok: false, intentado: false };
            } catch (e) {
                this.error = e.message || 'No se pudo registrar el cobro.';
            } finally { this.guardando = false; }
        },

        async enviarRecibo() {
            const celular = this.cobro.celular || this.resultado.celular;
            if (!celular) { this.envio.texto = 'Escribe el celular en el formulario para enviar el recibo.'; this.envio.clase = 'ambar'; return; }
            this.envio = { texto: 'Enviando recibo por WhatsApp…', clase: 'azul', enviando: true, ok: false, intentado: true };
            try {
                const d = await this.pedir(URL.enviar, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ factura_id: this.resultado.factura_id, anticipo_ids: this.resultado.anticipo_ids, celular }) });
                this.envio = { texto: '✓ ' + d.mensaje, clase: 'verde', enviando: false, ok: true, intentado: true };
            } catch (e) {
                this.envio = { texto: e.message, clase: 'rojo', enviando: false, ok: false, intentado: true };
            }
        },

        async reenviarAnticipo(a) {
            const celular = this.cliente.celular;
            if (!celular) { alert('El cliente no tiene celular en la ficha.'); return; }
            if (!confirm('¿Enviar el recibo ANT-' + a.id + ' al WhatsApp ' + celular + '?')) return;
            a.enviando = true;
            try {
                await this.pedir(URL.enviar, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ anticipo_ids: [a.id], celular }) });
                a.enviado = true;
            } catch (e) { alert(e.message); }
            finally { a.enviando = false; }
        },

        async compartir() {
            try {
                const r = await fetch(this.resultado.recibo_url, { credentials: 'same-origin' });
                const blob = await r.blob();
                const archivo = new File([blob], 'recibo.pdf', { type: 'application/pdf' });
                if (navigator.canShare && navigator.canShare({ files: [archivo] })) {
                    await navigator.share({ files: [archivo], title: 'Recibo de pago' });
                } else {
                    window.open(this.resultado.recibo_url, '_blank');
                }
            } catch (e) { if (e.name !== 'AbortError') window.open(this.resultado.recibo_url, '_blank'); }
        },

        siguiente() {
            this.cobro = null; this.resultado = null; this.cliente = null; this.contratos = [];
            this.texto = '';
            this.buscar();
            this.$nextTick(() => this.$refs.buscar.focus());
        },
    };
}
function URL_obj(f) { return window.URL.createObjectURL(f); }
</script>
</body>
</html>
