<?php

namespace App\Services\Caja;

use App\Models\Contrato;
use App\Models\EpsAfiliacion;
use App\Models\Radicado;
use App\Models\RadicadoMovimiento;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Afiliación a la caja Comfandi por su Sucursal Virtual Empresas
 * (`afiliaciones.sucursalcomfandi.com/sakaar`, Next.js + Keycloak).
 *
 * El login es de la empresa (NIT + clave) y ofrece activar un segundo factor;
 * se omite, porque con TOTP la extensión no podría abrir sesión. La persona
 * entra en su Chrome y la extensión BryNex Portales llena el formulario de
 * Afiliación individual —que es uno solo, sin asistente— con los datos de
 * BryNex; ella revisa y pulsa Finalizar. El portal devuelve un número de
 * radicado con el formato 002-002-00436282.
 *
 * Mapeado el 15-sep-2026. Ver `comfandi-caja-afiliacion` en la memoria.
 */
class ComfandiCajaService
{
    public const ENTIDAD = 'comfandi_caja';

    public const HOST = 'afiliaciones.sucursalcomfandi.com';

    /** Nombre de la caja en BryNex (tabla cajas). */
    private const CAJA = 'COMFANDI';

    /** Comfandi es del Valle: el combo de departamento solo trae el 76. */
    private const DEPARTAMENTO = '76';

    /** Tipo de documento de BryNex → valor del combo del portal. No maneja pasaporte. */
    private const TIPOS = ['CC' => 'CO1C', 'CE' => 'CO1E', 'TI' => 'CO1T', 'PT' => 'CO1Y', 'PPT' => 'CO1Y', 'PE' => 'CO1Y'];

    public const ESTADOS_CIVIL = ['1' => 'Soltero', '2' => 'Casado', '3' => 'Separado', '4' => 'Viudo / a', '5' => 'Unión Libre'];

    public const CONTRATOS = ['02' => 'Indefinido', '01' => 'Fijo', '03' => 'Labor u obra'];

    public const SALARIOS = ['02' => 'Fijo', '01' => 'Integral', '03' => 'Variable'];

    /** El combo solo acepta enteros y calcula el total mes multiplicando por 30. */
    public const HORAS = ['8' => '8 horas (240 al mes)', '6' => '6 horas (180 al mes)', '4' => '4 horas (120 al mes)', '3' => '3 horas (90 al mes)', '2' => '2 horas (60 al mes)', '1' => '1 hora (30 al mes)'];

    public const NIVELES = [
        '10' => 'Bachillerato grado 11', '11' => 'Técnico', '12' => 'Estudios superiores',
        '09' => 'Bachillerato grado 10', '04' => 'Primaria grado 5', '13' => 'No formal', '14' => 'Ninguna',
    ];

    /**
     * Datos que Comfandi exige y BryNex no guarda: van fijos y son los valores
     * neutros del portal. Cambiarlos aquí los cambia en todas las afiliaciones.
     */
    private const PREDETERMINADOS = [
        'orientacion' => '04',   // Información no disponible
        'vulnerabilidad' => '1',    // No aplica
        'etnia' => '7',    // No se autoreconoce en ninguno de los anteriores
        'nacionalidad' => 'CO',   // Colombia
        'nivel' => '10',   // Bachillerato grado 11
        'horas' => '8',
        'tipoSalario' => '02',   // Fijo
        'ocupacion' => 'OTRAS OCUPACIONES ELEMENTALES',
    ];

    /**
     * @return array{problemas: string[], avisos: string[], resumen: array, portal: array|null}
     */
    public function preparar(Contrato $contrato): array
    {
        $contrato->loadMissing(['cliente.municipio', 'cliente.departamento', 'plan', 'razonSocial']);
        $cliente = $contrato->cliente;
        $rs = $contrato->razonSocial;
        $tipo = strtoupper((string) $cliente?->tipo_doc);
        $caja = DB::table('cajas')->where('id', $contrato->caja_id)->value('nombre');
        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', 'caja')->first();
        $problemas = [];
        $avisos = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if (! $caja || ! str_contains(mb_strtoupper($caja), self::CAJA)) {
            $problemas[] = 'La caja del contrato no es Comfandi.';
        }
        if (! $contrato->plan?->incluye_caja) {
            $avisos[] = 'El plan del contrato no marca caja de compensación: revisa antes de afiliar.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'La Sucursal Virtual Empresas es para dependientes: el independiente va por otro canal.';
        } elseif (! preg_replace('/\D/', '', (string) $rs->nit) || preg_replace('/\D/', '', (string) $rs->nit) === '2') {
            $problemas[] = 'La razón social del contrato no tiene un NIT real (es la comodín): no se puede saber con qué empresa afiliarlo.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } elseif (! isset(self::TIPOS[$tipo])) {
            $problemas[] = "El portal de Comfandi no maneja el tipo de documento '{$cliente->tipo_doc}'.";
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        } elseif ($contrato->fecha_ingreso->isAfter(today())) {
            $problemas[] = 'La fecha de ingreso es futura y el calendario de Comfandi solo acepta hasta hoy.';
        } elseif ($contrato->fecha_ingreso->year < 2022) {
            $problemas[] = 'El calendario de Comfandi no baja de 2022.';
        }
        if (! (int) round((float) ($contrato->salario ?: $contrato->ibc))) {
            $problemas[] = 'El contrato no tiene salario.';
        }
        if ($radicado?->estado === Radicado::ESTADO_OK) {
            $problemas[] = 'El radicado de caja ya está en OK.';
        }

        $cred = $this->credencial($contrato);
        if (isset($cred['error'])) {
            $avisos[] = $cred['error'].' Tendrás que iniciar sesión a mano en el portal.';
        }

        $genero = match (mb_strtoupper(trim((string) $cliente?->genero))) {
            'F', 'FEMENINO' => '1',
            'M', 'MASCULINO' => '2',
            default => null,
        };
        if (! $genero) {
            $avisos[] = 'El cliente no tiene género en BryNex: escógelo abajo, el portal lo exige.';
        }

        // El portal exige celular y dirección, y sin ellos no se puede radicar.
        // Cuando el cliente no los tiene se usan los del aliado, que es quien
        // hace el trámite y quien recibe lo que la caja mande: preferible eso a
        // dejar a la persona sin afiliar. Va como aviso, no en silencio, para
        // que se complete el dato de verdad cuando se sepa.
        $aliado = $contrato->aliado;

        $celular = collect([$cliente?->celular, $cliente?->telefono])
            ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
            ->map(fn ($t) => preg_replace('/\D/', '', $t))->first(fn ($t) => strlen($t) === 10) ?: null;

        if (! $celular) {
            $celular = collect([$aliado?->celular, $aliado?->telefono])
                ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
                ->map(fn ($t) => preg_replace('/\D/', '', $t))->first(fn ($t) => strlen($t) === 10) ?: null;

            $celular
                ? $avisos[] = "El cliente no tiene celular: se usa el de {$aliado->nombre} ({$celular})."
                : $problemas[] = 'El cliente no tiene celular de 10 dígitos y el aliado tampoco: el portal lo exige.';
        }

        $direccion = $this->direccion((string) $cliente?->direccion_vivienda);

        if (! $direccion) {
            $direccion = $this->direccion((string) $aliado?->direccion);

            $direccion
                ? $avisos[] = "El cliente no tiene dirección: se usa la de {$aliado->nombre} ({$direccion})."
                : $problemas[] = 'El cliente no tiene dirección y el aliado tampoco: el portal la exige (mira la "Última dirección registrada" que muestra Comfandi).';
        }

        $ciudad = (string) ($cliente?->municipio_id ?: '');
        if (! str_starts_with($ciudad, self::DEPARTAMENTO)) {
            $avisos[] = $ciudad
                ? 'El cliente reside fuera del Valle: el portal solo lista municipios del Valle, se usará Cali.'
                : 'El cliente no tiene municipio: se usará Cali.';
            $ciudad = '76001';
        }

        $correo = $cliente?->correo ?: config("afiliaciones_correo.buzones.{$contrato->aliado_id}");
        if (! $cliente?->correo) {
            $avisos[] = 'El cliente no tiene correo: se usa el del buzón del aliado.';
        }
        if ($radicado?->numero_radicado && $radicado->estado === Radicado::ESTADO_TRAMITE) {
            $avisos[] = "Este radicado ya está en trámite (N° {$radicado->numero_radicado}).";
        }

        $nombre = trim(implode(' ', array_filter([$cliente?->primer_nombre, $cliente?->segundo_nombre, $cliente?->primer_apellido, $cliente?->segundo_apellido])));
        $resumen = [
            'trabajador' => $nombre,
            'documento' => trim($tipo.' '.$contrato->cedula),
            'razon_social' => $rs?->razon_social,
            'nit' => $rs?->nit,
            'caja' => $caja,
            'fecha_ingreso' => $contrato->fecha_ingreso?->toDateString(),
            'salario' => (int) round((float) ($contrato->salario ?: $contrato->ibc)),
            'residencia' => $cliente?->municipio?->nombre,
            'direccion' => $direccion,
            'celular' => $celular,
            'correo' => $correo,
            'usuario_portal' => $cred['usuario'] ?? null,
            'estado_radicado' => $radicado?->estado,
            'numero_radicado' => $radicado?->numero_radicado,
        ];

        return ['problemas' => $problemas, 'avisos' => $avisos, 'resumen' => $resumen, 'portal' => $problemas ? null : [
            'host' => self::HOST,
            'empresa' => $rs->razon_social,
            'nit' => preg_replace('/\D/', '', (string) $rs->nit),
            'tipoDoc' => self::TIPOS[$tipo],
            'documento' => (string) $contrato->cedula,
            'apellido' => (string) $cliente->primer_apellido,
            // La Registraduría no siempre devuelve la identidad al consultar el
            // documento en el portal (Erwing Torres, 28-sep-2026): ahí el
            // formulario se queda sin nombre ni nacimiento y no deja radicar.
            // Se mandan los de BryNex para poder rellenarlos.
            'primerNombre' => mb_strtoupper(trim((string) $cliente->primer_nombre)),
            'segundoNombre' => mb_strtoupper(trim((string) $cliente->segundo_nombre)),
            'primerApellido' => mb_strtoupper(trim((string) $cliente->primer_apellido)),
            'segundoApellido' => mb_strtoupper(trim((string) $cliente->segundo_apellido)),
            'nacimiento' => optional($cliente->fecha_nacimiento)->format('Y-m-d'),
            'genero' => $genero,
            'direccion' => $direccion,
            'ciudad' => $ciudad,
            'ciudadLabor' => $ciudad,
            'departamento' => self::DEPARTAMENTO,
            'celular' => $celular,
            'correo' => $correo,
            'fechaIngreso' => $contrato->fecha_ingreso->format('Y-m-d'),
            'salario' => (int) round((float) ($contrato->salario ?: $contrato->ibc)),
            'ocupacionTexto' => mb_strtoupper(trim((string) $contrato->cargo)) ?: self::PREDETERMINADOS['ocupacion'],
        ] + array_diff_key(self::PREDETERMINADOS, ['ocupacion' => null])];
    }

    /** Usuario (y clave, según permiso) del portal de la razón social. */
    public function credencial(Contrato $contrato): array
    {
        $contrato->loadMissing('razonSocial');
        $nit = preg_replace('/\D/', '', (string) $contrato->razonSocial?->nit);
        if (! $nit) {
            return ['error' => 'El contrato no tiene razón social con NIT.'];
        }

        $fila = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('rs.nit', $nit)
            ->where('c.tipo', 'CAJA')
            ->where('c.entidad', 'like', '%COMFANDI%')
            ->where('c.activo', true)
            ->whereNotNull('c.usuario')->where('c.usuario', '<>', '')
            ->orderByDesc('c.updated_at')
            ->first(['c.usuario', 'c.contrasena']);

        if (! $fila) {
            return ['error' => 'La empresa no tiene la clave de Comfandi en el módulo de claves.'];
        }

        return ['usuario' => trim($fila->usuario), 'contrasena' => (string) $fila->contrasena, 'host' => self::HOST];
    }

    /**
     * Registra el resultado del portal en el radicado de caja.
     *
     * $entrada: numero (002-002-…), texto (mensaje del portal), error.
     */
    public function aplicar(Contrato $contrato, array $entrada, ?int $usuarioId): array
    {
        $prep = $this->preparar($contrato);
        if ($prep['problemas']) {
            throw new RuntimeException(implode(' ', $prep['problemas']));
        }

        $radicado = Radicado::firstOrCreate(
            ['contrato_id' => $contrato->id, 'tipo' => 'caja'],
            ['aliado_id' => $contrato->aliado_id, 'estado' => Radicado::ESTADO_PENDIENTE]
        );
        $numero = trim((string) ($entrada['numero'] ?? '')) ?: null;
        $texto = trim(preg_replace('/\s+/', ' ', (string) ($entrada['texto'] ?? '')));

        if (! empty($entrada['error']) && ! $numero) {
            $mensaje = 'Comfandi (caja): el portal no radicó la afiliación — '.mb_substr((string) $entrada['error'], 0, 300);
            $this->marcar($radicado, null, Radicado::ESTADO_ERROR, $mensaje, $usuarioId);
            $this->bitacora($contrato, $radicado, 'fallida', null, $texto, (string) $entrada['error'], $usuarioId);

            return ['ok' => false, 'estado' => Radicado::ESTADO_ERROR, 'mensaje' => $mensaje];
        }
        if (! $numero) {
            throw new RuntimeException('Falta el número de radicado que dio el portal.');
        }

        $mensaje = sprintf('Comfandi (caja): afiliación radicada en la Sucursal Virtual Empresas con el N° %s el %s. Queda en Radicados del portal hasta que Comfandi la procese.',
            $numero, now()->format('d/m/Y H:i'));
        $this->marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, $mensaje, $usuarioId);
        $this->bitacora($contrato, $radicado, 'exitosa', $numero, $texto, null, $usuarioId);

        return ['ok' => true, 'estado' => Radicado::ESTADO_TRAMITE, 'numero' => $numero, 'mensaje' => $mensaje];
    }

    /**
     * Dirección como la acepta Comfandi: mayúsculas, sin acentos, **sin `#` ni
     * `-`** y terminada en **SECTOR URBANO** (o RURAL).
     *
     * Las dos reglas solo saltan al enviar el formulario, nunca mientras se
     * escribe, y cada una aborta la radicación con su propio mensaje:
     * "La nueva locación no puede contener caracteres especiales" y
     * "La nueva locación debe contener «Sector urbano» o «Sector rural»".
     * Así, "CALLE 43 # 37 - 31" va como "CALLE 43 37 31 SECTOR URBANO".
     * Probado el 15-sep-2026 radicando a Yesenia Vidal (002-002-00440257).
     */
    private function direccion(string $texto): string
    {
        $limpia = preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ',
            mb_strtoupper(strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ñ' => 'N']))));

        $limpia = trim($limpia);
        if (! preg_match('/[A-Z0-9]{2}/', $limpia)) {
            return '';
        }

        // Si ya trae el sector (urbano o rural) se respeta; si no, urbano.
        return preg_match('/\bSECTOR\s+(URBANO|RURAL)\b/', $limpia) ? $limpia : $limpia.' SECTOR URBANO';
    }

    /**
     * Sueldo que espera el portal para esa jornada.
     *
     * Comfandi valida que el salario sea proporcional a las horas ("El salario
     * no es proporcional a las horas diarias trabajadas"): la jornada completa
     * son 240 horas al mes (8 × 30), así que el sueldo declarado es el del
     * contrato por la fracción de jornada. Con 4 horas —120 al mes— sobre el
     * mínimo da 875.453, que es además lo que BryNex ya usa como salario de un
     * Tiempo Parcial (14). Ojo: no es el IBC de la planilla, que va por 14/30.
     */
    public static function sueldoPorHoras(int $salario, int $horasDiarias): int
    {
        $horasDiarias = max(1, min(8, $horasDiarias));

        return (int) round($salario * ($horasDiarias * 30) / 240);
    }

    private function marcar(Radicado $radicado, ?string $numero, string $estado, string $observacion, ?int $usuarioId): void
    {
        DB::transaction(function () use ($radicado, $numero, $estado, $observacion, $usuarioId) {
            $r = Radicado::whereKey($radicado->id)->lockForUpdate()->first();
            $anterior = $r->estado;
            if ($anterior === Radicado::ESTADO_OK) {
                return;                                  // nunca se retrocede un OK
            }
            $r->update([
                'estado' => $estado, 'numero_radicado' => $numero ?? $r->numero_radicado, 'canal_envio' => 'portal',
                'user_id' => $usuarioId, 'fecha_inicio_tramite' => $r->fecha_inicio_tramite ?? now(),
                'observacion' => trim(($r->observacion ? $r->observacion.' | ' : '').$observacion),
            ]);
            RadicadoMovimiento::create([
                'radicado_id' => $r->id, 'contrato_id' => $r->contrato_id, 'tipo_proceso' => 'afiliacion',
                'entidad' => 'caja', 'user_id' => $usuarioId, 'estado_anterior' => $anterior,
                'estado_nuevo' => $estado, 'observacion' => $observacion,
            ]);
        });
        $radicado->refresh();
    }

    private function bitacora(Contrato $contrato, Radicado $radicado, string $estado, ?string $numero, string $texto, ?string $error, ?int $usuarioId): void
    {
        EpsAfiliacion::create([
            'aliado_id' => $contrato->aliado_id, 'contrato_id' => $contrato->id, 'radicado_id' => $radicado->id,
            'entidad' => self::ENTIDAD, 'operacion' => 'afiliacion_caja', 'estado' => $estado, 'numero_radicado' => $numero,
            'payload' => json_encode(['portal' => self::HOST], JSON_UNESCAPED_UNICODE),
            'respuesta' => json_encode(['texto' => mb_substr($texto, 0, 2000)], JSON_UNESCAPED_UNICODE),
            'mensaje_error' => $error ? mb_substr($error, 0, 500) : null, 'usuario_id' => $usuarioId,
        ]);
    }
}
