<?php

namespace App\Services;

use App\Models\ConfiguracionBrynex;
use App\Models\Contrato;
use Illuminate\Support\Facades\DB;

/**
 * Regla única de IVA para la facturación.
 *
 * ¿Quién causa IVA?
 *   • Se cobra IVA si el cliente (`clientes.iva`) **o** su empresa
 *     (`clientes.cod_empresa` → `empresas.iva`) están marcados 'SI'. Basta
 *     con uno de los dos.
 *   • Hasta nov-2026 mandaba la empresa: con la empresa en 'No' se eximía al
 *     cliente aunque él tuviera 'SI'. Eso le quitó el IVA a un cliente ligado a
 *     un canal de referidos («ALIADO-…», NIT 1) que no es su empleador ni recibe
 *     la cuenta de cobro: pagó con IVA y los $10.500 quedaron como saldo a favor
 *     (factura 107981, aliado 4).
 *   • `cod_empresa` puede apuntar a una empresa de otro aliado (ver
 *     Contrato::pagaParafiscales()); esa no cuenta y decide solo el cliente.
 *
 * ¿Sobre qué?
 *   • Planilla    → administración (admon empresa + admon asesor).
 *   • Afiliación  → costo de afiliación (el seguro no es gravado).
 *   • I ACT primer mes (afiliación + planilla en la misma factura)
 *                 → administración + costo de afiliación.
 *
 * Antes de agosto de 2026 la afiliación nunca generaba IVA porque la base
 * siempre era la administración, que en afiliación pura vale 0.
 */
class IvaService
{
    /**
     * "aliado:cedula" => bool, para no repetir la consulta por cada contrato del
     * mismo request. La llave lleva el aliado porque la misma cédula existe en
     * varios aliados con marcas de IVA distintas.
     */
    private static array $cache = [];

    /** ¿Un valor de la columna `iva` (clientes/empresas) significa "sí"? */
    public static function bandera($valor): bool
    {
        return strtoupper(trim((string) ($valor ?? ''))) === 'SI';
    }

    /**
     * ¿El contrato causa IVA? Sí, si el cliente o su empresa están en 'SI'.
     */
    public static function aplicaContrato(?Contrato $contrato): bool
    {
        if (! $contrato || ! $contrato->cedula) {
            return false;
        }

        $llave = self::llave($contrato->aliado_id, $contrato->cedula);
        if (array_key_exists($llave, self::$cache)) {
            return self::$cache[$llave];
        }

        // La relación cliente() ya filtra por el aliado del contrato: obligatorio,
        // porque la misma cédula puede estar en otro aliado con otra marca de IVA.
        $cliente = $contrato->relationLoaded('cliente')
            ? $contrato->cliente
            : $contrato->cliente()->first();

        if (! $cliente) {
            return self::$cache[$llave] = false;
        }

        $empresaId = $cliente->cod_empresa ?? null;

        $ivaEmpresa = $empresaId
            ? DB::table('empresas')
                ->where('id', $empresaId)
                ->where('aliado_id', $contrato->aliado_id)
                ->value('iva')
            : null;

        // Sin fila de empresa en este aliado (cod_empresa de otro aliado, ver
        // Contrato::pagaParafiscales()) decide solo la marca del cliente.
        $aplica = self::bandera($cliente->iva) || self::bandera($ivaEmpresa);

        return self::$cache[$llave] = $aplica;
    }

    private static function llave($aliadoId, $cedula): string
    {
        return ((int) $aliadoId).':'.$cedula;
    }

    /**
     * Mapa cedula => bool para un lote de contratos de UN aliado, en 2 queries.
     * Evita el N+1 de aplicaContrato() dentro de un foreach.
     *
     * El filtro por aliado es obligatorio: la misma cédula existe en varios
     * aliados (con empresa y marca de IVA distintas), y sin él la fila del otro
     * aliado pisa la correcta al indexar por cédula.
     *
     * @param  iterable<int|string>  $cedulas
     * @return array<int|string, bool>
     */
    public static function mapaPorCedulas(int $aliadoId, iterable $cedulas): array
    {
        $cedulas = collect($cedulas)->filter()->unique()->values();
        if ($cedulas->isEmpty()) {
            return [];
        }

        $clientes = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->whereIn('cedula', $cedulas)
            ->select('cedula', 'iva', 'cod_empresa')
            ->get();

        $empresasIva = [];
        $empresaIds = $clientes->pluck('cod_empresa')->filter()->unique()->values();
        if ($empresaIds->isNotEmpty()) {
            $empresasIva = DB::table('empresas')
                ->where('aliado_id', $aliadoId)
                ->whereIn('id', $empresaIds)
                ->pluck('iva', 'id')
                ->map(fn ($v) => self::bandera($v))
                ->toArray();
        }

        $mapa = [];
        foreach ($clientes as $cli) {
            // Mismo criterio que aplicaContrato(): el cliente o su empresa.
            $mapa[$cli->cedula] = self::bandera($cli->iva)
                || (bool) ($empresasIva[$cli->cod_empresa] ?? false);
            self::$cache[self::llave($aliadoId, $cli->cedula)] = $mapa[$cli->cedula];
        }

        return $mapa;
    }

    /** Solo para pruebas: olvida las banderas ya consultadas. */
    public static function limpiarCache(): void
    {
        self::$cache = [];
    }

    /** IVA sobre una base gravada. Redondeo igual que calcularCotizacion(): round. */
    public static function calcular($base, bool $tieneIva = true): int
    {
        if (! $tieneIva || $base <= 0) {
            return 0;
        }

        return (int) round($base * ConfiguracionBrynex::porcentajeIva() / 100);
    }

    /**
     * IVA de una factura completa.
     *
     * @param  int  $admon  administración empresa
     * @param  int  $admonAsesor  administración asesor
     * @param  int  $afiliacion  costo de afiliación cobrado en esta factura
     */
    public static function deFactura(bool $tieneIva, int $admon, int $admonAsesor, int $afiliacion): int
    {
        return self::calcular($admon + $admonAsesor + $afiliacion, $tieneIva);
    }
}
