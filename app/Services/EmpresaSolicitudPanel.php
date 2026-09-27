<?php

namespace App\Services;

use App\Http\Controllers\Portal\PortalTramitesController;
use App\Models\Empresa;
use App\Models\EmpresaAcceso;
use App\Models\EmpresaSolicitud;
use App\Models\Incapacidad;
use App\Models\Tarea;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el detalle de una tarea (admin/tareas) muestra de la solicitud del
 * portal que la abrió: los datos que llenó la empresa en limpio, sus adjuntos
 * y qué puede hacer el equipo según el tipo.
 */
class EmpresaSolicitudPanel
{
    /**
     * @return array{solicitud: ?array, empresa: ?array}
     */
    public function paraTarea(Tarea $tarea, int $aliadoId): array
    {
        return [
            'solicitud' => $this->solicitud($tarea, $aliadoId),
            'empresa' => $this->empresaConPortal($tarea, $aliadoId),
        ];
    }

    /**
     * La empresa que vería los avances de esta tarea: la que la abrió o la del
     * trabajador, siempre que tenga el portal activo. Sin ella, marcar una
     * gestión como visible no tiene a quién mostrarse.
     */
    private function empresaConPortal(Tarea $tarea, int $aliadoId): ?array
    {
        $empresaId = $tarea->empresa_id ?: DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cedula', $tarea->cedula)
            ->value('cod_empresa');

        if (! $empresaId || ! EmpresaAcceso::where('empresa_id', $empresaId)->where('activo', true)->exists()) {
            return null;
        }

        $empresa = Empresa::where('aliado_id', $aliadoId)->find($empresaId);

        return $empresa ? ['id' => $empresa->id, 'nombre' => $empresa->empresa] : null;
    }

    private function solicitud(Tarea $tarea, int $aliadoId): ?array
    {
        $s = EmpresaSolicitud::where('aliado_id', $aliadoId)->where('tarea_id', $tarea->id)->first();
        if (! $s) {
            return null;
        }

        // Abrirla es verla: deja de contar como nueva en el aviso del equipo.
        if (! $s->vista_at) {
            $s->forceFill(['vista_at' => now()])->save();
        }

        $d = $s->datos ?? [];

        return [
            'id' => $s->id,
            'tipo' => $s->tipo,
            'tipo_label' => $s->tipoLabel(),
            'estado' => $s->estado,
            'estado_label' => EmpresaSolicitud::ESTADOS[$s->estado] ?? $s->estado,
            'empresa' => $s->empresa?->empresa,
            'recibida' => $s->created_at?->format('d/m/Y h:i a'),
            'datos' => $this->filas($s, $d),
            'archivos' => collect($d['archivos'] ?? [])->map(fn ($a, $i) => [
                'nombre' => $a['nombre'],
                'etiqueta' => $a['etiqueta'],
                'url' => route('admin.portal_solicitudes.archivo', [$s->id, $i]),
            ])->values(),
            'acciones' => $s->estaPendiente() ? $this->acciones($s, $d, $aliadoId) : null,
        ];
    }

    /** Los datos en pares etiqueta → valor, en el orden en que se leen. */
    private function filas(EmpresaSolicitud $s, array $d): array
    {
        $fecha = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : null;

        $filas = match ($s->tipo) {
            'ingreso' => [
                'Documento' => trim(($d['tipo_doc'] ?? '').' '.($d['cedula'] ?? '')),
                'Nombre' => $d['nombre'] ?? null,
                'Nacimiento' => $fecha($d['fecha_nacimiento'] ?? null),
                'Sexo' => ['M' => 'Masculino', 'F' => 'Femenino'][$d['genero'] ?? ''] ?? null,
                'Celular' => $d['celular'] ?? null,
                'Correo' => $d['correo'] ?? null,
                'Ciudad' => $this->ciudad($d['municipio_id'] ?? null),
                'Dirección' => $d['direccion'] ?? null,
                'Plan pedido' => $d['plan_nombre'] ?? null,
                'Fecha de ingreso' => $fecha($d['fecha_ingreso'] ?? null),
                'Cargo' => $d['cargo'] ?? null,
                'Nota' => $d['observacion'] ?? null,
            ],
            'retiro' => [
                'Trabajador' => $d['nombre'] ?? $s->cedula,
                'Fecha de retiro' => $fecha($d['fecha_retiro'] ?? null),
                'Motivo' => $d['motivo'] ?? null,
            ],
            'incapacidad' => [
                'Trabajador' => $d['nombre'] ?? $s->cedula,
                'Tipo' => Incapacidad::TIPOS_INCAPACIDAD[$d['tipo_incapacidad'] ?? ''] ?? null,
                'Inicio' => $fecha($d['fecha_inicio'] ?? null),
                'Días' => $d['dias'] ?? null,
                'Lo que cuenta' => $d['descripcion'] ?? null,
            ],
            default => [
                'Asunto' => PortalTramitesController::ASUNTOS[$d['asunto'] ?? ''] ?? null,
                'Trabajador' => $d['nombre'] ?? null,
                'Detalle' => $d['descripcion'] ?? null,
            ],
        };

        return collect($filas)->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v, $k) => ['etiqueta' => $k, 'valor' => (string) $v])->values()->all();
    }

    private function acciones(EmpresaSolicitud $s, array $d, int $aliadoId): array
    {
        $acciones = ['rechazar_url' => route('admin.portal_solicitudes.rechazar', $s->id)];

        if ($s->tipo === 'ingreso') {
            $cliente = DB::table('clientes')->where('aliado_id', $aliadoId)->where('cedula', $d['cedula'] ?? '')
                ->first(['id', 'cod_empresa']);

            $acciones += [
                'ruaf_url' => route('admin.portal_solicitudes.ruaf', $s->id),
                'ficha' => $cliente
                    ? ['existe' => true, 'url' => route('admin.clientes.edit', $cliente->id),
                        'otra_empresa' => (int) $cliente->cod_empresa !== (int) $s->empresa_id]
                    : ['existe' => false, 'url' => route('admin.clientes.create', array_filter([
                        'cedula' => $d['cedula'] ?? null,
                        'tipo_doc' => $d['tipo_doc'] ?? null,
                        'primer_nombre' => $d['primer_nombre'] ?? null,
                        'segundo_nombre' => $d['segundo_nombre'] ?? null,
                        'primer_apellido' => $d['primer_apellido'] ?? null,
                        'segundo_apellido' => $d['segundo_apellido'] ?? null,
                        'fecha_nacimiento' => $d['fecha_nacimiento'] ?? null,
                        'genero' => $d['genero'] ?? null,
                        'celular' => $d['celular'] ?? null,
                        'correo' => $d['correo'] ?? null,
                        'departamento_id' => $d['departamento_id'] ?? null,
                        'municipio_id' => $d['municipio_id'] ?? null,
                        'direccion_vivienda' => $d['direccion'] ?? null,
                        'cod_empresa' => $s->empresa_id,
                    ]))],
                'contrato_url' => route('admin.contratos.create', ['cedula' => $d['cedula'] ?? '', 'solicitud' => $s->id]),
            ];
        }

        if ($s->tipo === 'retiro') {
            $acciones += [
                'retiro_url' => route('admin.portal_solicitudes.retiro', $s->id),
                'fecha_retiro' => $d['fecha_retiro'] ?? null,
                'contrato_url' => $s->contrato_id ? route('admin.contratos.edit', $s->contrato_id) : null,
            ];
        }

        if ($s->tipo === 'incapacidad') {
            $acciones['incapacidad_url'] = route('admin.portal_solicitudes.incapacidad', $s->id);
        }

        return $acciones;
    }

    private function ciudad(?int $municipioId): ?string
    {
        if (! $municipioId) {
            return null;
        }

        $c = DB::table('ciudades as c')->leftJoin('departamentos as d', 'd.id', '=', 'c.departamento_id')
            ->where('c.id', $municipioId)->first(['c.nombre', 'd.nombre as depto']);

        return $c ? trim($c->nombre.($c->depto ? ', '.$c->depto : '')) : null;
    }
}
