@extends('layouts.app')
@section('modulo', 'Validador de cédulas')
@section('contenido')

<div style="max-width:880px;margin:0 auto;" x-data="validadorCedula()">

    {{-- Cabecera --}}
    <div style="margin-bottom:1.25rem;">
        <h1 style="font-size:1.4rem;font-weight:700;color:#0a1628;margin:0;">Validador de cédulas</h1>
        <p style="color:#64748b;font-size:0.9rem;margin:.35rem 0 0;">
            Comprueba si la UGPP deja a la persona usar el subtipo <b>03</b>, <b>04</b> o <b>09</b>
            y recomienda el plan. Es una consulta a Simple con freno: <b>no liquida ni paga nada</b>.
        </p>
    </div>

    {{-- Formulario --}}
    <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:1.25rem;margin-bottom:1.25rem;">
        <form @submit.prevent="verificar()">
            <div style="display:grid;grid-template-columns:120px 1fr;gap:.75rem;margin-bottom:.75rem;">
                <div>
                    <label style="font-size:.72rem;font-weight:600;color:#475569;">Tipo doc.</label>
                    <select x-model="form.tipo_doc"
                        style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                        <template x-for="t in ['CC','CE','PT','PP','PE','PA','TI']" :key="t">
                            <option :value="t" x-text="t"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:600;color:#475569;">Número de documento</label>
                    <input x-model="form.cedula" type="text" inputmode="numeric" placeholder="Ej. 1152467496"
                        style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                </div>
            </div>

            <p style="font-size:.7rem;color:#94a3b8;margin:.25rem 0 .5rem;">
                Si la cédula ya tiene ficha, el nombre se completa solo. Si es un prospecto, escríbelo:
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
                <input x-model="form.primer_nombre" type="text" placeholder="Primer nombre"
                    style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                <input x-model="form.segundo_nombre" type="text" placeholder="Segundo nombre (opcional)"
                    style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                <input x-model="form.primer_apellido" type="text" placeholder="Primer apellido"
                    style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                <input x-model="form.segundo_apellido" type="text" placeholder="Segundo apellido (opcional)"
                    style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
            </div>

            <button type="submit" :disabled="cargando"
                style="background:#2563eb;color:#fff;border:none;padding:.55rem 1.4rem;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">
                <span x-show="!cargando">Verificar</span>
                <span x-show="cargando" x-cloak>Consultando a Simple… (03, 04, 09)</span>
            </button>
        </form>

        <p x-show="error" x-cloak x-text="error"
            style="color:#991b1b;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:.6rem .8rem;margin-top:.9rem;font-size:.82rem;"></p>
    </div>

    {{-- Resultado --}}
    <template x-if="res && res.ok">
        <div>
            {{-- Recomendación --}}
            <div x-cloak
                :style="`border-radius:12px;padding:1rem 1.25rem;margin-bottom:1rem;border:1px solid ${bannerBorde(res.recomendacion.color)};background:${bannerFondo(res.recomendacion.color)};`">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.7;">Recomendación</div>
                <div style="font-size:1.1rem;font-weight:700;margin:.15rem 0;" x-text="res.recomendacion.titulo"></div>
                <div style="font-size:.85rem;line-height:1.4;" x-text="res.recomendacion.detalle"></div>
            </div>

            {{-- Detalle por subtipo --}}
            <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
                <div style="padding:.75rem 1.25rem;border-bottom:1px solid #e2e8f0;font-size:.8rem;color:#64748b;">
                    <span x-text="`${res.persona.primer_nombre} ${res.persona.primer_apellido} · ${res.persona.tipo_doc} ${res.persona.cedula}`"></span>
                    <span style="float:right;" x-text="`${res.rs.nombre} · período ${res.periodo}`"></span>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                    <thead>
                        <tr style="background:#f8fafc;color:#475569;text-align:left;">
                            <th style="padding:.6rem 1.25rem;">Subtipo</th>
                            <th style="padding:.6rem;">Significado</th>
                            <th style="padding:.6rem;">Resultado</th>
                            <th style="padding:.6rem 1.25rem;">Códigos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="st in ['03','04','09']" :key="st">
                            <tr style="border-top:1px solid #f1f5f9;">
                                <td style="padding:.6rem 1.25rem;font-weight:700;" x-text="st"></td>
                                <td style="padding:.6rem;color:#64748b;" x-text="significado(st)"></td>
                                <td style="padding:.6rem;">
                                    <template x-if="!res.subtipos[st].portal">
                                        <span style="color:#92400e;" x-text="'portal no respondió'"></span>
                                    </template>
                                    <template x-if="res.subtipos[st].portal">
                                        <span :style="`font-weight:700;color:${res.subtipos[st].pasa ? '#16a34a' : '#dc2626'};`"
                                            x-text="res.subtipos[st].pasa ? '✓ PASA' : '✕ No pasa'"></span>
                                    </template>
                                </td>
                                <td style="padding:.6rem 1.25rem;font-family:monospace;font-size:.75rem;color:#64748b;"
                                    x-text="(res.subtipos[st].errores||[]).join('  ') || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p style="font-size:.7rem;color:#94a3b8;margin:.6rem .25rem 0;">
                El <code>eo.val.2.655</code> cierra el 03/04; el <code>eo.val.2.506</code> cierra el 09.
                El <code>2.379</code> es ruido de ADRES y no cuenta.
            </p>
        </div>
    </template>
</div>

<script>
function validadorCedula() {
    return {
        form: { tipo_doc:'CC', cedula:'', primer_nombre:'', segundo_nombre:'', primer_apellido:'', segundo_apellido:'' },
        cargando:false, error:'', res:null,

        significado(st){ return {'03':'Exento por edad (H 55+, M 50+)','04':'Exento por requisitos','09':'Pensionado real'}[st]; },
        bannerBorde(c){ return {ok:'rgba(34,197,94,.35)',info:'rgba(59,130,246,.35)',warn:'rgba(234,179,8,.4)'}[c]||'#e2e8f0'; },
        bannerFondo(c){ return {ok:'rgba(34,197,94,.1)',info:'rgba(59,130,246,.1)',warn:'rgba(234,179,8,.12)'}[c]||'#f8fafc'; },

        async verificar(){
            this.error=''; this.res=null;
            if(!this.form.cedula.trim()){ this.error='Escribe el número de documento.'; return; }
            this.cargando=true;
            try{
                const r = await fetch('{{ route('brynex.validador.verificar') }}', {
                    method:'POST',
                    headers:{ 'Content-Type':'application/json', 'Accept':'application/json',
                        'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify(this.form),
                });
                const d = await r.json();
                if(!r.ok || d.ok===false){ this.error = d.error || 'No se pudo verificar.'; return; }
                this.res = d;
            }catch(e){ this.error = 'Error de red: '+e.message; }
            finally{ this.cargando=false; }
        },
    };
}
</script>
@endsection
