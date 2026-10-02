{{-- Componente Alpine del cotizador de prospectos. Lee window.CZ_COTIZADOR (ver _form). --}}
<script>
    function cotizadorProspecto() {
        const cfg = window.CZ_COTIZADOR;
        const vacio = () => ({ eps: 0, pen: 0, arl: 0, caja: 0, admon: 0, seguro: 0, iva: 0, total: 0 });

        return {
            tipoDoc: cfg.tipoDoc,
            celular: cfg.celular,
            salario: cfg.salario,
            costoAfiliacion: cfg.costoAfiliacion,
            administracion: cfg.administracion,
            esIndependiente: cfg.esIndependiente,
            nivelArl: cfg.nivelArl,
            modalidadId: '',
            planId: '',
            fechaIngreso: cfg.fechaIngreso,
            diasProporcionales: 30,

            cargando: false,
            guardando: false,
            error: false,
            calculo: null,   // promesa del cálculo en curso
            turno: 0,        // descarta respuestas de cálculos viejos

            pctEps: 0, pctPen: 0, pctArl: 0, pctCaja: 0,
            resultProp: vacio(),
            resultFull: vacio(),

            get modalidadesFiltradas() {
                // Una modalidad sin planes permitidos no se puede cotizar: no se ofrece.
                const indep = this.esIndependiente === '1';
                return cfg.modalidades.filter(m =>
                    m.independiente === indep && (cfg.planesPorModalidad[m.id] || []).length > 0
                );
            },

            get planesFiltrados() {
                if (!this.modalidadId) return [];
                const permitidos = (cfg.planesPorModalidad[this.modalidadId] || []).map(String);
                return cfg.planes.filter(p => permitidos.includes(String(p.id)));
            },

            get hayProporcional() { return this.diasProporcionales < 30; },
            get celularLimpio() { return (this.celular || '').replace(/\D/g, ''); },
            get celularValido() { return this.celularLimpio.length >= 10; },

            init() {
                this.calcularDias();

                // El perfil manda sobre qué modalidades se listan: si lo guardado no
                // coincide con la modalidad, gana la modalidad para que no se pierda.
                const mod = cfg.modalidades.find(m => String(m.id) === cfg.modalidadId);
                if (mod) this.esIndependiente = mod.independiente ? '1' : '0';

                // Las opciones de modalidad y plan las pinta Alpine: se asigna el valor
                // cuando ya existen, primero la modalidad y luego el plan que depende de ella.
                this.$nextTick(() => {
                    this.modalidadId = cfg.modalidadId;
                    this.$nextTick(() => {
                        this.planId = cfg.planId;

                        // Solo sirve lo guardado si trae el desglose; lo que deja el
                        // asistente de IA es apenas el valor mensual, y ahí se recalcula.
                        const g = cfg.guardado;
                        if (g && g.completo && g.completo.total !== undefined) {
                            this.diasProporcionales = g.dias_proporcionales || 30;
                            this.resultFull = { ...vacio(), ...g.completo };
                            this.resultProp = { ...vacio(), ...(g.proporcional || g.completo) };
                            this.tomarPorcentajes(g.completo.pctEps !== undefined ? g.completo : (g.proporcional || {}));
                        } else {
                            this.recalcular();
                        }
                    });
                });
            },

            fmt(v) { return '$' + this.miles(v); },
            miles(v) { return Math.round(v || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
            aNumero(texto) { return parseInt(String(texto).replace(/\D/g, '') || 0, 10); },

            tomarPorcentajes(r) {
                this.pctEps = r.pctEps || 0;
                this.pctPen = r.pctPen || 0;
                this.pctArl = r.pctArl || 0;
                this.pctCaja = r.pctCaja || 0;
            },

            limpiarResultado() {
                this.resultProp = vacio();
                this.resultFull = vacio();
                this.tomarPorcentajes({});
            },

            calcularDias() {
                if (this.modalidadId === '12') {
                    // Ingreso Retiro: forzar fecha 26 del mes
                    const d = this.fechaIngreso ? new Date(this.fechaIngreso + 'T00:00:00') : new Date();
                    const mes = String(d.getMonth() + 1).padStart(2, '0');
                    this.fechaIngreso = `${d.getFullYear()}-${mes}-26`;
                }

                if (!this.fechaIngreso || this.modalidadId === '-1') {
                    // Sin fecha, o Estudiante K: siempre 30 días, no proporcional
                    this.diasProporcionales = 30;
                    return;
                }

                const dia = new Date(this.fechaIngreso + 'T00:00:00').getDate();
                this.diasProporcionales = Math.max(1, 30 - dia + 1);
            },

            onPerfilChange() {
                this.modalidadId = '';
                this.planId = '';
                this.limpiarResultado();
            },

            onModalidadChange() {
                const permitidos = (cfg.planesPorModalidad[this.modalidadId] || []).map(String);
                if (this.planId && !permitidos.includes(String(this.planId))) {
                    this.planId = '';
                    this.limpiarResultado();
                }
                this.calcularDias();
                this.recalcular();
            },

            recalcular() {
                this.calculo = this.cotizar();
                return this.calculo;
            },

            async pedir(dias) {
                const r = await fetch(cfg.urlCotizar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        tipo_modalidad_id: this.modalidadId,
                        plan_id: this.planId,
                        salario: this.salario,
                        ibc: this.salario, // el backend calcula el IBC real
                        n_arl: this.nivelArl,
                        administracion: this.administracion,
                        dias,
                    }),
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            },

            async cotizar() {
                if (!this.modalidadId || !this.planId || !this.salario) return;

                const turno = ++this.turno;
                this.cargando = true;
                this.error = false;

                try {
                    const dias = this.diasProporcionales;
                    const [completo, proporcional] = await Promise.all([
                        this.pedir(30),
                        dias < 30 ? this.pedir(dias) : null,
                    ]);
                    if (turno !== this.turno) return; // ya hay un cálculo más nuevo

                    this.resultFull = completo;
                    this.resultProp = proporcional || { ...completo };
                    this.tomarPorcentajes(completo);

                    if (this.modalidadId === '15') {
                        // Gestión ARL: no trae planilla; el total es afiliación + administración
                        const admon = this.administracion || 0;
                        this.resultFull = { ...vacio(), admon, total: (this.costoAfiliacion || 0) + admon };
                        this.resultProp = { ...this.resultFull };
                    }

                    if (this.modalidadId === '12') {
                        // Ingreso Retiro: no existe el mes completo, solo vale el proporcional
                        this.resultFull = vacio();
                    }
                } catch (e) {
                    if (turno !== this.turno) return;
                    console.error('Error cotizando:', e);
                    this.error = true;
                } finally {
                    if (turno === this.turno) this.cargando = false;
                }
            },

            async guardar() {
                if (this.guardando) return;
                this.guardando = true;

                // Si se cambió un valor y se pulsó Guardar de una, el cálculo todavía
                // va en camino: se espera para no guardar el resultado anterior.
                if (this.calculo) await this.calculo;

                this.$refs.resultado.value = JSON.stringify({
                    dias_proporcionales: this.diasProporcionales,
                    proporcional: this.resultProp,
                    completo: this.resultFull,
                    costo_afiliacion: this.costoAfiliacion,
                    administracion: this.administracion,
                });
                this.$refs.resultado.form.submit();
            },
        };
    }
</script>
