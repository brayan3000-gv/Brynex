<?php

namespace App\Services\Caja;

use App\Models\Contrato;
use App\Models\EpsAfiliacion;
use App\Models\Radicado;
use App\Models\RadicadoMovimiento;
use App\Services\EpsPortal\EpsClavePortal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Afiliación a la caja de compensación Comfenalco Valle por su Sucursal Virtual
 * Afiliación (`virtual.comfenalcovalle.com.co/ServiciosWebRyA`).
 *
 * El login es de la empresa y pide captcha, así que la persona entra en su Chrome
 * y la extensión BryNex Portales llena el asistente de 8 pasos con los datos de
 * BryNex; los botones Continuar, la aceptación de términos y Finalizar los pulsa
 * ella. Al terminar, el portal da un número de formulario (1089…) y Comfenalco
 * verifica en 2 días; después llega el correo "Afiliación exitosa" de sirap@.
 *
 * Mapeado y probado con Juan David Varela el 15-sep-2026 (formulario 1089000002377457).
 */
class ComfenalcoCajaService
{
    use ContactoDeCaja;

    public const ENTIDAD = 'comfenalco_caja';

    public const HOST = 'virtual.comfenalcovalle.com.co';

    /** Nombre de la caja en BryNex (tabla cajas). */
    private const CAJA = 'COMFENALCO VALLE';

    /**
     * Cómo puede llamarse esta caja en el llavero de claves.
     *
     * Comfenalco no es una sola empresa: Valle, Cartagena, Antioquia y Quindío
     * son cajas independientes, con portal y clave propios. Un `LIKE
     * '%COMFENALCO%'` las confunde y entrega la clave de otra ciudad, así que
     * el nombre tiene que estar en esta lista —sin el "CAJA " de adelante—.
     */
    private const NOMBRES = ['COMFENALCO', 'COMFENALCO VALLE', 'COMFENALCO VALLE DELAGENTE'];

    /** ¿Ese nombre del llavero es el de Comfenalco Valle y no el de otra regional? */
    public static function esDelValle(string $entidad): bool
    {
        $nombre = trim(preg_replace('/^\s*CAJA\s+/i', '', mb_strtoupper(trim($entidad))));

        return in_array($nombre, self::NOMBRES, true);
    }

    /** Tipo de documento de BryNex → valor del combo del portal. */
    private const TIPOS = ['CC' => 1, 'TI' => 2, 'CE' => 4, 'RC' => 5, 'PA' => 6, 'PP' => 6, 'PT' => 16, 'PPT' => 16];

    /** Estado civil del portal (lo elige la persona en el modal). */
    public const ESTADOS_CIVIL = [1 => 'Soltero', 2 => 'Casado', 3 => 'Viudo', 4 => 'Unión libre', 5 => 'Separado', 6 => 'Divorciado'];

    public const CONTRATOS = [1 => 'Término indefinido', 2 => 'Término fijo', 3 => 'Labor contratada'];

    public const FORMAS_PAGO = [10 => 'Daviplata', 13 => 'Kupi'];

    /**
     * @return array{problemas: string[], avisos: string[], resumen: array, portal: array|null}
     */
    public function preparar(Contrato $contrato): array
    {
        $contrato->loadMissing(['cliente.municipio', 'cliente.departamento', 'plan', 'razonSocial']);
        $cliente = $contrato->cliente;
        $rs      = $contrato->razonSocial;
        $tipo    = strtoupper((string) $cliente?->tipo_doc);
        $caja    = DB::table('cajas')->where('id', $contrato->caja_id)->value('nombre');
        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', 'caja')->first();
        $problemas = [];
        $avisos = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if (! $caja || ! str_contains(mb_strtoupper($caja), self::CAJA)) {
            $problemas[] = 'La caja del contrato no es Comfenalco Valle.';
        }
        if (! $contrato->plan?->incluye_caja) {
            $avisos[] = 'El plan del contrato no marca caja de compensación: revisa antes de afiliar.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'El portal de empresa es para dependientes: el independiente va por correo a servicio al cliente.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } elseif (! isset(self::TIPOS[$tipo])) {
            $problemas[] = "Tipo de documento '{$cliente->tipo_doc}' sin equivalencia en el portal.";
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        }
        if (! $contrato->baseCaja()['salario']) {
            $problemas[] = 'El contrato no tiene salario.';
        }
        if ($radicado?->estado === Radicado::ESTADO_OK) {
            $problemas[] = 'El radicado de caja ya está en OK.';
        }

        $cred = $rs ? EpsClavePortal::para(self::ENTIDAD, '%COMFENALCO%', 'Comfenalco Valle', (string) $rs->nit, 'CAJA', self::esDelValle(...)) : ['error' => 'Sin razón social.'];
        if (isset($cred['error'])) {
            $avisos[] = $cred['error'].' Tendrás que iniciar sesión a mano en el portal.';
        }

        $celular = collect([$cliente?->celular, $cliente?->telefono])
            ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
            ->map(fn ($t) => preg_replace('/\D/', '', $t))->first(fn ($t) => strlen($t) === 10) ?: null;
        if (! $celular) {
            $problemas[] = 'El cliente no tiene celular de 10 dígitos: el portal lo exige.';
        }
        if (! $cliente?->direccion_vivienda) {
            $avisos[] = 'El cliente no tiene dirección: habrá que escribirla en el portal (sin # ni -).';
        }
        $beneficiarios = $cliente ? $cliente->beneficiarios()->where('aliado_id', $contrato->aliado_id)->get() : collect();
        if ($beneficiarios->isNotEmpty()) {
            $avisos[] = 'El cliente tiene '.$beneficiarios->count().' beneficiario(s) en BryNex: agrégalos en el paso Beneficiarios del portal o después con una adición.';
        }
        if ($radicado?->numero_radicado && $radicado->estado === Radicado::ESTADO_TRAMITE) {
            $avisos[] = "Este radicado ya está en trámite (formulario {$radicado->numero_radicado}).";
        }

        // El salario que se le declara a la caja no es el del contrato cuando es
        // Tiempo Parcial: ese es el de pensión (ver Contrato::baseCaja()).
        $base = $contrato->baseCaja();

        // Si el trabajador no tiene correo se usa el de su empleador, que es
        // quien hace el trámite y a quien la caja le responde.
        $correo = $this->correo($cliente->correo)
            ?: $this->correoRazonSocial($rs)
            ?: $this->correo($contrato->aliado?->correo, config("afiliaciones_correo.buzones.{$contrato->aliado_id}"));

        if ($correo && ! $cliente->correo) {
            $avisos[] = "El cliente no tiene correo: se usa {$correo}.";
        } elseif (! $correo) {
            $avisos[] = 'Nadie tiene correo (ni el cliente, ni la empresa, ni el aliado): habrá que escribirlo en el portal.';
        }

        $nombre = trim(implode(' ', array_filter([$cliente?->primer_nombre, $cliente?->segundo_nombre, $cliente?->primer_apellido, $cliente?->segundo_apellido])));
        $resumen = [
            'trabajador'   => $nombre,
            'documento'    => trim($tipo.' '.$contrato->cedula),
            'razon_social' => $rs?->razon_social,
            'nit'          => $rs?->nit,
            'caja'         => $caja,
            'fecha_ingreso' => $contrato->fecha_ingreso?->toDateString(),
            'salario'      => $base['salario'],
            'residencia'   => trim(($cliente?->municipio?->nombre ?? '').' · '.($cliente?->departamento?->nombre ?? ''), ' ·'),
            'direccion'    => $cliente?->direccion_vivienda,
            'barrio'       => $cliente?->barrio,
            'celular'      => $celular,
            'beneficiarios' => $beneficiarios->count(),
            'usuario_portal' => $cred['usuario'] ?? null,
            'estado_radicado' => $radicado?->estado,
            'numero_radicado' => $radicado?->numero_radicado,
        ];

        return ['problemas' => $problemas, 'avisos' => $avisos, 'resumen' => $resumen, 'portal' => $problemas ? null : [
            'host'         => self::HOST,
            'empresa'      => $rs->razon_social,
            'nit'          => preg_replace('/\D/', '', (string) $rs->nit),
            'tipoDoc'      => self::TIPOS[$tipo],
            'documento'    => (string) $contrato->cedula,
            'apellido'     => (string) $cliente->primer_apellido,
            'departamento' => (string) $cliente->departamento?->nombre,
            'municipio'    => (string) $cliente->municipio?->nombre,
            'barrio'       => (string) $cliente->barrio,
            'direccion'    => (string) $cliente->direccion_vivienda,
            'celular'      => $celular,
            'correo'       => $correo,
            'fechaIngreso' => $contrato->fecha_ingreso->format('Y-m-d'),
            'salario'      => $base['salario'],
            'cargoTexto'   => mb_strtoupper(trim((string) $contrato->cargo)) ?: 'APOYO ADMINISTRATIVO',
        ]];
    }

    /** Usuario (y clave, según permiso) del portal de la razón social. */
    public function credencial(Contrato $contrato): array
    {
        $contrato->loadMissing('razonSocial');
        $cred = EpsClavePortal::para(self::ENTIDAD, '%COMFENALCO%', 'Comfenalco Valle', (string) $contrato->razonSocial?->nit, 'CAJA', self::esDelValle(...));

        return isset($cred['error']) ? $cred : ['usuario' => $cred['usuario'], 'contrasena' => $cred['contrasena'], 'host' => self::HOST];
    }

    /**
     * Registra el resultado del portal en el radicado de caja.
     *
     * $entrada: numero (formulario 1089…), texto (mensaje del portal), error.
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
        $texto  = trim(preg_replace('/\s+/', ' ', (string) ($entrada['texto'] ?? '')));

        if (! empty($entrada['error']) && ! $numero) {
            $mensaje = 'Comfenalco Valle (caja): el portal no radicó la afiliación — '.mb_substr((string) $entrada['error'], 0, 300);
            $this->marcar($radicado, null, Radicado::ESTADO_ERROR, $mensaje, $usuarioId);
            $this->bitacora($contrato, $radicado, 'fallida', null, $texto, (string) $entrada['error'], $usuarioId);

            return ['ok' => false, 'estado' => Radicado::ESTADO_ERROR, 'mensaje' => $mensaje];
        }
        if (! $numero) {
            throw new RuntimeException('Falta el número de formulario que dio el portal.');
        }

        $mensaje = sprintf('Comfenalco Valle (caja): afiliación radicada en la Sucursal Virtual con el formulario N° %s el %s. Comfenalco verifica y responde en máximo 2 días calendario.',
            $numero, now()->format('d/m/Y H:i'));
        $this->marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, $mensaje, $usuarioId);
        $this->bitacora($contrato, $radicado, 'exitosa', $numero, $texto, null, $usuarioId);

        return ['ok' => true, 'estado' => Radicado::ESTADO_TRAMITE, 'numero' => $numero, 'mensaje' => $mensaje];
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
