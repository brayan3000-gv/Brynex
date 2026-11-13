<?php

namespace App\Services\Demo;

use App\Models\Arl;
use App\Models\Bitacora;
use App\Models\Caja;
use App\Models\Contrato;
use App\Models\Radicado;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La radicación por API de la demo: hace lo mismo que el trámite real ante la
 * ARL, la EPS, la caja o el fondo —revisa los datos, arma lo que se enviaría,
 * deja el radicado en OK con su soporte— pero no le habla a nadie: el número es
 * inventado y el PDF dice MUESTRA en cada página.
 *
 * Sirve para cualquier entidad, no solo las que BryNex automatiza de verdad,
 * porque el prospecto inscribe a su gente en la que quiera.
 */
class RadicacionSimulada
{
    private Contrato $contrato;

    public function __construct(private Radicado $radicado)
    {
        $this->contrato = $radicado->contrato()
            ->with(['cliente', 'razonSocial', 'eps', 'pension', 'arl', 'caja', 'tipoModalidad'])
            ->firstOrFail();
    }

    public static function para(int $radicadoId): self
    {
        return new self(Radicado::paraTramite($radicadoId));
    }

    public function radicado(): Radicado
    {
        return $this->radicado;
    }

    /** La entidad ante la que se radica: ['tipo', 'nombre', 'nit']. */
    public function entidad(): array
    {
        $c = $this->contrato;
        $rs = $c->razonSocial;

        $e = match ($this->radicado->tipo) {
            Radicado::TIPO_ARL => $c->arl ?: ($rs?->arl_nit ? Arl::where('nit', $rs->arl_nit)->first() : null),
            Radicado::TIPO_EPS => $c->eps ?: $c->cliente?->eps,
            Radicado::TIPO_CAJA => $c->caja ?: ($rs?->caja_nit ? Caja::where('nit', $rs->caja_nit)->first() : null),
            Radicado::TIPO_PENSION => $c->pension ?: $c->cliente?->pension,
            default => null,
        };

        $etiqueta = [
            Radicado::TIPO_ARL => 'ARL', Radicado::TIPO_EPS => 'EPS',
            Radicado::TIPO_CAJA => 'Caja de compensación', Radicado::TIPO_PENSION => 'Fondo de pensión',
        ][$this->radicado->tipo] ?? strtoupper((string) $this->radicado->tipo);

        return [
            'tipo' => $this->radicado->tipo,
            'etiqueta' => $etiqueta,
            'nombre' => trim((string) ($e?->nombre ?? $e?->razon_social ?? '')) ?: $etiqueta.' sin definir',
            'nit' => $e?->nit,
            'definida' => (bool) $e,
        ];
    }

    /**
     * Lo que le falta al contrato para radicar. Son los mismos datos que piden
     * las entidades de verdad, para que la demo enseñe también esa parte.
     */
    public function problemas(): array
    {
        $c = $this->contrato;
        $cl = $c->cliente;
        $p = [];

        if (! $this->entidad()['definida']) {
            $p[] = 'El contrato no tiene '.$this->entidad()['etiqueta'].' asignada.';
        }
        if (! $cl?->fecha_nacimiento) {
            $p[] = 'Falta la fecha de nacimiento del trabajador.';
        }
        if (! $cl?->genero) {
            $p[] = 'Falta el sexo del trabajador.';
        }
        if (! trim((string) $cl?->direccion_vivienda)) {
            $p[] = 'Falta la dirección de residencia.';
        }
        if (! $c->fecha_ingreso) {
            $p[] = 'Falta la fecha de ingreso del contrato.';
        }
        if (! (float) ($c->ibc ?: $c->salario)) {
            $p[] = 'Falta el salario o IBC.';
        }
        if ($this->radicado->tipo === Radicado::TIPO_ARL && ! (int) $c->n_arl) {
            $p[] = 'Falta el nivel de riesgo ARL.';
        }

        return $p;
    }

    /** Fecha de cobertura que se propone: mañana, o el ingreso si es posterior. */
    public function fechaSugerida(): string
    {
        $manana = now()->addDay()->startOfDay();
        $ingreso = $this->contrato->fecha_ingreso;

        return ($ingreso && $ingreso->gt($manana) ? $ingreso : $manana)->toDateString();
    }

    /** Resumen para el modal, igual al de los trámites reales. */
    public function resumen(): array
    {
        $c = $this->contrato;

        return [
            'trabajador' => $this->nombreTrabajador(),
            'documento' => trim(($c->cliente?->tipo_doc ?: 'CC').' '.$c->cedula),
            'razon_social' => $c->razonSocial?->razon_social,
            'nit_empleador' => $c->razonSocial?->nit,
            'modalidad' => $c->tipoModalidad?->tipo_modalidad,
            'ibc' => (int) ($c->ibc ?: $c->salario),
            'cargo' => $c->cargo,
            'nivel_riesgo' => $c->n_arl,
            'fecha_ingreso' => $c->fecha_ingreso?->format('d/m/Y'),
        ];
    }

    /**
     * Los pasos que el robot o el API hacen de verdad, para mostrarlos uno a
     * uno. Ninguno se ejecuta: el modal los recorre y al final llama a radicar().
     */
    public function pasos(): array
    {
        $n = $this->entidad()['nombre'];

        return match ($this->radicado->tipo) {
            Radicado::TIPO_ARL => [
                "Abriendo sesión con el API de {$n}",
                'Validando documento, sexo y fecha de nacimiento del trabajador',
                'Consultando si ya tiene una cobertura vigente',
                'Ubicando el centro de trabajo y la tasa del riesgo '.((int) $this->contrato->n_arl ?: '—'),
                'Enviando la afiliación (POST /trabajador/afiliar)',
                'Descargando el certificado de afiliación',
                'Dejando el radicado en OK con el soporte adjunto',
            ],
            Radicado::TIPO_EPS => [
                "Entrando al portal de empleadores de {$n}",
                'Consultando al afiliado en la BDUA',
                'Llenando la novedad de ingreso con los datos del contrato',
                'Adjuntando el formulario de afiliación firmado',
                'Radicando la novedad',
                'Descargando la constancia de radicación',
                'Dejando el radicado en OK con el soporte adjunto',
            ],
            default => [
                "Entrando a la sucursal virtual de {$n}",
                'Validando los datos del trabajador y del empleador',
                'Registrando la afiliación',
                'Descargando la constancia',
                'Dejando el radicado en OK con el soporte adjunto',
            ],
        };
    }

    /** Lo que se le enviaría a la entidad: se muestra en el modal, no se envía. */
    public function payload(?string $fechaCobertura = null): array
    {
        $c = $this->contrato;
        $cl = $c->cliente;

        $trabajador = array_filter([
            'tipoDocumento' => $cl?->tipo_doc ?: 'CC',
            'numeroDocumento' => (string) $c->cedula,
            'primerNombre' => $cl?->primer_nombre,
            'segundoNombre' => $cl?->segundo_nombre,
            'primerApellido' => $cl?->primer_apellido,
            'segundoApellido' => $cl?->segundo_apellido,
            'sexo' => $cl?->genero,
            'fechaNacimiento' => $cl?->fecha_nacimiento ? Carbon::parse($cl->fecha_nacimiento)->format('d/m/Y') : null,
            'direccion' => $cl?->direccion_vivienda,
        ], fn ($v) => $v !== null && $v !== '');

        $base = [
            'empleador' => [
                'tipoDocumento' => 'NI',
                'numeroDocumento' => (string) $c->razonSocial?->nit,
                'razonSocial' => $c->razonSocial?->razon_social,
            ],
            'trabajador' => $trabajador,
            'fechaIngreso' => $c->fecha_ingreso?->format('d/m/Y'),
            'salario' => (int) ($c->ibc ?: $c->salario),
        ];

        return match ($this->radicado->tipo) {
            Radicado::TIPO_ARL => $base + [
                'tipoAfiliado' => $c->razonSocial?->es_independiente ? 'I' : 'D',
                'nivelRiesgo' => (int) $c->n_arl,
                'cargo' => $c->cargo,
                'fechaInicioCobertura' => Carbon::parse($fechaCobertura ?: $this->fechaSugerida())->format('d/m/Y'),
            ],
            Radicado::TIPO_EPS => $base + ['tipoNovedad' => 'ING', 'descripcion' => 'Inicio de relación laboral'],
            default => $base + ['tipoNovedad' => 'ING'],
        };
    }

    /**
     * Deja el radicado como lo deja el trámite real: en OK, con número y con el
     * PDF adjunto. Para la ARL, además, mueve la fecha de cobertura del contrato
     * (es lo que pinta el semáforo de Gestión ARL).
     */
    public function radicar(?string $fechaCobertura, ?int $usuarioId): array
    {
        $entidad = $this->entidad();
        $fecha = Carbon::parse($fechaCobertura ?: $this->fechaSugerida())->startOfDay();
        $numero = 'DEMO-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $c = $this->contrato;

        $pdf = Pdf::loadView('demo.pdf.radicado', [
            'entidad' => $entidad,
            'numero' => $numero,
            'resumen' => $this->resumen(),
            'payload' => $this->payload($fecha->toDateString()),
            'fechaCobertura' => $fecha,
            'ahora' => now(),
            'tipo' => $this->radicado->tipo,
        ])->setPaper('letter')->setOption('isFontSubsettingEnabled', true)->output();

        $ruta = "radicados/{$c->aliado_id}/{$c->id}/{$c->cedula}/".Str::slug($this->radicado->tipo).'_demo_'.now()->format('Ymd_His').'.pdf';
        Storage::disk('local')->put($ruta, $pdf);

        if ($this->radicado->ruta_pdf && $this->radicado->ruta_pdf !== $ruta) {
            Storage::disk('local')->delete($this->radicado->ruta_pdf);
        }

        $this->radicado->fill([
            'estado' => Radicado::ESTADO_OK,
            'numero_radicado' => $numero,
            'canal_envio' => Radicado::CANAL_WEB,
            'ruta_pdf' => $ruta,
            'fecha_confirmacion' => now(),
            'confirmado_por' => 'demo',
            'confirmado_en' => now(),
            'user_id' => $usuarioId,
            'observacion' => "Radicación SIMULADA en {$entidad['nombre']} (demo de BryNex, no se envió nada).".
                ($this->radicado->tipo === Radicado::TIPO_ARL ? ' Cobertura desde '.$fecha->format('d/m/Y').'.' : ''),
        ])->save();

        if ($this->radicado->tipo === Radicado::TIPO_ARL) {
            $c->update(['fecha_arl' => $fecha->toDateString()]);
        }

        Bitacora::registrar(
            'updated',
            'Contrato',
            $c->id,
            "Radicación simulada ({$entidad['etiqueta']} {$entidad['nombre']}): {$numero}",
            ['radicado_id' => $this->radicado->id, 'demo' => true],
            (int) $c->aliado_id
        );

        return [
            'numero' => $numero,
            'ruta' => $ruta,
            'fecha_cobertura' => $fecha->format('d/m/Y'),
            'respuesta' => [
                'codigoRespuesta' => '00',
                'mensaje' => 'Transacción exitosa (SIMULADA)',
                'codigoTransaccion' => $numero,
                'fechaProceso' => now()->format('d/m/Y H:i:s'),
            ],
        ];
    }

    /** Los pasos de anular o retirar, para que el modal los muestre como en radicar. */
    public function pasosAnulacion(string $operacion): array
    {
        $n = $this->entidad()['nombre'];

        return $operacion === 'anular' ? [
            "Abriendo sesión con el API de {$n}",
            'Buscando la cobertura creada para este trabajador',
            'Revisando que esté dentro de los 30 días para anular',
            'Anulando el ingreso',
            'Descargando la constancia de anulación',
            'Reabriendo el radicado para volver a afiliar',
        ] : [
            "Abriendo sesión con el API de {$n}",
            'Buscando la cobertura vigente del trabajador',
            'Reportando la novedad de retiro (RET) con la fecha de fin',
            'Descargando la constancia de retiro',
            'Dejando la constancia en el radicado',
        ];
    }

    /**
     * Anular (deshacer la afiliación, como si nunca hubiera existido) o retirar
     * (cerrar la cobertura en una fecha). Solo ARL, que es donde BryNex lo hace
     * por API de verdad.
     */
    public function anular(string $operacion, ?string $fecha, ?int $usuarioId): array
    {
        abort_unless($this->radicado->tipo === Radicado::TIPO_ARL, 422, 'Solo se anula o retira en la ARL.');

        $entidad = $this->entidad();
        $c = $this->contrato;
        $fin = Carbon::parse($fecha ?: now()->toDateString())->startOfDay();
        $numero = 'DEMO-'.($operacion === 'anular' ? 'ANU' : 'RET').'-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $pdf = Pdf::loadView('demo.pdf.radicado', [
            'entidad' => $entidad,
            'numero' => $numero,
            'resumen' => $this->resumen(),
            'payload' => ['operacion' => $operacion === 'anular' ? 'ANULACION_INGRESO' : 'RETIRO',
                'trabajador' => $this->payload()['trabajador'], 'empleador' => $this->payload()['empleador']]
                + ($operacion === 'retirar' ? ['fechaFinCobertura' => $fin->format('d/m/Y')] : []),
            'fechaCobertura' => $fin,
            'ahora' => now(),
            'tipo' => $this->radicado->tipo,
            'operacion' => $operacion,
        ])->setPaper('letter')->setOption('isFontSubsettingEnabled', true)->output();

        $ruta = "radicados/{$c->aliado_id}/{$c->id}/{$c->cedula}/arl_{$operacion}_demo_".now()->format('Ymd_His').'.pdf';
        Storage::disk('local')->put($ruta, $pdf);

        if ($operacion === 'anular') {
            $this->radicado->fill([
                'estado' => Radicado::ESTADO_PENDIENTE,
                'numero_radicado' => null,
                'ruta_pdf' => $ruta,
                'user_id' => $usuarioId,
                'observacion' => "Anulación SIMULADA en {$entidad['nombre']} ({$numero}). El radicado quedó abierto para volver a afiliar.",
            ])->save();
            $c->update(['fecha_arl' => null]);
        } else {
            $this->radicado->fill([
                'ruta_pdf' => $ruta,
                'user_id' => $usuarioId,
                'observacion' => "Retiro SIMULADO en {$entidad['nombre']} ({$numero}): cobertura hasta el ".$fin->format('d/m/Y').'.',
            ])->save();
        }

        Bitacora::registrar('updated', 'Contrato', $c->id,
            ($operacion === 'anular' ? 'Anulación' : 'Retiro')." simulado en {$entidad['nombre']}: {$numero}",
            ['radicado_id' => $this->radicado->id, 'demo' => true], (int) $c->aliado_id);

        return ['numero' => $numero, 'fecha' => $fin->format('d/m/Y'), 'operacion' => $operacion];
    }

    private function nombreTrabajador(): string
    {
        $cl = $this->contrato->cliente;

        return trim(collect([$cl?->primer_nombre, $cl?->segundo_nombre, $cl?->primer_apellido, $cl?->segundo_apellido])
            ->filter()->implode(' '));
    }
}
