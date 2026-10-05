<?php

namespace App\Services\ArlSura;

use App\Models\ArlCentroTrabajo;
use App\Models\Contrato;
use App\Models\TipoModalidad;
use App\Services\Afiliaciones\DatosAfiliacion;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Traduce un contrato de BryNex al JSON que espera `trabajador/afiliar`.
 *
 * Aquí vive todo el mapeo entre los dos modelos, que es donde está la sustancia:
 * qué tipo de afiliado y de cotizante le corresponde a cada modalidad, cómo se
 * convierte un municipio DANE al código interno de Sura, y de dónde sale cada
 * dato que el formulario exige y BryNex no guarda.
 *
 * Lo que NO se guarda en BD porque es siempre igual: zona URBANA, jornada UNICA,
 * modalidad PRESENCIAL y `tipoTeletrabajo` "A". El barrio tampoco: lo devuelve
 * el geocodificador de Sura junto con las coordenadas.
 */
class ArlSuraPayloadBuilder
{
    /** Tipos de documento: los de BryNex a la letra que usa Sura. */
    public const TIPOS_DOC = [
        'CC' => 'C', 'CE' => 'E', 'TI' => 'T', 'RC' => 'I',
        'PA' => 'P', 'PT' => 'X', 'PPT' => 'X', 'PEP' => 'Q',
        'SC' => 'S', 'CD' => 'D',
    ];

    /** Modalidades de BryNex que ante Sura son un independiente. */
    private const MODALIDADES_INDEPENDIENTE = [8, 10, 11, 13, 14];

    /** El plan que marca al trabajador de Gestión ARL como independiente. */
    private const PLAN_ARL_INDEPENDIENTE = 'SOLO_ARL_IND';

    /** La modalidad K: estudiante que solo cotiza a riesgos (Dec. 1072). */
    private const MODALIDAD_ESTUDIANTE = -1;

    private array $municipiosPorDepto = [];

    public function __construct(private ArlSuraApiService $api)
    {
    }

    /**
     * @param  Carbon  $inicioCobertura  Normalmente el día siguiente: la cobertura
     *                                   arranca después de afiliar, salvo que la
     *                                   póliza tenga habilitada la cobertura por horas.
     */
    public function paraAfiliacion(Contrato $contrato, Carbon $inicioCobertura): array
    {
        $cliente = $contrato->cliente;
        $rs      = DatosAfiliacion::deContrato($contrato);

        if (! $cliente) {
            throw new RuntimeException("El contrato {$contrato->id} no tiene cliente asociado.");
        }
        if (! $rs?->arl_poliza) {
            throw new RuntimeException("La razón social del contrato {$contrato->id} no tiene póliza ARL registrada.");
        }

        $tipoAfiliado = $this->tipoAfiliado($contrato);
        $centro       = $this->centro($contrato);

        $payload = [
            'tipoAfiliado'         => $tipoAfiliado,
            'tipoCotizante'        => $this->tipoCotizante($contrato, $tipoAfiliado),
            'subTipoCotizante'     => [
                'cdSubTipoCotizante' => '',
                'dsSubTipoCotizante' => 'NO TIENE SUBTIPO DE COTIZANTE',
            ],
            'fechaInicioCobertura' => $inicioCobertura->format('d/m/Y'),
            'afiliado'             => $this->afiliado($contrato, $cliente, $rs),
            'sitioTrabajo'         => $this->sitioTrabajo($contrato, $centro, $rs),
            'autorizacion'         => true,
            'poliza'               => $rs->arl_poliza,
        ];

        // La modalidad de trabajo va siempre: el formulario web la oculta para
        // independientes y estudiantes, pero el API la exige igual («modalidad
        // : may not be null»). El tipo de teletrabajo sí es solo del
        // dependiente.
        $payload['modalidad'] = 'PRESENCIAL';

        if ($tipoAfiliado === 'D') {
            $payload['tipoTeletrabajo'] = 'A';
        }

        // El independiente lleva su propio bloque con los datos del contrato de
        // prestación de servicios, y no lleva fecha de retiro programada: el
        // portal la borra del envío al marcar «I».
        if ($tipoAfiliado === 'I') {
            $payload['datosIndependiente'] = $this->datosIndependiente($contrato, $inicioCobertura);
            unset($payload['afiliado']['fechaRetiroProgramada']);
        }

        return $payload;
    }

    /**
     * Revisa si al contrato le falta algo para poder afiliarse, sin tocar la red.
     *
     * Sirve para el precheck de la pantalla: es preferible listarle al usuario
     * los cuatro datos que faltan de una vez, a que descubra uno por intento.
     * Las validaciones que solo el portal puede hacer —que la cédula exista, que
     * el sexo coincida— quedan fuera a propósito.
     *
     * @return array<int,string> Lista vacía cuando el contrato está listo.
     */
    public function problemas(Contrato $contrato): array
    {
        $problemas = [];
        $cliente   = $contrato->cliente;
        $rs        = DatosAfiliacion::deContrato($contrato);

        if (! $cliente) {
            return ['El contrato no tiene un cliente asociado.'];
        }

        if (! $rs?->arl_poliza) {
            $problemas[] = 'La razón social no tiene póliza ARL registrada.';
        }

        try {
            self::tipoDocumento($cliente->tipo_doc);
        } catch (RuntimeException $e) {
            $problemas[] = $e->getMessage();
        }

        foreach (['primer_nombre' => 'nombre', 'primer_apellido' => 'apellido', 'fecha_nacimiento' => 'fecha de nacimiento', 'genero' => 'sexo'] as $campo => $etiqueta) {
            if (! $cliente->$campo) {
                $problemas[] = "El cliente no tiene {$etiqueta}.";
            }
        }

        if (! $cliente->municipio_id || ! $cliente->departamento_id) {
            $problemas[] = 'El cliente no tiene municipio o departamento.';
        }

        foreach ([[$contrato->eps ?: $cliente->eps, 'EPS'], [$contrato->pension ?: $cliente->pension, 'AFP']] as [$entidad, $etiqueta]) {
            if (! $entidad) {
                $problemas[] = "No tiene {$etiqueta} asignada.";
            } elseif (! $entidad->codigo_sura) {
                $problemas[] = "La {$etiqueta} '{$entidad->razon_social}' no tiene código de Sura (corre arl:sincronizar-catalogos).";
            }
        }

        if (! ($contrato->ibc ?: $contrato->salario)) {
            $problemas[] = 'El contrato no tiene salario ni IBC.';
        }

        if (! (int) $contrato->n_arl) {
            $problemas[] = 'El contrato no tiene nivel de riesgo (n_arl).';
        } elseif (! ArlCentroTrabajo::paraRiesgo((int) $contrato->razon_social_id, (int) $contrato->n_arl)) {
            $niveles = ArlCentroTrabajo::nivelesDePoliza((string) $rs?->arl_poliza);
            $problemas[] = $niveles
                ? "La póliza {$rs->arl_poliza} no tiene centro de trabajo de riesgo {$contrato->n_arl} en Sura: solo de riesgo ".implode(', ', $niveles).'. Hay que crearlo en el portal (y luego correr arl:sincronizar-centros) o revisar el riesgo del contrato.'
                : "No hay centro de trabajo de riesgo {$contrato->n_arl} para esta razón social (corre arl:sincronizar-centros).";
        }

        try {
            $this->cargo($contrato);
        } catch (RuntimeException $e) {
            $problemas[] = $e->getMessage();
        }

        try {
            $this->direccionValida(
                $cliente->direccion_vivienda,
                $rs?->dir_formulario ?: $rs?->direccion ?: $contrato->aliado?->direccion
            );
        } catch (RuntimeException $e) {
            $problemas[] = $e->getMessage();
        }

        if (! $this->correo($contrato, $cliente, $rs)) {
            $problemas[] = 'No hay correo del cliente, ni de la razón social, ni del aliado.';
        }

        if (! $this->telefono($contrato, $cliente, $rs)) {
            $problemas[] = 'No hay teléfono del cliente, ni de la razón social, ni del aliado.';
        }

        return $problemas;
    }

    // ─── Clasificación ───────────────────────────────────────────────

    /** D dependiente · E estudiante · I independiente, según la modalidad de BryNex. */
    public function tipoAfiliado(Contrato $contrato): string
    {
        $modalidad = (int) $contrato->tipo_modalidad_id;

        if ($modalidad === self::MODALIDAD_ESTUDIANTE) {
            return 'E';
        }

        // En Gestión ARL hay de los dos tipos, así que no lo decide la
        // modalidad: lo dice el plan del contrato. El independiente se afilia
        // igual con la póliza y la credencial de la empresa —no tenemos usuario
        // del portal de cada persona—, lo único que cambia es el cotizante.
        if ($contrato->plan?->codigo === self::PLAN_ARL_INDEPENDIENTE) {
            return 'I';
        }

        // Una razón social marcada como independiente ES el propio trabajador,
        // que va por su cuenta.
        if ($contrato->razonSocial?->es_independiente) {
            return 'I';
        }

        return in_array($modalidad, self::MODALIDADES_INDEPENDIENTE, true) ? 'I' : 'D';
    }

    /**
     * Código PILA del tipo de cotizante. Sura usa los mismos que la planilla, así
     * que el 51 de tiempo parcial y el 23 de estudiante coinciden con los que
     * BryNex ya calcula para PILA.
     */
    private function tipoCotizante(Contrato $contrato, string $tipoAfiliado): array
    {
        [$codigo, $descripcion] = match ($tipoAfiliado) {
            'E'     => ['23', 'ESTUDIANTE APORTE SOLO RIESGOS LABORALES (DEC 1072 DE 2015)'],
            'I'     => ['59', 'INDEPENDIENTE CON CONTRATO DE PRESTACION DE SERVICIOS SUPERIOR A UN MES'],
            default => $contrato->tipoModalidad?->es_tiempo_parcial
                ? ['51', 'TRABAJADOR DE TIEMPO PARCIAL']
                : ['01', 'DEPENDIENTE'],
        };

        return ['cdTipoCotizante' => $codigo, 'dsTipoCotizante' => $descripcion];
    }

    /** El centro de trabajo que corresponde al nivel de riesgo del contrato. */
    private function centro(Contrato $contrato): ArlCentroTrabajo
    {
        $nivel = (int) $contrato->n_arl;

        if (! $nivel) {
            throw new RuntimeException("El contrato {$contrato->id} no tiene nivel de riesgo (n_arl).");
        }

        $centro = ArlCentroTrabajo::paraRiesgo((int) $contrato->razon_social_id, $nivel);

        if (! $centro) {
            throw new RuntimeException(
                "No hay centro de trabajo de riesgo {$nivel} para la razón social {$contrato->razon_social_id}. ".
                'Corre `arl:sincronizar-centros` o revisa que exista en el portal de Sura.'
            );
        }

        return $centro;
    }

    // ─── Bloques del payload ─────────────────────────────────────────

    /**
     * Datos del contrato de prestación de servicios del independiente.
     *
     * Sura los pide todos: sin este bloque el portal responde «Error al
     * ingresar el trabajador dependiente: 025», que no dice nada de lo que
     * falta.
     *
     * El tipo de contrato sale de un catálogo del portal —01 CIVIL, 02
     * COMERCIAL, 03 ADMINISTRATIVO— y se usa CIVIL, que es el que corresponde a
     * una prestación de servicios entre particulares.
     *
     * Los honorarios son el IBC del contrato: es lo que se está cotizando. Sin
     * fecha de fin no hay un total distinto del mensual, así que van iguales.
     */
    private function datosIndependiente(Contrato $contrato, Carbon $inicioCobertura): array
    {
        $honorarios = (int) ($contrato->ibc ?: $contrato->salario);
        $fin        = $contrato->fecha_retiro ?: null;

        return [
            'fechaInicialContrato' => ($contrato->fecha_ingreso ?: $inicioCobertura)->format('d/m/Y'),
            'fechaFinalContrato'   => $fin?->format('d/m/Y'),
            'tipoContrato'         => ['codigo' => '01', 'desTipoContrato' => 'CIVIL'],
            'valorHonorarios'      => $honorarios,
            'valorTotalHonorarios' => $honorarios,
        ];
    }

    private function afiliado(Contrato $contrato, $cliente, $rs): array
    {
        $eps = $contrato->eps ?: $cliente->eps;
        $afp = $contrato->pension ?: $cliente->pension;

        $direccion = $this->direccionValida(
            $cliente->direccion_vivienda,
            $rs->dir_formulario ?: $rs->direccion ?: $contrato->aliado?->direccion
        );
        $municipio = $this->municipio((string) $cliente->departamento_id, (string) $cliente->municipio_id);
        $geo       = $this->api->estandarizarDireccion($direccion, (string) $cliente->municipio_id);

        return [
            'tipoId'    => self::tipoDocumento($cliente->tipo_doc),
            'numDoc'    => (string) $cliente->cedula,
            'nombre1'   => $this->limpiar($cliente->primer_nombre),
            'nombre2'   => $this->limpiar($cliente->segundo_nombre),
            'apellido1' => $this->limpiar($cliente->primer_apellido),
            'apellido2' => $this->limpiar($cliente->segundo_apellido),
            'sexo'      => strtoupper(substr((string) $cliente->genero, 0, 1)) === 'F' ? 'F' : 'M',
            'fechaNacimiento' => $cliente->fecha_nacimiento?->format('d/m/Y'),

            'eps' => $this->entidad($eps, 'codigoEps', 'EPS'),
            'afp' => $this->entidad($afp, 'codigoAfp', 'AFP'),

            'salario'   => (int) round($contrato->ibc ?: $contrato->salario),
            'email'     => $this->correo($contrato, $cliente, $rs),
            'telefono1' => $this->soloDigitos($this->telefono($contrato, $cliente, $rs)),
            'telefono2' => $this->soloDigitos($cliente->telefono) ?: null,

            'direccion'     => $geo['dirtrad'] ?: $direccion,
            'cordenadasDir' => [
                'vectorx'   => (float) ($geo['longitude'] ?? 0),
                'vectory'   => (float) ($geo['latitude'] ?? 0),
                // El geocodificador devuelve el barrio real; la localidad es la
                // comuna. Preferimos el barrio, que es lo que pide el formulario.
                'localidad' => $geo['barrio'] ?: ($geo['localidad'] ?? null),
            ],
            'departamento' => [
                'nombreDepartamento' => $municipio['departamento']['nombreProvincia'] ?? null,
                'cdDepartamento'     => (string) $cliente->departamento_id,
            ],
            'municipio' => [
                'poblacion'       => $municipio['poblacion'],
                'codigoMunicipio' => $municipio['codigoMunicipio'],
                'codigoPostal'    => $municipio['codigoPostal'],
            ],
            'zona'                  => 'URBANA',
            'fechaRetiroProgramada' => null,
            'textoCarneEspecial'    => null,
            'jornada'               => 'UNICA',
            'cargo'                 => $this->cargo($contrato),
        ];
    }

    private function sitioTrabajo(Contrato $contrato, ArlCentroTrabajo $centro, $rs): array
    {
        $geo = $this->api->estandarizarDireccion($centro->direccion ?: $rs->direccion, $centro->municipio_sura);

        return [
            'centroTrabajo' => [
                'cdActividad'    => $centro->cd_actividad,
                'cdClase'        => (string) $centro->nivel_riesgo,
                'cdMunicipio'    => $centro->municipio_sura,
                'cdSucursal'     => $centro->codigo_centro,
                'direccion'      => $centro->direccion,
                'dsActividad'    => null,
                'dsDepartamento' => $centro->departamento,
                'dsMunicipio'    => $centro->municipio,
                'dsSucursal'     => $centro->nombre_centro,
                'fax'            => null,
                'poCotizacion'   => (float) $centro->tasa,
                'telefono'       => $centro->telefono,
            ],
            'actividadEconomica' => [
                'cdActividad'  => $centro->cd_actividad,
                'dsActividad'  => null,
                'cdClase'      => (string) $centro->nivel_riesgo,
                'poCotizacion' => (float) $centro->tasa,
            ],
            'telefono1'     => (int) $this->soloDigitos(
                $centro->telefono ?: $this->telefono($contrato, $contrato->cliente, $rs)
            ),
            'telefono2'     => null,
            'email'         => $this->primerValor(
                $rs->correo_formulario,
                $rs->correos,
                $contrato->aliado?->correo,
            ),
            'direccion'     => $centro->direccion,
            'cordenadasDir' => [
                'vectorx'   => (float) ($geo['longitude'] ?? 0),
                'vectory'   => (float) ($geo['latitude'] ?? 0),
                'localidad' => $geo['localidad'] ?? null,
            ],
            'departamento' => [
                'nombreDepartamento' => $centro->departamento,
                'cdDepartamento'     => substr((string) $centro->municipio_sura, 0, 2),
            ],
            'municipio' => [
                'poblacion'       => $centro->municipio,
                'codigoMunicipio' => $centro->municipio_sura,
                'codigoPostal'    => null,
            ],
            'zona' => 'URBANA',
        ];
    }

    // ─── Traducciones y respaldos ────────────────────────────────────

    public static function tipoDocumento(?string $tipoBrynex): string
    {
        $tipo = strtoupper(trim((string) $tipoBrynex));

        return self::TIPOS_DOC[$tipo]
            ?? throw new RuntimeException("Tipo de documento '{$tipo}' sin equivalencia en ARL Sura.");
    }

    /**
     * EPS y AFP viajan con el código de Sura, que no es el nuestro: EMSSANAR es
     * ESSC18 aquí y 148 allá. Se llena con `arl:sincronizar-catalogos`.
     */
    private function entidad($entidad, string $campoCodigo, string $etiqueta): array
    {
        if (! $entidad) {
            throw new RuntimeException("El contrato no tiene {$etiqueta} asignada.");
        }

        if (! $entidad->codigo_sura) {
            throw new RuntimeException(
                "La {$etiqueta} '{$entidad->razon_social}' no tiene codigo_sura. Corre `arl:sincronizar-catalogos`."
            );
        }

        return [
            $campoCodigo         => $entidad->codigo_sura,
            'dni'                => $entidad->dni_sura,
            'dsCodigoMinisterio' => null,
            'dsNombre'           => $entidad->razon_social,
        ];
    }

    /** Traduce el municipio DANE de BryNex al código interno que usa Sura. */
    private function municipio(string $departamentoDane, string $municipioDane): array
    {
        $lista = $this->municipiosPorDepto[$departamentoDane]
            ??= $this->api->municipios($departamentoDane);

        foreach ($lista as $m) {
            if (($m['codigoPostal'] ?? null) === $municipioDane) {
                return $m;
            }
        }

        throw new RuntimeException("El municipio DANE {$municipioDane} no existe en el catálogo de Sura.");
    }

    /**
     * El cargo del contrato; si está vacío, el que la razón social tenga marcado
     * por defecto para ese nivel de riesgo.
     */
    private function cargo(Contrato $contrato): string
    {
        if ($cargo = $this->limpiar($contrato->cargo)) {
            return $cargo;
        }

        $porDefecto = \App\Models\RazonSocialCargo::porDefecto(
            (int) $contrato->razon_social_id,
            (int) $contrato->n_arl
        );

        return $porDefecto?->cargo
            ?? throw new RuntimeException(
                "El contrato {$contrato->id} no tiene cargo y la razón social no tiene uno por defecto ".
                "para el nivel de riesgo {$contrato->n_arl}."
            );
    }

    /** Una dirección de "0" o vacía no sirve para geocodificar: cae a la de la razón social. */
    private function direccionValida(?string $delCliente, ?string $respaldo): string
    {
        $dir = trim((string) $delCliente);

        if ($dir === '' || $dir === '0' || mb_strlen($dir) < 5) {
            $dir = trim((string) $respaldo);
        }

        if ($dir === '') {
            throw new RuntimeException('Ni el cliente ni la razón social tienen una dirección utilizable.');
        }

        return $dir;
    }

    /**
     * Correo de contacto, en cascada: el del trabajador, el de su razón social y
     * por último el del aliado.
     *
     * Varias razones sociales no tienen ningún dato de contacto cargado —BRYGAR
     * SAS, sin ir más lejos—, así que sin el último escalón la afiliación se
     * bloquearía por un dato que la empresa sí tiene, solo que en otra tabla.
     */
    private function correo(Contrato $contrato, $cliente, $rs): ?string
    {
        return $this->primerValor(
            $cliente->correo,
            $rs?->correo_formulario,
            $rs?->correos,
            $contrato->aliado?->correo,
        );
    }

    private function telefono(Contrato $contrato, $cliente, $rs): ?string
    {
        return $this->primerValor(
            $cliente->celular,
            $cliente->telefono,
            $rs?->tel_formulario,
            $rs?->telefonos,
            $contrato->aliado?->celular,
            $contrato->aliado?->telefono,
        );
    }

    private function primerValor(...$valores): ?string
    {
        foreach ($valores as $v) {
            // `correos` y `telefonos` de la razón social pueden traer varios separados por coma o ;
            $v = trim((string) preg_split('/[;,]/', (string) $v)[0]);
            if ($v !== '' && $v !== '0') {
                return $v;
            }
        }

        return null;
    }

    private function limpiar(?string $valor): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $valor));

        return $v !== '' ? mb_strtoupper($v) : null;
    }

    private function soloDigitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?: '';
    }
}
