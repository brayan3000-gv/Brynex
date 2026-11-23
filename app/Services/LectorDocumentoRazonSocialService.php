<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Lee un certificado de cámara de comercio o un RUT y saca los datos de la
 * ficha, para no digitarlos a mano.
 *
 * Los dos documentos son texto estructurado, no imágenes: se leen con
 * expresiones regulares y no hace falta IA ni ningún servicio externo. Lo que
 * aporta cada uno:
 *
 *   Cámara → razón social, NIT, dígito de verificación, fecha de constitución,
 *            municipio (que decide el ICA), dirección, correo, teléfono, CIIU.
 *   RUT    → las responsabilidades de la casilla 53, y de ahí el régimen
 *            (código 47 = simple) y si es responsable de IVA (código 48).
 *
 * Un certificado escaneado no trae texto y no se puede leer: eso se informa,
 * no se adivina.
 */
class LectorDocumentoRazonSocialService
{
    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    /** Códigos del RUT que el módulo entiende. El resto se guarda igual. */
    private const RESP_REGIMEN_SIMPLE = '47';

    private const RESP_IVA = '48';

    /**
     * @return array{
     *   ok: bool, tipo: ?string, error: ?string, datos: array, encontrados: array
     * }
     */
    public function leer(UploadedFile $archivo, ?string $nitEsperado = null): array
    {
        try {
            $texto = (new Parser)->parseFile($archivo->getRealPath())->getText();
        } catch (\Throwable $e) {
            Log::warning('Lector RS: el PDF no se pudo abrir', ['error' => $e->getMessage()]);

            return $this->fallo('El PDF no se pudo abrir. ¿Está dañado o protegido con contraseña?');
        }

        // Menos de 300 caracteres es un escaneo: páginas de imagen sin texto.
        if (mb_strlen(trim($texto)) < 300) {
            return $this->fallo(
                'El documento no tiene texto: parece un escaneo. '
                .'Descarga el certificado en PDF desde el portal de la cámara o de la DIAN, no escaneado.'
            );
        }

        $tipo = $this->tipo($texto);

        if (! $tipo) {
            return $this->fallo('No reconozco el documento. Debe ser un certificado de cámara de comercio o un RUT.');
        }

        $datos = $tipo === 'CAMARA' ? $this->deCamara($texto) : $this->deRut($texto);
        $datos = array_filter($datos, fn ($v) => $v !== null && $v !== '' && $v !== []);

        // Que el documento sea de OTRA empresa es el error que de verdad hace
        // daño: llenaría la ficha con datos ajenos sin que nadie lo note.
        if ($nitEsperado && ! empty($datos['nit']) && $datos['nit'] !== preg_replace('/\D/', '', $nitEsperado)) {
            return $this->fallo(sprintf(
                'Ese documento es del NIT %s y la razón social que estás siguiendo es la %s.',
                $datos['nit'], preg_replace('/\D/', '', $nitEsperado)
            ));
        }

        return [
            'ok' => true,
            'tipo' => $tipo,
            'error' => null,
            'datos' => $datos,
            'encontrados' => array_keys($datos),
        ];
    }

    private function fallo(string $mensaje): array
    {
        return ['ok' => false, 'tipo' => null, 'error' => $mensaje, 'datos' => [], 'encontrados' => []];
    }

    private function tipo(string $t): ?string
    {
        if (preg_match('/CERTIFICADO DE EXISTENCIA|C[ÁA]MARA DE COMERCIO|Matr[íi]cula No/ui', $t)) {
            return 'CAMARA';
        }

        if (preg_match('/Registro [ÚU]nico Tributario|RUT|Responsabilidades, Calidades y Atributos/ui', $t)) {
            return 'RUT';
        }

        return null;
    }

    // ─── Cámara de comercio ───────────────────────────────────────────

    private function deCamara(string $t): array
    {
        $nit = $this->uno('/Nit\.?:\s*([\d\.]+)/u', $t);

        return [
            'razon_social' => $this->uno('/Razón social:\s*(.+)/u', $t),
            'nit' => $nit ? preg_replace('/\D/', '', $nit) : null,
            'dv' => $this->uno('/Nit\.?:\s*[\d\.]+\s*-\s*(\d)/u', $t),
            // La constitución sale del párrafo "…se constituyó sociedad".
            // Si no está, sirve la fecha de matrícula: para una SAS son la
            // misma o van con días de diferencia.
            'fecha_constitucion' => $this->fecha($this->uno('/((?:Que\s+)?por\s+(?:Documento|Escritura|Acta)[^.]{0,400}?constituy[óo])/u', $t))
                ?? $this->fecha($this->uno('/Fecha de matrícula en esta Cámara:\s*(.+)/u', $t)),
            // "Cali - Valle" → "Cali": es lo que decide el ICA.
            'municipio_ica' => trim(explode('-', (string) $this->uno('/Municipio:\s*(.+)/u', $t))[0]) ?: null,
            'direccion' => $this->uno('/Dirección del domicilio principal:\s*(.+)/u', $t),
            'correo' => $this->uno('/Correo electrónico:\s*(\S+@\S+)/u', $t),
            'telefono' => $this->uno('/Teléfono comercial 1:\s*(\d+)/u', $t),
            'ciiu' => $this->uno('/CIIU:\s*(\d+)/u', $t),
            'matricula' => $this->uno('/Matrícula No\.?:\s*([\d\-]+)/u', $t),
            // Lo que cierra la renovación de matrícula en el checklist.
            'ultimo_anio_renovado' => $this->uno('/[ÚU]ltimo año renovado:?\s*(\d{4})/iu', $t),
            'fecha_renovacion' => $this->fecha($this->uno('/Fecha de renovaci[óo]n:?\s*(.+)/iu', $t)),
        ];
    }

    // ─── RUT ──────────────────────────────────────────────────────────

    private function deRut(string $t): array
    {
        $nit = $this->nitDelRut($t);

        // Las responsabilidades vienen con su descripción al lado:
        // "47 - Régimen Simple de Tributación - SIMPLE".
        preg_match_all('/\b(\d{2})\s*-\s*[A-ZÁÉÍÓÚÑ][^\n]{5,60}/u', $t, $m);
        $codigos = array_values(array_unique($m[1] ?? []));
        sort($codigos);

        if (! $codigos) {
            return ['nit' => $nit];
        }

        $simple = in_array(self::RESP_REGIMEN_SIMPLE, $codigos, true);
        $iva = in_array(self::RESP_IVA, $codigos, true);

        return [
            'nit' => $nit,
            'responsabilidades_rut' => $codigos,
            'regimen' => $simple ? 'RST' : 'ORDINARIO',
            // En el simple el IVA se declara una vez al año. En el ordinario
            // depende de los ingresos del año anterior: bimestral desde 92.000
            // UVT. Se propone cuatrimestral, que es el caso de la gran mayoría,
            // y queda editable.
            'periodicidad_iva' => ! $iva ? 'no_responsable' : ($simple ? 'anual' : 'cuatrimestral'),
        ];
    }

    /**
     * El NIT de la casilla 5, que va justo antes de la dirección seccional
     * ("… Impuestos de Cali").
     *
     * Según el PDF los dígitos salen pegados ("9019189232") o separados
     * ("9 0 1 9 1 8 9 2 3 2"), y en la misma línea viene también el número del
     * formulario. Por eso se toma la ÚLTIMA cifra antes de «Impuestos» y se
     * decide con el algoritmo de la DIAN si el último dígito es el de
     * verificación o parte del NIT.
     */
    private function nitDelRut(string $t): ?string
    {
        // Se recorren TODAS las apariciones: la primera suele ser la del
        // membrete ("Dirección de Impuestos y Aduanas Nacionales"), no la de
        // la seccional. La buena es la que trae la cifra del NIT delante.
        $desde = 0;

        while (($corte = mb_stripos($t, 'Impuestos', $desde)) !== false) {
            $desde = $corte + 1;

            // Línea por línea y no por bloques: en la misma ventana está el
            // número del formulario, y un `[\d\s]+` se lo traga junto con el
            // NIT en una sola cifra. Partir por líneas además resuelve el caso
            // en que los dígitos vienen separados ("9 0 1 9 1 8 9 2 3 2").
            $lineas = preg_split('/\R/u', mb_substr($t, max(0, $corte - 80), min($corte, 80)));

            foreach (array_reverse($lineas) as $linea) {
                $crudo = preg_replace('/\D/', '', $linea);

                if (strlen($crudo) < 10) {
                    continue;
                }

                // El último dígito es el de verificación si cuadra con el
                // algoritmo de la DIAN. Si no, la cifra es el NIT completo.
                $sinDv = substr($crudo, 0, -1);

                if (strlen($sinDv) >= 9 && strlen($sinDv) <= 10
                    && $this->digitoVerificacion($sinDv) === (int) substr($crudo, -1)) {
                    return $sinDv;
                }
            }
        }

        return null;
    }

    /** Dígito de verificación de la DIAN: pesos por posición, módulo 11. */
    private function digitoVerificacion(string $nit): int
    {
        $pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $suma = 0;

        foreach (array_reverse(str_split($nit)) as $i => $d) {
            $suma += (int) $d * ($pesos[$i] ?? 0);
        }

        $resto = $suma % 11;

        return $resto > 1 ? 11 - $resto : $resto;
    }

    // ─── Ayudas ───────────────────────────────────────────────────────

    private function uno(string $patron, string $t): ?string
    {
        return preg_match($patron, $t, $m) ? trim($m[1]) : null;
    }

    /** "19 de febrero de 2025" → "2025-02-19". */
    private function fecha(?string $s): ?string
    {
        if (! $s || ! preg_match('/(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/u', $s, $m)) {
            return null;
        }

        $mes = self::MESES[mb_strtolower($m[2])] ?? null;

        return $mes ? sprintf('%04d-%02d-%02d', $m[3], $mes, $m[1]) : null;
    }
}
