{{-- Componente Alpine del cotizador de prospectos. Lee window.CZ_COTIZADOR (ver _form). --}}
<script>
    function cotizadorProspecto() {
        const cfg = window.CZ_COTIZADOR;
        const vacio = () => ({ eps: 0, pen: 0, arl: 0, caja: 0, admon: 0, seguro: 0, iva: 0, total: 0 });

        let secuencia = 0;
        const nuevoTrabajador = (t = {}) => ({
            key: ++secuencia,
            cargo: t.cargo || '',
            nombre: t.nombre || '',
            modalidadId: t.modalidadId || '',
            planId: t.planId || '',
            salario: t.salario || cfg.salario,
            nivelArl: t.nivelArl || '1',
            completo: { ...vacio(), ...(t.completo || {}) },
            proporcional: { ...vacio(), ...(t.proporcional || t.completo || {}) },
            cargando: false,
            error: false,
            turno: 0,
        });

        return {
            tipo: cfg.tipo === 'empresa' ? 'empresa' : 'persona',
            trabajadores: (cfg.trabajadores || []).map(nuevoTrabajador),
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

            get modalidadesEmpresa() {
                return cfg.modalidades.filter(m => !m.independiente && (cfg.planesPorModalidad[m.id] || []).length > 0);
            },

            planesDe(modalidadId) {
                if (!modalidadId) return [];
                const permitidos = (cfg.planesPorModalidad[modalidadId] || []).map(String);
                return cfg.planes.filter(p => permitidos.includes(String(p.id)));
            },

            nombrePlan(planId) {
                const p = cfg.planes.find(p => String(p.id) === String(planId));
                return p ? p.nombre : '';
            },

            get hayProporcional() { return this.diasProporcionales < 30; },
            get hayCotizacion() {
                return this.tipo === 'empresa' ? this.trabajadores.some(t => t.planId) : !!this.planId;
            },
            get totalAfiliacion() {
                return (this.costoAfiliacion || 0) * (this.tipo === 'empresa' ? this.trabajadores.length : 1);
            },
            get celularLimpio() { return (this.celular || '').replace(/\D/g, ''); },
            get celularValido() { return this.celularLimpio.length >= 10; },

            init() {
                this.calcularDias();
                if (this.tipo === 'empresa' && this.trabajadores.length === 0) this.agregarTrabajador();

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
                        if (this.tipo === 'empresa') {
                            this.sumarTrabajadores();
                        } else if (g && g.completo && g.completo.total !== undefined) {
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

            cambiarTipo(tipo) {
                if (this.tipo === tipo) return;
                this.tipo = tipo;
                if (tipo === 'empresa') {
                    if (this.trabajadores.length === 0) this.agregarTrabajador();
                    this.sumarTrabajadores();
                } else {
                    this.recalcular();
                }
            },

            agregarTrabajador() {
                const anterior = this.trabajadores[this.trabajadores.length - 1];
                this.trabajadores.push(nuevoTrabajador(anterior ? {
                    modalidadId: anterior.modalidadId, planId: anterior.planId,
                    salario: anterior.salario, nivelArl: anterior.nivelArl,
                    completo: anterior.completo, proporcional: anterior.proporcional,
                } : {}));
                this.sumarTrabajadores();
            },

            quitarTrabajador(i) {
                if (this.trabajadores.length <= 1) return;
                this.trabajadores.splice(i, 1);
                this.sumarTrabajadores();
            },

            onModalidadTrabajador(t) {
                const permitidos = (cfg.planesPorModalidad[t.modalidadId] || []).map(String);
                if (t.planId && !permitidos.includes(String(t.planId))) t.planId = '';
                this.cotizarTrabajador(t);
            },

            cotizarTrabajador(t) {
                this.calculo = this.cotizarUno(t);
                return this.calculo;
            },

            async cotizarUno(t) {
                if (!t.modalidadId || !t.planId || !t.salario) {
                    t.completo = vacio(); t.proporcional = vacio();
                    this.sumarTrabajadores();
                    return;
                }
                const turno = ++t.turno;
                t.cargando = true; t.error = false;
                try {
                    const dias = this.diasProporcionales;
                    const [completo, proporcional] = await Promise.all([
                        this.pedir(30, t),
                        dias < 30 ? this.pedir(dias, t) : null,
                    ]);
                    if (turno !== t.turno) return;
                    t.completo = completo;
                    t.proporcional = proporcional || { ...completo };
                    this.sumarTrabajadores();
                } catch (e) {
                    if (turno !== t.turno) return;
                    console.error('Error cotizando trabajador:', e);
                    t.error = true;
                } finally {
                    if (turno === t.turno) t.cargando = false;
                }
            },

            // El resumen de la empresa es la suma de sus trabajadores, concepto por concepto.
            sumarTrabajadores() {
                const suma = (campo) => {
                    const total = vacio();
                    this.trabajadores.forEach(t => {
                        Object.keys(total).forEach(k => { total[k] += Number(t[campo]?.[k] || 0); });
                    });
                    return total;
                };
                this.resultFull = suma('completo');
                this.resultProp = suma('proporcional');
                this.error = this.trabajadores.some(t => t.error);
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
                if (this.tipo === 'empresa') {
                    this.calculo = Promise.all(this.trabajadores.map(t => this.cotizarUno(t)));
                } else {
                    this.calculo = this.cotizar();
                }
                return this.calculo;
            },

            async pedir(dias, t = null) {
                const r = await fetch(cfg.urlCotizar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        tipo_modalidad_id: t ? t.modalidadId : this.modalidadId,
                        plan_id: t ? t.planId : this.planId,
                        salario: t ? t.salario : this.salario,
                        ibc: t ? t.salario : this.salario, // el backend calcula el IBC real
                        n_arl: t ? t.nivelArl : this.nivelArl,
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
                    tipo: this.tipo,
                    dias_proporcionales: this.diasProporcionales,
                    proporcional: this.resultProp,
                    completo: this.resultFull,
                    costo_afiliacion: this.costoAfiliacion,
                    administracion: this.administracion,
                    trabajadores: this.tipo === 'empresa' ? this.trabajadores.length : 1,
                });
                this.$refs.trabajadores.value = this.tipo === 'empresa' ? JSON.stringify(this.trabajadores.map(t => ({
                    cargo: t.cargo, nombre: t.nombre,
                    modalidad_id: t.modalidadId, plan_id: t.planId,
                    salario: t.salario, n_arl: t.nivelArl,
                    completo: t.completo, proporcional: t.proporcional,
                }))) : '';
                this.$refs.resultado.form.submit();
            },
        };
    }
</script>
