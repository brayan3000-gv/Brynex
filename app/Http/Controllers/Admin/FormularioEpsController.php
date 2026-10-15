<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\FormularioEpsService;
use Illuminate\Http\Request;

/**
 * El contrato llega como id y se resuelve con {@see Contrato::paraTramite()}:
 * con el binding implícito cualquier usuario veía el formulario (datos
 * personales) o pisaba la firma de un contrato de otro aliado cambiando el id.
 */
class FormularioEpsController extends Controller
{
    public function __construct(protected FormularioEpsService $service) {}

    /**
     * Vista de impresión del formulario de EPS.
     * GET /admin/afiliaciones/{contrato}/formulario/eps
     */
    public function vista(int $contrato)
    {
        return $this->vistaFormulario(Contrato::paraTramite($contrato), 'eps');
    }

    /**
     * Vista de impresión del formulario del fondo de pensión (COLPENSIONES y demás).
     * GET /admin/afiliaciones/{contrato}/formulario/pension
     */
    public function vistaPension(int $contrato)
    {
        return $this->vistaFormulario(Contrato::paraTramite($contrato), 'pension');
    }

    /**
     * Vista de impresión del formulario de la caja de compensación (COMFABOY y demás).
     * GET /admin/afiliaciones/{contrato}/formulario/caja
     */
    public function vistaCaja(int $contrato)
    {
        return $this->vistaFormulario(Contrato::paraTramite($contrato), 'caja');
    }

    /**
     * Arma la vista de impresión: muestra el PDF en un iframe con el botón de
     * imprimir. El único cambio entre EPS, pensión y caja es de quién sale el mapeo.
     */
    protected function vistaFormulario(Contrato $contrato, string $tipo)
    {
        $conBeneficiarios = request()->boolean('beneficiarios', false);

        $contrato->loadMissing([
            'cliente.municipio',
            'cliente.departamento',
            'cliente.beneficiarios',
            'razonSocial',
            'eps',
            'arl',
            'pension',
            'caja',
        ]);

        $cliente = $contrato->cliente;
        $entidad = match ($tipo) {
            'pension' => $contrato->pension,
            'caja' => $contrato->caja,
            default => $contrato->eps,
        };
        $tituloEntidad = match ($tipo) {
            'pension' => $contrato->pension?->razon_social ?? 'Pensión',
            'caja' => $contrato->caja?->nombre ?? 'Caja',
            default => $contrato->eps?->nombre ?? 'EPS',
        };

        $nombreCompleto = $cliente?->nombre_completo ?? '—';
        $empresa = $contrato->razonSocial?->razon_social ?? '—';
        $fechaIngreso = $contrato->fecha_ingreso?->format('d/m/Y') ?? '—';
        $salario = $contrato->salario
            ? '$ '.number_format((float) $contrato->salario, 0, ',', '.')
            : '—';

        // Campos de texto libre (custom.*) configurados para esta entidad
        $camposCustom = collect($entidad?->formulario_campos ?? [])
            ->filter(fn ($c) => str_starts_with($c['dato'] ?? '', 'custom.'))
            ->map(fn ($c) => [
                'clave' => $c['dato'],
                'sufijo' => str_replace('custom.', '', $c['dato']),
                'label' => $c['label'] ?? $c['dato'],
            ])
            ->unique('clave')
            ->values();

        $rutaVista = match ($tipo) {
            'pension' => 'admin.afiliaciones.formulario.pension',
            'caja' => 'admin.afiliaciones.formulario.caja',
            default => 'admin.afiliaciones.formulario.eps',
        };
        $rutaRaw = $rutaVista.'.raw';

        return view('admin.afiliaciones.formulario_print', [
            'contrato' => $contrato,
            'tituloEntidad' => $tituloEntidad,
            'nombreCompleto' => $nombreCompleto,
            'empresa' => $empresa,
            'fechaIngreso' => $fechaIngreso,
            'salario' => $salario,
            'conBeneficiarios' => $conBeneficiarios,
            'camposCustom' => $camposCustom,
            'urlVista' => route($rutaVista, ['contrato' => $contrato->id]),
            'urlRaw' => route($rutaRaw, ['contrato' => $contrato->id]),
            'urlFirma' => route('admin.afiliaciones.formulario.eps.firma', $contrato->id),
            // Sin firma dibujada el formulario sale con el espacio en blanco, así
            // que la vista abre el modal sola para pedirla.
            'tieneFirma' => FormularioEpsService::tieneFirma($cliente),
        ]);
    }

    /**
     * Guarda la firma del cliente (base64 PNG) en disco.
     * POST /admin/afiliaciones/{contrato}/formulario/eps/firma
     */
    public function guardarFirma(Request $request, int $contrato)
    {
        $contrato = Contrato::paraTramite($contrato);
        $request->validate(['firma' => 'required|string']);

        $data = $request->input('firma');
        if (str_contains($data, ',')) {
            $data = explode(',', $data, 2)[1];
        }

        $contrato->loadMissing('cliente');
        $cedula = $contrato->cliente?->cedula ?? $contrato->id;

        $dir = storage_path('app/firmas');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir.'/'.$cedula.'.png', base64_decode($data));

        return response()->json(['ok' => true]);
    }

    /**
     * PDF relleno de la EPS (binario) para el iframe.
     * GET /admin/afiliaciones/{contrato}/formulario/eps/raw
     */
    public function generar(int $contrato)
    {
        return $this->generarPdf(Contrato::paraTramite($contrato), 'eps');
    }

    /**
     * PDF relleno del fondo de pensión (binario) para el iframe.
     * GET /admin/afiliaciones/{contrato}/formulario/pension/raw
     */
    public function generarPension(int $contrato)
    {
        return $this->generarPdf(Contrato::paraTramite($contrato), 'pension');
    }

    /**
     * PDF relleno de la caja de compensación (binario) para el iframe.
     * GET /admin/afiliaciones/{contrato}/formulario/caja/raw
     */
    public function generarCaja(int $contrato)
    {
        return $this->generarPdf(Contrato::paraTramite($contrato), 'caja');
    }

    protected function generarPdf(Contrato $contrato, string $tipo)
    {
        $incluirBeneficiarios = request()->boolean('beneficiarios', false);

        // Datos de texto libre enviados desde la vista (custom[texto_1]=valor, …)
        $customDatos = request()->input('custom', []);
        if (! is_array($customDatos)) {
            $customDatos = [];
        }

        $contrato->loadMissing([
            'cliente.municipio',
            'cliente.departamento',
            'cliente.beneficiarios',
            'razonSocial',
            'eps',
            'arl',
            'pension',
            'caja',
        ]);

        $pdfBinario = match ($tipo) {
            'pension' => $this->service->generarPension($contrato, $incluirBeneficiarios, $customDatos),
            'caja' => $this->service->generarCaja($contrato, $incluirBeneficiarios, $customDatos),
            default => $this->service->generar($contrato, $incluirBeneficiarios, $customDatos),
        };

        $nombreCliente = str_replace([' ', '/'], '_', $contrato->cliente?->nombre_completo ?? 'formulario');
        $nombreEntidad = str_replace([' ', '/'], '_', match ($tipo) {
            'pension' => $contrato->pension?->razon_social ?? 'pension',
            'caja' => $contrato->caja?->nombre ?? 'caja',
            default => $contrato->eps?->nombre ?? 'eps',
        });
        $filename = "Formulario_{$nombreEntidad}_{$nombreCliente}.pdf";

        return response($pdfBinario, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
