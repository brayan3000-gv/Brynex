<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\EmpresaAcceso;
use App\Models\EmpresaSolicitud;
use App\Models\Tarea;
use App\Models\TareaGestion;
use App\Models\TareaSemaforoConfig;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Las solicitudes que manda una empresa desde su portal.
 *
 * Cada una abre una tarea en el módulo de tareas, asignada al encargado de la
 * empresa (o a quien le dio el acceso, si no tiene). El equipo la trabaja allí
 * como cualquier otra; lo que marque «visible para la empresa» le sale a la
 * empresa como avance. Cuando la tarea se cierra, la solicitud queda aprobada
 * o rechazada.
 */
class EmpresaSolicitudService
{
    /** Carpeta de los adjuntos, en el disco `local`: son cédulas y datos de salud. */
    public const DISCO = 'local';

    /**
     * @param  array<string, UploadedFile|UploadedFile[]>  $archivos  por etiqueta (cedula, incapacidad, …)
     */
    public function crear(
        EmpresaAcceso $acceso,
        string $tipo,
        array $datos,
        array $archivos,
        string $descripcion,
        ?Contrato $contrato = null,
        ?string $cedula = null,
        ?string $tipoDoc = null,
    ): EmpresaSolicitud {
        $empresa = $acceso->empresa;
        $encargado = $this->encargadoPara($empresa, $acceso);

        return DB::transaction(function () use ($acceso, $empresa, $tipo, $datos, $archivos, $descripcion, $contrato, $cedula, $tipoDoc, $encargado) {
            $solicitud = EmpresaSolicitud::create([
                'aliado_id' => $acceso->aliado_id,
                'empresa_id' => $acceso->empresa_id,
                'empresa_acceso_id' => $acceso->id,
                'tipo' => $tipo,
                'estado' => 'pendiente',
                'tipo_doc' => $tipoDoc,
                'cedula' => $cedula ?? $contrato?->cedula,
                'contrato_id' => $contrato?->id,
                'datos' => $datos,
            ]);

            $guardados = [];
            foreach ($archivos as $etiqueta => $lista) {
                foreach ((is_array($lista) ? $lista : [$lista]) as $archivo) {
                    if (! $archivo instanceof UploadedFile) {
                        continue;
                    }
                    $ruta = app(CompresorDocumentoService::class)->guardar(
                        $archivo,
                        "portal/{$acceso->aliado_id}/{$acceso->empresa_id}/{$solicitud->id}",
                        self::DISCO
                    );
                    $guardados[] = [
                        'etiqueta' => $etiqueta,
                        'nombre' => $this->nombreSeguro($archivo->getClientOriginalName()),
                        'ruta' => $ruta,
                    ];
                }
            }

            $tipoTarea = EmpresaSolicitud::TIPO_TAREA[$tipo];
            $tarea = Tarea::create([
                'aliado_id' => $acceso->aliado_id,
                'empresa_id' => $acceso->empresa_id,
                'tipo' => $tipoTarea,
                'estado' => Tarea::ESTADO_PENDIENTE,
                // La columna no admite null: una solicitud general no es de nadie.
                'cedula' => (string) ($solicitud->cedula ?? ''),
                'contrato_id' => $contrato?->id,
                'razon_social_id' => $contrato?->razon_social_id,
                'tarea' => mb_substr($descripcion, 0, 3900),
                'observacion' => 'Enviada por '.$empresa->empresa.' desde su portal.',
                'encargado_id' => $encargado,
                'creado_por' => $encargado,
                'fecha_limite' => TareaSemaforoConfig::fechaLimiteParaTipo($tipoTarea, $acceso->aliado_id)
                    ?? now()->addDays(3),
            ]);

            TareaGestion::create([
                'tarea_id' => $tarea->id,
                'user_id' => $encargado,
                'tipo_accion' => 'nota',
                'observacion' => 'Recibimos tu solicitud. La estamos revisando.',
                'visible_empresa' => true,
                'estado_tarea' => Tarea::ESTADO_PENDIENTE,
                'created_at' => now(),
            ]);

            $solicitud->forceFill([
                'tarea_id' => $tarea->id,
                'datos' => array_merge($datos, ['archivos' => $guardados]),
            ])->save();

            return $solicitud;
        });
    }

    /**
     * Cierra la solicitud y su tarea con un mensaje que ve la empresa.
     *
     * @param  string  $estado  aprobada | rechazada
     */
    public function resolver(EmpresaSolicitud $solicitud, string $estado, string $mensaje, ?int $resultadoId = null): void
    {
        DB::transaction(function () use ($solicitud, $estado, $mensaje, $resultadoId) {
            $solicitud->forceFill([
                'estado' => $estado,
                'resultado_id' => $resultadoId ?? $solicitud->resultado_id,
                'atendida_por' => Auth::id(),
                'atendida_at' => now(),
                'vista_at' => $solicitud->vista_at ?? now(),
            ])->save();

            $tarea = $solicitud->tarea;
            if (! $tarea || $tarea->estado === Tarea::ESTADO_CERRADA) {
                return;
            }

            $positivo = $estado === 'aprobada';
            TareaGestion::create([
                'tarea_id' => $tarea->id,
                'user_id' => Auth::id() ?? $tarea->encargado_id,
                'tipo_accion' => 'cambio_estado',
                'observacion' => ($positivo ? '✅ ' : '❌ ').$mensaje,
                'visible_empresa' => true,
                'estado_tarea' => Tarea::ESTADO_CERRADA,
                'created_at' => now(),
            ]);

            $tarea->update([
                'estado' => Tarea::ESTADO_CERRADA,
                'resultado' => $positivo ? 'positivo' : 'negativo',
            ]);
        });
    }

    /**
     * Cuando el equipo cierra la tarea por el camino normal, la solicitud sigue
     * el resultado: positiva = aprobada, negativa = rechazada.
     */
    public function alCerrarTarea(Tarea $tarea, string $resultado): void
    {
        $solicitud = EmpresaSolicitud::where('tarea_id', $tarea->id)->first();
        if (! $solicitud || ! $solicitud->estaPendiente()) {
            return;
        }

        $solicitud->forceFill([
            'estado' => $resultado === 'positivo' ? 'aprobada' : 'rechazada',
            'atendida_por' => Auth::id(),
            'atendida_at' => now(),
            'vista_at' => $solicitud->vista_at ?? now(),
        ])->save();
    }

    /**
     * Las solicitudes que nadie del equipo ha abierto y le tocan a este
     * usuario: las de las empresas que tiene a cargo, y las de las empresas sin
     * encargado, que le tocan a todos.
     */
    public function nuevasPara(User $usuario, int $aliadoId, int $limite = 5): array
    {
        $base = DB::table('empresa_solicitudes as s')
            ->join('empresas as e', 'e.id', '=', 's.empresa_id')
            ->leftJoin('tareas as t', 't.id', '=', 's.tarea_id')
            ->where('s.aliado_id', $aliadoId)
            ->where('s.estado', 'pendiente')
            ->whereNull('s.vista_at')
            ->where(fn ($q) => $q->where('t.encargado_id', $usuario->id)->orWhereNull('e.encargado_id'));

        $total = (clone $base)->count();
        $ultimas = $total ? (clone $base)
            ->orderByDesc('s.id')
            ->limit($limite)
            ->get(['s.id', 's.tipo', 's.tarea_id', 's.created_at', 'e.empresa'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'tarea_id' => (int) $r->tarea_id,
                'empresa' => $r->empresa,
                'tipo' => EmpresaSolicitud::TIPOS[$r->tipo] ?? $r->tipo,
            ])->all() : [];

        return ['total' => $total, 'ultimas' => $ultimas];
    }

    /**
     * A quién le llega: el encargado de la empresa si lo tiene y sigue activo;
     * si no, quien le dio el acceso al portal (el superadmin del aliado).
     */
    public function encargadoPara(Empresa $empresa, EmpresaAcceso $acceso): int
    {
        foreach ([$empresa->encargado_id, $acceso->creado_por] as $candidato) {
            if ($candidato && User::whereKey($candidato)->where('activo', true)->exists()) {
                return (int) $candidato;
            }
        }

        return TareaAutomaticaService::USUARIO_SISTEMA;
    }

    /**
     * El nombre del archivo como lo ve el equipo. Viene de afuera y se pinta en
     * el panel: solo letras, números y signos inofensivos.
     */
    private function nombreSeguro(string $nombre): string
    {
        $limpio = preg_replace('/[^\pL\pN ._()-]+/u', '_', $nombre);

        return mb_substr(trim($limpio) ?: 'archivo', 0, 120);
    }
}
