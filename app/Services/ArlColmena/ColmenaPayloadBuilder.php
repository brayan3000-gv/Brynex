<?php

namespace App\Services\ArlColmena;

use App\Models\Contrato;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Traduce un contrato de BryNex al JSON que espera `POST /workers/dependent`.
 *
 * El mapa de campos no está documentado en ninguna parte: se capturó del propio
 * formulario del portal (15-sep-2026) interceptando el envío, porque un cuerpo
 * incompleto solo devuelve "Bad request" sin decir qué falta. Por eso van
 * también los bloques vacíos (`workingDay`, `usualOccupation`, `contractType`,
 * `linkType`, `linkerType`): el portal los manda en null y no conviene apartarse
 * de lo que ya se sabe que funciona.
 *
 * Lo que no vive en BryNex y aquí es constante: zona URBANA, jornada ÚNICA,
 * modalidad PRESENCIAL y `specialActivityType` 1 (actividad normal).
 *
 * EPS, AFP, ARL anterior y centro de trabajo se resuelven contra los catálogos
 * del propio portal —no hay tabla de equivalencias en BryNex—, así que si un
 * nombre no cruza, el error dice contra qué se intentó.
 */
class ColmenaPayloadBuilder
{
    /** Tipos de documento de BryNex a los de Colmena. */
    public const TIPOS_DOC = [
        'CC' => 'CC', 'CE' => 'CE', 'TI' => 'TI', 'RC' => 'RC',
        'PA' => 'PA', 'PT' => 'PT', 'PPT' => 'PT', 'PEP' => 'PE',
        'PE' => 'PE', 'SC' => 'SC', 'CD' => 'CD', 'NI' => 'NI',
        'NU' => 'NU', 'MS' => 'MSI', 'PM' => 'PM',
    ];

    /**
     * Nombres que no cruzan solos entre BryNex y Colmena.
     *
     * Clave: como está en BryNex (normalizado). Valor: trozo del nombre en
     * Colmena.
     */
    private const ALIAS = [
        // Colmena escribe EMSANAR con una sola ese.
        'EMSSANAR' => 'EMSANAR',
        'SOS' => 'SERVICIO OCCIDENTAL DE SALUD',
        'DELAGENTE' => 'COMFENALCO VALLE',
        'HORIZONTE' => 'Horizonte',
    ];

    /** ARL anterior cuando no se sabe de dónde viene el trabajador. */
    public const ARL_ANTERIOR_DESCONOCIDA = '888'; // NO SUMINISTRADA

    public function __construct(private ColmenaApiService $api) {}

    /**
     * @param  Carbon  $inicio  Inicio de la vigencia. Colmena no cubre el mismo
     *                          día: su calendario solo habilita desde mañana.
     * @param  string|null  $arlAnteriorId  Id del catálogo de Colmena; por
     *                                      defecto "no suministrada".
     */
    public function paraAfiliacion(Contrato $contrato, Carbon $inicio, ?string $arlAnteriorId = null): array
    {
        $cliente = $contrato->cliente ?? throw new RuntimeException("El contrato {$contrato->id} no tiene cliente.");
        $rs = $contrato->razonSocial;

        $centro = $this->centro($contrato);
        $eps = $this->deCatalogo($this->api->eps(), $this->nombreEntidad($contrato->eps ?: $cliente->eps), 'EPS');
        $afp = $this->afpDe($contrato, $cliente);
        $arl = $this->arlAnterior($arlAnteriorId);

        $fecha = $inicio->toDateString();

        return [
            'contractId' => (string) $this->api->contrato(),
            'headquarterId' => (string) $centro['consecutive'],
            // 0 = trabajador nuevo en este contrato. En una reafiliación
            // Colmena lo vuelve a numerar solo.
            'workerId' => '0',

            'identification' => (string) $contrato->cedula,
            'identificationType' => $this->tipoDocumento($cliente->tipo_doc),

            'firstname' => $this->limpiar($cliente->primer_nombre),
            'secondName' => $this->limpiar($cliente->segundo_nombre),
            'firstSurname' => $this->limpiar($cliente->primer_apellido),
            'secondSurname' => $this->limpiar($cliente->segundo_apellido),
            'gender' => $this->genero($cliente->genero),
            'birthdate' => $cliente->fecha_nacimiento?->toDateString()
                ?? throw new RuntimeException('El cliente no tiene fecha de nacimiento.'),

            'city' => ['id' => $this->ciudad($cliente)],
            'department' => $this->departamento($cliente),
            'zoneType' => ['id' => 'U', 'name' => 'Urbana'],
            'address' => $this->direccion($cliente, $rs, $contrato),
            // Colmena valida el fijo con el formato viejo: máximo 7 dígitos, sin
            // indicativo. Un celular de 10 lo rechaza ("El teléfono no debe
            // tener más de 7 caracteres"), así que se le quita el prefijo.
            'phone' => $this->telefonoFijo($contrato, $cliente, $rs),
            'cellphone' => $this->soloDigitos($cliente->celular) ?: $this->telefono($contrato, $cliente, $rs),
            'email' => $this->correo($contrato, $cliente, $rs),
            'fax' => null,

            'position' => $this->cargo($contrato),
            'basicSalary' => (string) (int) round($contrato->ibc ?: $contrato->salario),
            'eps' => $eps,
            'afp' => $afp,
            'previousArp' => $arl,

            'admissionDate' => $fecha,
            'initEffectiveDate' => $fecha,

            'contributorType' => ['id' => '1', 'name' => 'Dependiente'],
            'subContributorType' => ['id' => '0', 'name' => 'NO DEFINIDO'],
            'modalityType' => ['id' => 'A', 'name' => 'Presencial'],
            'workday' => ['id' => 'A', 'name' => 'JORNADA UNICA'],
            'specialActivityType' => ['id' => '1', 'name' => null],

            // El portal los manda vacíos en un ingreso normal.
            'teleworkerSchedules' => [],
            'workingDay' => ['id' => null, 'name' => null],
            'usualOccupation' => ['id' => null, 'name' => null],
            'usualOccupationTime' => '0',
            'contractType' => ['id' => null, 'name' => null],
            'linkType' => ['id' => null, 'name' => null],
            'linkerType' => ['id' => null, 'name' => null],
            'blockageCause' => null,
            'status' => null,
        ];
    }

    /**
     * Qué le falta al contrato para poder afiliarse, sin tocar la red más que
     * para los catálogos.
     *
     * @return array<int,string> Vacío cuando está listo.
     */
    public function problemas(Contrato $contrato): array
    {
        $problemas = [];
        $cliente = $contrato->cliente;

        if (! $cliente) {
            return ['El contrato no tiene un cliente asociado.'];
        }

        foreach ([
            'primer_nombre' => 'primer nombre',
            'primer_apellido' => 'primer apellido',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'genero' => 'sexo',
        ] as $campo => $etiqueta) {
            if (! $cliente->$campo) {
                $problemas[] = "El cliente no tiene {$etiqueta}.";
            }
        }

        foreach ([
            fn () => $this->tipoDocumento($cliente->tipo_doc),
            fn () => $this->ciudad($cliente),
            fn () => $this->departamento($cliente),
            fn () => $this->cargo($contrato),
            fn () => $this->centro($contrato),
            fn () => $this->deCatalogo($this->api->eps(), $this->nombreEntidad($contrato->eps ?: $cliente->eps), 'EPS'),
            fn () => $this->afpDe($contrato, $cliente),
            fn () => $this->direccion($cliente, $contrato->razonSocial, $contrato),
        ] as $comprobar) {
            try {
                $comprobar();
            } catch (RuntimeException $e) {
                $problemas[] = $e->getMessage();
            }
        }

        if (! ($contrato->ibc ?: $contrato->salario)) {
            $problemas[] = 'El contrato no tiene salario ni IBC.';
        }

        if (! $this->correo($contrato, $cliente, $contrato->razonSocial)) {
            $problemas[] = 'No hay correo del cliente, ni de la razón social, ni del aliado.';
        }

        if (! $this->telefono($contrato, $cliente, $contrato->razonSocial)) {
            $problemas[] = 'No hay teléfono del cliente, ni de la razón social, ni del aliado.';
        }

        return $problemas;
    }

    // ─── Piezas ──────────────────────────────────────────────────────

    /** El centro de trabajo del contrato según su nivel de riesgo. */
    public function centro(Contrato $contrato): array
    {
        $nivel = (int) $contrato->n_arl;

        if (! $nivel) {
            throw new RuntimeException("El contrato {$contrato->id} no tiene nivel de riesgo (n_arl).");
        }

        $centros = $this->api->centrosDeTrabajo();

        foreach ($centros as $centro) {
            if ((int) ($centro['riskClass'] ?? 0) === $nivel) {
                return $centro;
            }
        }

        $disponibles = implode(', ', array_map(
            fn ($c) => trim($c['name'] ?? '').' (riesgo '.($c['riskClass'] ?? '?').')',
            $centros
        ));

        throw new RuntimeException(
            "El contrato de Colmena no tiene centro de trabajo de riesgo {$nivel}. Hay: {$disponibles}."
        );
    }

    public function tipoDocumento(?string $tipoBrynex): array
    {
        $tipo = strtoupper(trim((string) $tipoBrynex));
        $id = self::TIPOS_DOC[$tipo] ?? throw new RuntimeException("Tipo de documento '{$tipo}' sin equivalencia en Colmena.");

        foreach ($this->api->tiposDocumento() as $t) {
            if (($t['id'] ?? null) === $id) {
                // El formulario manda `label`/`value` además de id/name.
                return ['id' => $id, 'name' => $t['name'], 'label' => $t['name'], 'value' => $id];
            }
        }

        throw new RuntimeException("Colmena no reconoce el tipo de documento '{$id}'.");
    }

    /** Solo la letra: Colmena acepta F, M, N, O, T. */
    private function genero(?string $genero): array
    {
        $letra = strtoupper(substr(trim((string) $genero), 0, 1)) === 'F' ? 'F' : 'M';

        return ['id' => $letra, 'name' => $letra === 'F' ? 'Femenino' : 'Masculino'];
    }

    private function ciudad($cliente): string
    {
        $codigo = preg_replace('/\D/', '', (string) $cliente->municipio_id);

        if (! $codigo) {
            throw new RuntimeException('El cliente no tiene municipio.');
        }

        return str_pad($codigo, 5, '0', STR_PAD_LEFT);
    }

    private function departamento($cliente): array
    {
        $codigo = str_pad(preg_replace('/\D/', '', (string) $cliente->departamento_id), 2, '0', STR_PAD_LEFT);

        if ($codigo === '00') {
            throw new RuntimeException('El cliente no tiene departamento.');
        }

        foreach ($this->api->catalogoDepartamentos() as $d) {
            if (($d['id'] ?? null) === $codigo) {
                return ['id' => $codigo, 'name' => $d['name']];
            }
        }

        return ['id' => $codigo, 'name' => null];
    }

    private function arlAnterior(?string $id): array
    {
        $id ??= self::ARL_ANTERIOR_DESCONOCIDA;

        foreach ($this->api->arls() as $arl) {
            if ((string) ($arl['id'] ?? '') === (string) $id) {
                return ['id' => (string) $id, 'name' => $arl['name']];
            }
        }

        throw new RuntimeException("La ARL anterior '{$id}' no está en el catálogo de Colmena.");
    }

    /**
     * Busca una entidad en un catálogo de Colmena por nombre.
     *
     * Primero exacto, luego por contención en cualquier sentido —los nombres de
     * Colmena vienen recortados a 50 caracteres— y por último con los alias.
     */
    private function deCatalogo(array $catalogo, string $nombre, string $etiqueta): array
    {
        $buscado = $this->normalizar($nombre);

        if ($buscado === '') {
            throw new RuntimeException("El contrato no tiene {$etiqueta} asignada.");
        }

        $alias = self::ALIAS[$buscado] ?? null;

        foreach ([$buscado, $alias ? $this->normalizar($alias) : null] as $clave) {
            if (! $clave) {
                continue;
            }

            foreach ($catalogo as $item) {
                if ($this->normalizar($item['name'] ?? '') === $clave) {
                    return ['id' => (string) $item['id'], 'name' => $item['name']];
                }
            }

            foreach ($catalogo as $item) {
                $suyo = $this->normalizar($item['name'] ?? '');
                if ($suyo !== '' && mb_strlen($clave) >= 5 && (str_contains($suyo, $clave) || str_contains($clave, $suyo))) {
                    return ['id' => (string) $item['id'], 'name' => $item['name']];
                }
            }
        }

        throw new RuntimeException("La {$etiqueta} '{$nombre}' no cruza con ninguna del catálogo de Colmena.");
    }

    /**
     * La AFP que se manda a Colmena.
     *
     * Primero una AFP real: la del contrato y, si no tiene, la de la ficha del
     * cliente. Quien no cotiza pensión (planes «Solo ARL» y «EPS + ARL», con el
     * RUAF diciendo «ninguna») no tiene ninguna, y para eso el catálogo de
     * Colmena trae «SIN AFP». Si el plan SÍ incluye pensión, que falte la AFP
     * sigue siendo un dato por completar, no algo que se rellene.
     */
    private function afpDe(Contrato $contrato, $cliente): array
    {
        foreach ([$contrato->pension, $cliente->pension] as $fondo) {
            $nombre = $this->nombreEntidad($fondo);

            if ($nombre !== '' && ! preg_match('/ninguna/i', $nombre)) {
                return $this->deCatalogo($this->api->afp(), $nombre, 'AFP');
            }
        }

        if (! $contrato->plan?->incluye_pension) {
            return $this->deCatalogo($this->api->afp(), 'SIN AFP', 'AFP');
        }

        throw new RuntimeException('El contrato no tiene AFP asignada.');
    }

    private function nombreEntidad($entidad): string
    {
        return trim((string) ($entidad->razon_social ?? $entidad->nombre ?? ''));
    }

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

    private function direccion($cliente, $rs, Contrato $contrato): string
    {
        $dir = trim((string) $cliente->direccion_vivienda);

        if ($dir === '' || $dir === '0' || mb_strlen($dir) < 5) {
            $dir = trim((string) ($rs?->dir_formulario ?: $rs?->direccion ?: $contrato->aliado?->direccion));
        }

        if ($dir === '') {
            throw new RuntimeException('Ni el cliente ni la razón social tienen una dirección utilizable.');
        }

        // Colmena valida la dirección y rechaza lo que venga después del
        // número ("CARRERA 39 # 43 - 04 Antonio Nariño" → "La dirección del
        // trabajador no es válida"). El barrio va en otro campo, así que se
        // corta en el último dígito.
        $dir = mb_strtoupper(preg_replace('/\s+/', ' ', $dir));

        if (preg_match('/^(.*\d)/u', $dir, $m)) {
            $dir = $m[1];
        }

        return mb_substr(trim($dir), 0, 40);
    }

    private function correo(Contrato $contrato, $cliente, $rs): ?string
    {
        return $this->primerValor($cliente->correo, $rs?->correo_formulario, $rs?->correos, $contrato->aliado?->correo);
    }

    /** El fijo que acepta Colmena: los últimos 7 dígitos, sin indicativo. */
    private function telefonoFijo(Contrato $contrato, $cliente, $rs): ?string
    {
        $telefono = $this->telefono($contrato, $cliente, $rs);

        if (! $telefono) {
            return null;
        }

        return mb_strlen($telefono) > 7 ? mb_substr($telefono, -7) : $telefono;
    }

    private function telefono(Contrato $contrato, $cliente, $rs): ?string
    {
        return $this->soloDigitos($this->primerValor(
            $cliente->telefono,
            $cliente->celular,
            $rs?->tel_formulario,
            $rs?->telefonos,
            $contrato->aliado?->telefono,
            $contrato->aliado?->celular,
        )) ?: null;
    }

    private function primerValor(...$valores): ?string
    {
        foreach ($valores as $v) {
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

    /**
     * Deja solo el nombre propio: sin tildes, sin puntuación y sin el ruido que
     * cada lado escribe a su manera ("Eps-s emssanar" aquí, "EMSANAR EPS" allá,
     * "E.P.S. Sanitas S.A." en el catálogo).
     */
    private function normalizar(string $texto): string
    {
        $t = mb_strtoupper(trim($texto));
        $t = strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);
        $t = preg_replace('/[^A-Z0-9]+/', ' ', $t);

        $ruido = ['EPS', 'EPSS', 'ESS', 'SA', 'SAS', 'S', 'E', 'P', 'A', 'LTDA', 'CCF', 'DE', 'DEL', 'LA', 'EL'];
        $palabras = array_filter(
            explode(' ', $t),
            fn ($palabra) => $palabra !== '' && ! in_array($palabra, $ruido, true)
        );

        return implode('', $palabras);
    }
}
