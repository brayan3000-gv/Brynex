<?php

namespace App\Services;

use App\Models\Bitacora;
use App\Models\Cliente;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige la cédula de una ficha y la lleva a todo lo que cuelga de ella.
 *
 * La cédula es la llave con la que contratos, facturas, anticipos,
 * beneficiarios, incapacidades y tareas se enlazan con la ficha. Cambiarla
 * solo en `clientes` —lo que hacía el formulario— dejaba todo eso con el
 * número viejo: el contrato 56196 quedó vigente y "(sin cliente)" cuando la
 * ficha 31548 pasó de 6303602 a 6203602 (25-ago-2026), y el 54642 del aliado
 * 7 igual con 31176726 → 31178726 (3-jul-2026).
 *
 * Lo que ya salió del sistema con el número viejo —planillas pagadas,
 * radicados ante la entidad, movimientos en ARL Sura— no se reescribe: es el
 * registro de lo que se reportó. Se avisa, para que alguien lo corrija allá.
 */
class CorregirCedulaService
{
    /**
     * Tablas que siguen a la ficha: tabla => columna con la cédula.
     * Todas se filtran además por aliado_id.
     */
    public const VINCULADAS = [
        'contratos'          => 'cedula',
        'facturas'           => 'cedula',
        'anticipos'          => 'cedula',
        'saldo_ajustes'      => 'cedula',
        'beneficiarios'      => 'cc_cliente',
        'documentos_cliente' => 'cc_cliente',
        'clave_accesos'      => 'cedula',
        'incapacidades'      => 'cedula_usuario',
        'tareas'             => 'cedula',
        'marketing_contactos'=> 'cedula',
    ];

    private const ETIQUETAS = [
        'contratos'          => 'contratos',
        'facturas'           => 'facturas',
        'anticipos'          => 'anticipos',
        'saldo_ajustes'      => 'ajustes de saldo',
        'beneficiarios'      => 'beneficiarios',
        'documentos_cliente' => 'documentos',
        'clave_accesos'      => 'claves',
        'incapacidades'      => 'incapacidades',
        'tareas'             => 'tareas',
        'marketing_contactos'=> 'contactos de marketing',
    ];

    /** Cuántos registros de cada tabla cuelgan de la cédula actual de la ficha. */
    public function vinculados(Cliente $cliente): array
    {
        $conteo = [];
        foreach (self::VINCULADAS as $tabla => $columna) {
            $n = DB::table($tabla)
                ->where('aliado_id', $cliente->aliado_id)
                ->where($columna, (string) $cliente->cedula)
                ->count();
            if ($n > 0) {
                $conteo[$tabla] = $n;
            }
        }

        return $conteo;
    }

    public function tieneVinculados(Cliente $cliente): bool
    {
        return $this->vinculados($cliente) !== [];
    }

    /**
     * Lo que ya salió con el número viejo y no se va a reescribir.
     *
     * @return string[] avisos legibles
     */
    public function avisosExternos(Cliente $cliente): array
    {
        $aliadoId = $cliente->aliado_id;
        $cedula = (string) $cliente->cedula;
        $avisos = [];

        $contratoIds = DB::table('contratos')
            ->where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->pluck('id');

        if ($contratoIds->isNotEmpty()) {
            $planillas = DB::table('planos')
                ->where('aliado_id', $aliadoId)
                ->whereIn('contrato_id', $contratoIds)
                ->where('no_identifi', $cedula)
                ->whereNull('deleted_at')
                ->count();
            if ($planillas > 0) {
                $avisos[] = "{$planillas} planilla(s) ya se reportaron a PILA con el número {$cedula}. "
                    .'Las siguientes salen con el nuevo; las pagadas se corrigen ante el operador si hace falta.';
            }

            // Un radicado cuenta como enviado si tiene soporte, número o
            // fecha de trámite, aunque después lo hayan marcado en error: así
            // pasó con la EPS SURA del 56196.
            $radicados = DB::table('radicados')
                ->where('aliado_id', $aliadoId)
                ->whereIn('contrato_id', $contratoIds)
                ->where(fn ($q) => $q->whereNotNull('ruta_pdf')
                    ->orWhereNotNull('numero_radicado')
                    ->orWhereNotNull('fecha_inicio_tramite')
                    ->orWhereIn('estado', ['tramite', 'traslado', 'ok']))
                ->get(['contrato_id', 'tipo', 'estado']);
            foreach ($radicados as $r) {
                $avisos[] = 'El radicado de '.strtoupper($r->tipo)." del contrato {$r->contrato_id} "
                    ."({$r->estado}) se envió a la entidad con el número {$cedula}: revisa allá que no quede una afiliación con el número errado.";
            }

            $arl = DB::table('arl_afiliaciones')
                ->where('aliado_id', $aliadoId)
                ->where('cedula', $cedula)
                ->where('estado', 'exitosa')
                ->count();
            if ($arl > 0) {
                $avisos[] = "{$arl} movimiento(s) en la ARL por API quedaron con el número {$cedula}.";
            }
        }

        return $avisos;
    }

    /** Resumen para mostrar antes de confirmar. */
    public function impacto(Cliente $cliente): array
    {
        $vinculados = [];
        foreach ($this->vinculados($cliente) as $tabla => $n) {
            $vinculados[] = ['tabla' => $tabla, 'etiqueta' => self::ETIQUETAS[$tabla], 'n' => $n];
        }

        return [
            'cedula'     => (string) $cliente->cedula,
            'vinculados' => $vinculados,
            'avisos'     => $this->avisosExternos($cliente),
        ];
    }

    /**
     * Cambia la cédula en la ficha y en todo lo vinculado, en una sola
     * transacción.
     *
     * @return array{de:string,a:string,movidos:array<string,int>,avisos:string[]}
     */
    public function corregir(Cliente $cliente, string $nueva, string $motivo): array
    {
        $nueva = trim($nueva);
        $vieja = (string) $cliente->cedula;
        $aliadoId = $cliente->aliado_id;

        if (! preg_match('/^\d{3,15}$/', $nueva)) {
            throw ValidationException::withMessages(['cedula' => 'La cédula nueva debe tener solo números (entre 3 y 15 dígitos).']);
        }
        if ($nueva === $vieja) {
            throw ValidationException::withMessages(['cedula' => 'La cédula nueva es igual a la actual.']);
        }
        $otra = Cliente::where('aliado_id', $aliadoId)
            ->where('cedula', $nueva)
            ->where('id', '!=', $cliente->id)
            ->first(['id']);
        if ($otra) {
            throw ValidationException::withMessages(['cedula' => "La cédula {$nueva} ya es de otra ficha de este aliado (ID #{$otra->id})."]);
        }

        // Se calculan antes de mover nada: después ya no hay registros con la vieja.
        $avisos = $this->avisosExternos($cliente);

        $movidos = DB::transaction(function () use ($cliente, $vieja, $nueva, $aliadoId) {
            $movidos = [];
            foreach (self::VINCULADAS as $tabla => $columna) {
                $n = DB::table($tabla)
                    ->where('aliado_id', $aliadoId)
                    ->where($columna, $vieja)
                    ->update([$columna => $nueva]);
                if ($n > 0) {
                    $movidos[$tabla] = $n;
                }
            }

            // Por el query builder: el observer registraría un "Actualizó
            // cliente" suelto, sin decir que se movió todo lo demás.
            DB::table('clientes')
                ->where('id', $cliente->id)
                ->where('aliado_id', $aliadoId)
                ->update(['cedula' => $nueva, 'updated_at' => now()]);

            return $movidos;
        });

        Bitacora::registrar(
            'cedula_corregida',
            'Cliente',
            $cliente->id,
            "Corrigió la cédula {$vieja} → {$nueva} — {$cliente->primer_nombre} {$cliente->primer_apellido}",
            ['cedula' => ['de' => $vieja, 'a' => $nueva], 'motivo' => $motivo, 'movidos' => $movidos, 'avisos' => $avisos],
            $aliadoId
        );

        $cliente->cedula = $nueva;

        return ['de' => $vieja, 'a' => $nueva, 'movidos' => $movidos, 'avisos' => $avisos];
    }
}
