<?php

namespace App\Services\Caja;

use App\Models\DocumentoCliente;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Genera el certificado de afiliación a la caja Comfenalco de un trabajador y lo
 * guarda como documento del cliente.
 *
 * El robot (ComfenalcoSubsidiosHeadless::certificado) entra al portal por empresa
 * y trae el PDF en base64; aquí se decodifica, se guarda al disco `local` —nunca
 * a `public`: es un dato personal— y se registra en documentos_cliente, igual que
 * los demás anexos de la caja.
 *
 * Lo invoca ComfenalcoCertificadoJob (tarda 1–2 min, no cabe en una petición).
 */
class ComfenalcoCertificadoService
{
    public function __construct(private ComfenalcoSubsidiosHeadless $portal) {}

    /**
     * Pide el certificado al portal y, si llega, lo guarda como DocumentoCliente.
     *
     * Devuelve, para el caché que sondea el modal:
     *   ok=true  → {ok, empresa, documento_id, nombre}
     *   ok=false → {ok, motivo?, error}  (motivo 'cupo' | 'no_afiliado')
     */
    public function generar(int $aliadoId, string $nit, string $tipoDoc, string $cedula, ?int $userId): array
    {
        $cedula = ltrim(preg_replace('/\D/', '', $cedula), '0');

        $r = $this->portal->certificado($nit, $tipoDoc, $cedula);

        if (! ($r['ok'] ?? false)) {
            // Trae motivo ('cupo' | 'no_afiliado') y un error ya redactado.
            return $r;
        }

        $guardado = $this->guardar($aliadoId, $cedula, (string) ($r['encodedString'] ?? ''), $r['namePdf'] ?? null, $userId);

        return $guardado + ['empresa' => $r['empresa'] ?? null];
    }

    /** Decodifica el base64, lo valida como PDF y lo registra en documentos_cliente. */
    private function guardar(int $aliadoId, string $cedula, string $base64, ?string $nombrePortal, ?int $userId): array
    {
        $bin = base64_decode($base64, true);

        if ($bin === false || ! str_starts_with($bin, '%PDF')) {
            Log::warning('Comfenalco certificado: la respuesta no es un PDF válido', ['aliado' => $aliadoId, 'cedula' => $cedula]);

            return ['ok' => false, 'error' => 'El certificado que devolvió Comfenalco no es un PDF válido.'];
        }

        $nombre = $nombrePortal ?: "certificado_afiliacion_comfenalco_{$cedula}.pdf";
        if (! str_ends_with(mb_strtolower($nombre), '.pdf')) {
            $nombre .= '.pdf';
        }

        $ruta = "documentos/{$aliadoId}/{$cedula}/cert_afiliacion_comfenalco_".time().'_'.Str::random(6).'.pdf';
        Storage::disk('local')->put($ruta, $bin);

        $doc = DocumentoCliente::create([
            'aliado_id' => $aliadoId,
            'cc_cliente' => $cedula,
            'doc_beneficiario' => null,
            'tipo_documento' => 'cert_afiliacion_caja',
            'nombre_archivo' => $nombre,
            'ruta' => $ruta,
            'subido_por' => $userId,
        ]);

        return ['ok' => true, 'documento_id' => $doc->id, 'nombre' => $nombre];
    }
}
