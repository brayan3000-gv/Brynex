{{--
    Campo «Entidad» de los formularios de claves: con un tipo que tiene
    catálogo (EPS, ARL, caja, pensión, operador, correo) se elige de una lista
    en vez de escribirlo, para que no quede «Sura ARL», «EPS» o «SAT» y la
    clave se ligue sola a su entidad. Portal, DIAN, banco y otro siguen con
    texto libre, y cada lista tiene «Otra…» para escribirla.

    El campo de texto original sigue siendo el que lleva el valor (los
    formularios lo leen igual que antes); la lista solo lo llena.

        EntidadClave.mejorar('glb-ca-f-tipo', 'glb-ca-f-entidad');
        EntidadClave.refrescar('glb-ca-f-entidad');   // después de llenar el formulario
--}}
@once
<script>
window.EntidadClave = (function () {
    const OPCIONES = @json(\App\Services\Afiliaciones\PortalesEntidades::opcionesFormulario());
    const OTRA = '__otra__';
    const llave = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    const campos = {};

    function mejorar(tipoId, entidadId) {
        const tipo = document.getElementById(tipoId);
        const input = document.getElementById(entidadId);
        if (!tipo || !input || campos[entidadId]) return;

        const lista = document.createElement('select');
        lista.className = input.className;
        lista.style.display = 'none';
        input.insertAdjacentElement('beforebegin', lista);

        lista.addEventListener('change', () => {
            if (lista.value === OTRA) {
                input.value = '';
                input.style.display = '';
                input.focus();
            } else {
                input.value = lista.value;
                input.style.display = 'none';
            }
        });
        tipo.addEventListener('change', () => { input.value = ''; pintar(entidadId); });

        campos[entidadId] = { tipo, input, lista };
        pintar(entidadId);
    }

    function pintar(entidadId) {
        const { tipo, input, lista } = campos[entidadId];
        const opciones = OPCIONES[tipo.value];

        if (!opciones) {
            lista.style.display = 'none';
            input.style.display = '';
            return;
        }

        const grupos = {};
        opciones.forEach(o => (grupos[o.grupo || ''] ??= []).push(o));
        const html = Object.entries(grupos).map(([g, os]) => {
            const opts = os.map(o => `<option value="${o.valor.replace(/"/g, '&quot;')}">${o.etiqueta.replace(/</g, '&lt;')}</option>`).join('');
            return g ? `<optgroup label="${g}">${opts}</optgroup>` : opts;
        }).join('');
        lista.innerHTML = '<option value="">— Elige —</option>' + html + `<option value="${OTRA}">✏️ Otra (escribirla)…</option>`;

        // Lo que ya tenía el campo: se busca en la lista por nombre o etiqueta.
        const actual = llave(input.value);
        const hallada = actual && opciones.find(o => llave(o.valor) === actual || llave(o.etiqueta) === actual || (o.alias || []).includes(actual));
        if (hallada) {
            lista.value = hallada.valor;
            input.value = hallada.valor;
            input.style.display = 'none';
        } else if (actual) {
            lista.value = OTRA;          // un nombre viejo que no está en la lista: queda a la vista
            input.style.display = '';
        } else {
            lista.value = '';
            input.style.display = 'none';
        }
        lista.style.display = '';
    }

    function refrescar(entidadId) {
        if (campos[entidadId]) pintar(entidadId);
    }

    return { mejorar, refrescar };
})();
</script>
@endonce
