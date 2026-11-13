@extends('layouts.app')
@section('modulo', 'Validador de cédulas')
@section('contenido')

<div style="max-width:920px;margin:0 auto;" x-data="validadorCedula()">

    {{-- Cabecera --}}
    <div style="margin-bottom:1.1rem;">
        <h1 style="font-size:1.4rem;font-weight:700;color:#0a1628;margin:0;">Validador de cédulas</h1>
        <p style="color:#64748b;font-size:0.9rem;margin:.35rem 0 0;">
            Comprueba si la UGPP deja usar el subtipo <b>03</b>, <b>04</b> o <b>09</b> y recomienda el plan.
            Es una consulta a Simple con freno: <b>no liquida ni paga nada</b>. El gate es por cédula, el nombre no influye.
        </p>
    </div>

    {{-- Toggle de modo --}}
    <div style="display:inline-flex;background:#e2e8f0;border-radius:9px;padding:3px;margin-bottom:1rem;">
        <button @click="modo='una'" :style="tabEstilo('una')">Una cédula</button>
        <button @click="modo='lote'" :style="tabEstilo('lote')">Lote</button>
    </div>

    {{-- ───────────── MODO UNA ───────────── --}}
    <div x-show="modo==='una'">
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:1.25rem;margin-bottom:1.25rem;">
            <form @submit.prevent="verificarUna()">
                <div style="display:grid;grid-template-columns:120px 1fr;gap:.75rem;margin-bottom:.75rem;">
                    <div>
                        <label style="font-size:.72rem;font-weight:600;color:#475569;">Tipo doc.</label>
                        <select x-model="form.tipo_doc" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                            <template x-for="t in tipos" :key="t"><option :value="t" x-text="t"></option></template>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.72rem;font-weight:600;color:#475569;">Número de documento</label>
                        <input x-model="form.cedula" type="text" inputmode="numeric" placeholder="Ej. 1152467496"
                            style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                    </div>
                </div>
                <p style="font-size:.7rem;color:#94a3b8;margin:.25rem 0 .5rem;">Nombre opcional (se autocompleta si hay ficha; no cambia el resultado):</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
                    <input x-model="form.primer_nombre" type="text" placeholder="Primer nombre" style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                    <input x-model="form.segundo_nombre" type="text" placeholder="Segundo nombre (opcional)" style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                    <input x-model="form.primer_apellido" type="text" placeholder="Primer apellido" style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                    <input x-model="form.segundo_apellido" type="text" placeholder="Segundo apellido (opcional)" style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                </div>
                <button type="submit" :disabled="cargando"
                    style="background:#2563eb;color:#fff;border:none;padding:.55rem 1.4rem;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">
                    <span x-show="!cargando">Verificar</span>
                    <span x-show="cargando" x-cloak>Consultando a Simple…</span>
                </button>
            </form>
            <p x-show="error" x-cloak x-text="error" style="color:#991b1b;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:.6rem .8rem;margin-top:.9rem;font-size:.82rem;"></p>
        </div>

        <template x-if="res && res.ok">
            <div>
                <div x-cloak :style="`border-radius:12px;padding:1rem 1.25rem;margin-bottom:1rem;border:1px solid ${bordeColor(res.recomendacion.color)};background:${fondoColor(res.recomendacion.color)};`">
                    <div style="font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.7;">Recomendación</div>
                    <div style="font-size:1.1rem;font-weight:700;margin:.15rem 0;" x-text="res.recomendacion.titulo"></div>
                    <div style="font-size:.85rem;line-height:1.4;" x-text="res.recomendacion.detalle"></div>
                </div>
                <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                        <thead><tr style="background:#f8fafc;color:#475569;text-align:left;">
                            <th style="padding:.6rem 1.25rem;">Subtipo</th><th style="padding:.6rem;">Significado</th>
                            <th style="padding:.6rem;">Resultado</th><th style="padding:.6rem 1.25rem;">Códigos</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="st in ['03','04','09']" :key="st">
                                <tr style="border-top:1px solid #f1f5f9;">
                                    <td style="padding:.6rem 1.25rem;font-weight:700;" x-text="st"></td>
                                    <td style="padding:.6rem;color:#64748b;" x-text="significado(st)"></td>
                                    <td style="padding:.6rem;"><span :style="`font-weight:700;color:${res.subtipos[st].pasa?'#16a34a':'#dc2626'};`" x-text="res.subtipos[st].pasa?'✓ PASA':'✕ No pasa'"></span></td>
                                    <td style="padding:.6rem 1.25rem;font-family:monospace;font-size:.75rem;color:#64748b;" x-text="(res.subtipos[st].errores||[]).join('  ')||'—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

    {{-- ───────────── MODO LOTE ───────────── --}}
    <div x-show="modo==='lote'" x-cloak>
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:1.25rem;margin-bottom:1rem;">
            <label style="font-size:.72rem;font-weight:600;color:#475569;">Pega las cédulas (una por línea; opcional "CE 123")</label>
            <textarea x-model="lote.texto" rows="7" placeholder="1152467496&#10;1144081525&#10;CE 987654321&#10;…"
                style="width:100%;padding:.6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:monospace;margin:.35rem 0 .75rem;"></textarea>
            <div style="display:flex;align-items:center;gap:1rem;">
                <button @click="validarLote()" :disabled="lote.corriendo"
                    style="background:#2563eb;color:#fff;border:none;padding:.55rem 1.4rem;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">
                    <span x-show="!lote.corriendo">Validar lote</span>
                    <span x-show="lote.corriendo" x-cloak x-text="`Validando ${lote.hechas}/${lote.total}…`"></span>
                </button>
                <span x-show="lote.filas.length && !lote.corriendo" x-cloak style="font-size:.78rem;color:#64748b;"
                    x-text="`${lote.filas.filter(f=>f.res&&f.res.recomendacion&&f.res.recomendacion.plan==='X1').length} califican X1 · ${lote.filas.length} en total`"></span>
            </div>
            <p x-show="error" x-cloak x-text="error" style="color:#991b1b;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:.6rem .8rem;margin-top:.8rem;font-size:.82rem;"></p>
        </div>

        <div x-show="lote.filas.length" x-cloak style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
                <thead><tr style="background:#f8fafc;color:#475569;text-align:left;">
                    <th style="padding:.55rem 1rem;">#</th><th style="padding:.55rem;">Cédula</th>
                    <th style="padding:.55rem;text-align:center;">03</th><th style="padding:.55rem;text-align:center;">04</th><th style="padding:.55rem;text-align:center;">09</th>
                    <th style="padding:.55rem 1rem;">Plan recomendado</th>
                </tr></thead>
                <tbody>
                    <template x-for="(f,i) in lote.filas" :key="i">
                        <tr style="border-top:1px solid #f1f5f9;">
                            <td style="padding:.5rem 1rem;color:#94a3b8;" x-text="i+1"></td>
                            <td style="padding:.5rem;font-family:monospace;" x-text="`${f.tipo_doc} ${f.cedula}`"></td>
                            <template x-for="st in ['03','04','09']" :key="st">
                                <td style="padding:.5rem;text-align:center;" x-html="celda(f,st)"></td>
                            </template>
                            <td style="padding:.5rem 1rem;" x-html="celdaPlan(f)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p style="font-size:.7rem;color:#94a3b8;margin:.6rem .25rem 0;">Se validan en orden, una tras otra (el operador admite una sola sesión). El <code>2.655</code> cierra 03/04; el <code>2.506</code> cierra 09.</p>
    </div>
</div>

<script>
function validadorCedula() {
    return {
        modo:'una', tipos:['CC','CE','PT','PP','PE','PA','TI'],
        form:{ tipo_doc:'CC', cedula:'', primer_nombre:'', segundo_nombre:'', primer_apellido:'', segundo_apellido:'' },
        cargando:false, error:'', res:null,
        lote:{ texto:'', filas:[], corriendo:false, hechas:0, total:0 },

        tabEstilo(m){ const on=this.modo===m; return `border:none;padding:.4rem 1.1rem;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer;background:${on?'#fff':'transparent'};color:${on?'#1e293b':'#64748b'};box-shadow:${on?'0 1px 3px rgba(0,0,0,.1)':'none'};`; },
        significado(st){ return {'03':'Exento por edad (H 55+, M 50+)','04':'Exento por requisitos','09':'Pensionado real'}[st]; },
        bordeColor(c){ return {ok:'rgba(34,197,94,.35)',info:'rgba(59,130,246,.35)',warn:'rgba(234,179,8,.4)'}[c]||'#e2e8f0'; },
        fondoColor(c){ return {ok:'rgba(34,197,94,.1)',info:'rgba(59,130,246,.1)',warn:'rgba(234,179,8,.12)'}[c]||'#f8fafc'; },

        async pedir(payload){
            const r = await fetch('{{ route('brynex.validador.verificar') }}', {
                method:'POST',
                headers:{ 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify(payload),
            });
            return r.json();
        },

        async verificarUna(){
            this.error=''; this.res=null;
            if(!this.form.cedula.trim()){ this.error='Escribe el número de documento.'; return; }
            this.cargando=true;
            try{ const d=await this.pedir(this.form); if(d.ok===false){ this.error=d.error||'No se pudo verificar.'; } else { this.res=d; } }
            catch(e){ this.error='Error de red: '+e.message; }
            finally{ this.cargando=false; }
        },

        parseLote(){
            return this.lote.texto.split(/[\n,;]+/).map(s=>s.trim()).filter(Boolean).map(l=>{
                const m=l.match(/^([A-Za-z]{2})?\s*(\d{4,})/);
                return m ? { tipo_doc:(m[1]||'CC').toUpperCase(), cedula:m[2] } : null;
            }).filter(Boolean);
        },

        async validarLote(){
            this.error='';
            const items=this.parseLote();
            if(!items.length){ this.error='Pega al menos una cédula (mínimo 4 dígitos).'; return; }
            this.lote.filas=items.map(it=>({ ...it, estado:'en cola', res:null }));
            this.lote.total=items.length; this.lote.hechas=0; this.lote.corriendo=true;
            for(const f of this.lote.filas){
                f.estado='validando';
                try{ const d=await this.pedir({tipo_doc:f.tipo_doc,cedula:f.cedula});
                    if(d.ok){ f.res=d; f.estado='ok'; } else { f.estado='error'; f.msg=d.error||'error'; } }
                catch(e){ f.estado='error'; f.msg=e.message; }
                this.lote.hechas++;
            }
            this.lote.corriendo=false;
        },

        celda(f,st){
            if(f.estado==='en cola') return '<span style="color:#cbd5e1;">·</span>';
            if(f.estado==='validando') return '<span style="color:#94a3b8;">…</span>';
            if(f.estado==='error') return '<span style="color:#dc2626;">!</span>';
            const p=f.res&&f.res.subtipos&&f.res.subtipos[st]&&f.res.subtipos[st].pasa;
            return p ? '<span style="color:#16a34a;font-weight:700;">✓</span>' : '<span style="color:#dc2626;">✕</span>';
        },
        celdaPlan(f){
            if(f.estado==='en cola') return '<span style="color:#94a3b8;">en cola</span>';
            if(f.estado==='validando') return '<span style="color:#2563eb;">validando…</span>';
            if(f.estado==='error') return '<span style="color:#dc2626;">'+(f.msg||'error')+'</span>';
            const rec=f.res.recomendacion, map={X1:['#16a34a','rgba(34,197,94,.12)','X1 — Salud sin pensión'],PENSIONADO:['#2563eb','rgba(59,130,246,.12)','Pensionado (09)'],INGRESO_RETIRO:['#b45309','rgba(234,179,8,.14)','Ingreso-Retiro']};
            const m=map[rec.plan]||['#475569','#f1f5f9',rec.plan];
            return '<span style="color:'+m[0]+';background:'+m[1]+';border-radius:999px;padding:.15rem .6rem;font-weight:600;font-size:.76rem;">'+m[2]+'</span>';
        },
    };
}
</script>
@endsection
