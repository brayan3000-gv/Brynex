<?php

namespace Database\Seeders;

use App\Models\BrynexCalendarioVencimiento;
use App\Models\BrynexObligacionCatalogo;
use Illuminate\Database\Seeder;

/**
 * Catálogo de obligaciones + calendario de vencimientos.
 *
 * Idempotente: se puede correr las veces que haga falta. Solo hace
 * updateOrCreate; nunca borra renglones del checklist de nadie.
 *
 * ── De dónde salen las fechas ────────────────────────────────────────────
 *
 * Del PDF oficial "Calendario Tributario 2026" de la DIAN
 * (https://www.dian.gov.co/Calendarios/Calendario_Tributario_2026.pdf),
 * transcrito tabla por tabla. La DIAN vence por el último dígito del NIT sin
 * el dígito de verificación, en dos filas de cinco: 1-2-3-4-5 y 6-7-8-9-0.
 *
 * ── Lo que NO está aquí ──────────────────────────────────────────────────
 *
 *  · **Información exógena**: no va en el calendario tributario, la fija una
 *    resolución aparte cada año. La obligación se crea igual pero sin fecha;
 *    el contador la carga desde la pantalla de calendario cuando salga.
 *  · **ICA**: lo fija cada municipio, no la DIAN. Mismo tratamiento.
 *  · **Años anteriores a 2026**: no se cargan. Los renglones viejos se generan
 *    sin `fecha_vencimiento` para poder ponerse al día con los soportes, pero
 *    no entran al semáforo. Inventar un vencimiento es peor que no tenerlo.
 */
class BrynexObligacionesSeeder extends Seeder
{
    /** Orden de los dígitos en las tablas de la DIAN. */
    private const DIGITOS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 0];

    /** El RST anual agrupa de a dos dígitos. */
    private const PARES = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 0]];

    public function run(): void
    {
        $this->catalogo();
        $this->calendario2026();

        $this->command?->info('Catálogo: '.BrynexObligacionCatalogo::count().' obligaciones.');
        $this->command?->info('Calendario 2026: '.BrynexCalendarioVencimiento::where('anio', 2026)->count().' vencimientos.');
    }

    // ─── Qué obligaciones existen ─────────────────────────────────────

    /**
     * Responsabilidades del RUT que exige cada obligación.
     *
     * Va aparte del catálogo para que se lea de un golpe qué depende del RUT y
     * qué no. Lo demás (renta, IVA, anticipos, cámara) depende del régimen y de
     * la periodicidad, no de una casilla del RUT.
     */
    private const RESPONSABILIDADES = [
        // 07 agente retenedor de renta · 09 agente retenedor de IVA. Ambas se
        // declaran en el mismo formulario 350, y las tiene tanto el régimen
        // ordinario como el simple.
        'retefuente_mensual' => '07,09',
        // 14 informante de exógena.
        'exogena' => '14',
    ];

    /**
     * Trámites de una sola vez (periodicidad 'unica'): meses desde la
     * constitución para hacerlos. Se genera un único renglón por razón social.
     */
    private const MESES_PLAZO = [
        // Art. 915 E.T.: el simple adopta la factura electrónica dentro de los
        // 2 meses siguientes a su inscripción en el RUT.
        'facturacion_electronica' => 2,
    ];

    private function catalogo(): void
    {
        $filas = [
            // [codigo, nombre, entidad, formulario, regimen, periodicidad,
            //  requiere_iva, periodicidad_iva_requerida, orden, descripcion,
            //  anios_desfase]

            // ── Trámites de una sola vez ──────────────────────────────
            ['facturacion_electronica', 'Habilitación de facturación electrónica', 'DIAN', null, 'RST', 'unica', false, null, 5,
                'Se hace una sola vez. El régimen simple tiene 2 meses desde la inscripción en el RUT (art. 915 E.T.); pasado el plazo el incumplimiento es insubsanable. Gratis con el facturador de la DIAN. Marcar «Hecha» con la fecha de la habilitación.', 0],

            // ── Régimen Simple ────────────────────────────────────────
            ['rst_anticipo_bimestral', 'Anticipo bimestral SIMPLE', 'DIAN', '2593', 'RST', 'bimestral', false, null, 10,
                'Seis anticipos al año. Incluye el ICA de los municipios que se acogieron al simple: por eso el régimen simple NO lleva una obligación de ICA aparte. Los contribuyentes con ingresos brutos anuales inferiores a 3.500 UVT están exentos del anticipo, pero igual se marca aquí como "No aplica" para dejar el rastro.', 0],
            ['rst_anual_consolidada', 'Declaración anual consolidada SIMPLE', 'DIAN', '260', 'RST', 'anual', false, null, 11,
                'Cierra el año del régimen simple. Vence en abril del año siguiente.', 1],
            ['rst_iva_anual', 'IVA anual consolidada (SIMPLE)', 'DIAN', '300', 'RST', 'anual', true, null, 12,
                'Los del régimen simple responsables de IVA declaran una sola vez al año, en febrero del año siguiente.', 1],

            // ── Régimen Ordinario ─────────────────────────────────────
            ['iva_bimestral', 'IVA bimestral', 'DIAN', '300', 'ORDINARIO', 'bimestral', true, 'bimestral', 20,
                'Aplica a quienes el año anterior tuvieron ingresos brutos iguales o superiores a 92.000 UVT.', 0],
            ['iva_cuatrimestral', 'IVA cuatrimestral', 'DIAN', '300', 'ORDINARIO', 'cuatrimestral', true, 'cuatrimestral', 21,
                'Aplica a quienes el año anterior tuvieron ingresos brutos inferiores a 92.000 UVT.', 0],
            ['retefuente_mensual', 'Retención en la fuente', 'DIAN', '350', null, 'mensual', false, null, 22,
                'Mensual y con pago total: sin el pago, la declaración se tiene por no presentada.', 0],
            ['renta_juridica', 'Renta persona jurídica — declaración y 1ª cuota', 'DIAN', '110', 'ORDINARIO', 'anual', false, null, 23,
                'Se declara y paga la primera cuota en mayo del año siguiente.', 1],
            ['renta_juridica_cuota2', 'Renta persona jurídica — 2ª cuota', 'DIAN', '110', 'ORDINARIO', 'anual', false, null, 24,
                'Segunda cuota, en julio del año siguiente.', 1],

            // ── Comunes a los dos regímenes ───────────────────────────
            ['exogena', 'Información exógena', 'DIAN', '1001+', null, 'anual', false, null, 30,
                'La fija una resolución aparte cada año, no el calendario tributario. Cargar la fecha a mano cuando salga la resolución.', 1],
            ['camara_renovacion', 'Renovación de matrícula mercantil', 'CAMARA', null, null, 'anual', false, null, 40,
                'Hasta el 31 de marzo, igual para todos: no depende del NIT.', 0],

            // ── Municipio: una entrada por periodicidad ───────────────
            // Solo del régimen ORDINARIO. En el simple el ICA no se declara
            // aparte: va incluido dentro del anticipo bimestral del 2593, que
            // ya está en el checklist. Generarlo suelto sería un renglón
            // fantasma que el contador tendría que marcar «no aplica» seis
            // veces al año.
            ['ica_bimestral', 'ICA bimestral', 'MUNICIPIO', null, 'ORDINARIO', 'bimestral', false, null, 50,
                'Lo fija el municipio (Bogotá y otros lo cobran bimestral). Cargar las fechas a mano en la pantalla de calendario.', 0],
            ['ica_anual', 'ICA anual', 'MUNICIPIO', null, 'ORDINARIO', 'anual', false, null, 51,
                'Lo fija el municipio; en Cali el ordinario declara una vez al año. Cargar la fecha a mano en la pantalla de calendario.', 1],
        ];

        $responsabilidades = self::RESPONSABILIDADES;

        foreach ($filas as $f) {
            BrynexObligacionCatalogo::updateOrCreate(
                ['codigo' => $f[0]],
                [
                    'nombre' => $f[1],
                    'entidad' => $f[2],
                    'formulario' => $f[3],
                    'regimen' => $f[4],
                    'periodicidad' => $f[5],
                    'requiere_iva' => $f[6],
                    'periodicidad_iva_requerida' => $f[7],
                    'requiere_responsabilidad' => $responsabilidades[$f[0]] ?? null,
                    'orden' => $f[8],
                    'descripcion' => $f[9],
                    // Años entre el año gravable y el plazo. Ordena el
                    // checklist cuando todavía no hay calendario cargado.
                    'anios_desfase' => $f[10],
                    'meses_plazo' => self::MESES_PLAZO[$f[0]] ?? null,
                    'activo' => true,
                ]
            );
        }
    }

    // ─── Cuándo vence cada una en 2026 ────────────────────────────────

    private function calendario2026(): void
    {
        // [período => [mes, año, '1 2 3 4 5', '6 7 8 9 0']]
        // El año va aparte porque el último período de casi todo vence en
        // enero del año siguiente.

        // RST — anticipo bimestral (formulario 2593)
        $this->cargar(2026, 'rst_anticipo_bimestral', [
            1 => [5, 2026, '12 13 14 15 19', '20 21 22 25 26'],
            2 => [6, 2026, '10 11 12 16 17', '18 19 22 23 24'],
            3 => [7, 2026, '9 10 14 15 16', '17 21 22 23 24'],
            4 => [9, 2026, '9 10 11 14 15', '16 17 18 21 22'],
            5 => [11, 2026, '11 12 13 17 18', '19 20 23 24 25'],
            6 => [1, 2027, '13 14 15 18 19', '20 21 22 25 26'],
        ]);

        // IVA bimestral (ordinario)
        $this->cargar(2026, 'iva_bimestral', [
            1 => [3, 2026, '10 11 12 13 16', '17 18 19 20 24'],
            2 => [5, 2026, '12 13 14 15 19', '20 21 22 25 26'],
            3 => [7, 2026, '9 10 14 15 16', '17 21 22 23 24'],
            4 => [9, 2026, '9 10 11 14 15', '16 17 18 21 22'],
            5 => [11, 2026, '11 12 13 17 18', '19 20 23 24 25'],
            6 => [1, 2027, '13 14 15 18 19', '20 21 22 25 26'],
        ]);

        // IVA cuatrimestral (ordinario)
        $this->cargar(2026, 'iva_cuatrimestral', [
            1 => [5, 2026, '12 13 14 15 19', '20 21 22 25 26'],
            2 => [9, 2026, '9 10 11 14 15', '16 17 18 21 22'],
            3 => [1, 2027, '13 14 15 18 19', '20 21 22 25 26'],
        ]);

        // Retención en la fuente — mensual (formulario 350).
        // El período es el mes declarado; el vencimiento cae al mes siguiente.
        $this->cargar(2026, 'retefuente_mensual', [
            1 => [2, 2026, '10 11 12 13 16', '17 18 19 20 23'],
            2 => [3, 2026, '10 11 12 13 16', '17 18 19 20 24'],
            3 => [4, 2026, '13 14 15 16 20', '21 22 23 24 27'],
            4 => [5, 2026, '12 13 14 15 19', '20 21 22 25 26'],
            5 => [6, 2026, '10 11 12 16 17', '18 19 22 23 24'],
            6 => [7, 2026, '9 10 14 15 16', '17 21 22 23 24'],
            7 => [8, 2026, '12 13 14 18 19', '20 21 24 25 26'],
            8 => [9, 2026, '9 10 11 14 15', '16 17 18 21 22'],
            9 => [10, 2026, '9 13 14 15 16', '19 20 21 22 23'],
            10 => [11, 2026, '11 12 13 17 18', '19 20 23 24 25'],
            11 => [12, 2026, '10 11 14 15 16', '17 18 21 22 23'],
            12 => [1, 2027, '13 14 15 18 19', '20 21 22 25 26'],
        ]);

        // Renta persona jurídica del año gravable 2026: se declara en 2027.
        // Las fechas de 2027 aún no están publicadas, así que aquí se cargan
        // las del año gravable 2025 (que son las que vencen dentro de 2026).
        $this->cargar(2025, 'renta_juridica', [
            1 => [5, 2026, '12 13 14 15 19', '20 21 22 25 26'],
        ]);
        $this->cargar(2025, 'renta_juridica_cuota2', [
            1 => [7, 2026, '9 10 14 15 16', '17 21 22 23 24'],
        ]);

        // RST anual del año gravable 2025, que vence dentro de 2026.
        // Agrupa de a dos dígitos, no de a uno.
        $this->cargarPares(2025, 'rst_anual_consolidada', 4, 2026, [20, 21, 22, 23, 24]);
        $this->cargarPares(2025, 'rst_iva_anual', 2, 2026, [16, 17, 18, 19, 20]);

        // Renovación de matrícula mercantil: 31 de marzo, igual para todos.
        // `ultimo_digito` en null = una sola fila que aplica a cualquier NIT.
        BrynexCalendarioVencimiento::updateOrCreate(
            ['anio' => 2026, 'obligacion_codigo' => 'camara_renovacion', 'periodo' => 1, 'ultimo_digito' => null],
            ['fecha_vencimiento' => '2026-03-31']
        );
    }

    // ─── Ayudas ───────────────────────────────────────────────────────

    /**
     * Carga una obligación completa. `$tablas` es
     * [periodo => [mes, año, 'días de los dígitos 1-5', 'días de los 6-9-0']].
     */
    private function cargar(int $anio, string $codigo, array $tablas): void
    {
        foreach ($tablas as $periodo => [$mes, $anioVence, $fila1, $fila2]) {
            $dias = array_merge(
                preg_split('/\s+/', trim($fila1)),
                preg_split('/\s+/', trim($fila2))
            );

            if (count($dias) !== 10) {
                throw new \RuntimeException(
                    "El calendario de {$codigo} período {$periodo} no trae 10 días."
                );
            }

            foreach (self::DIGITOS as $i => $digito) {
                BrynexCalendarioVencimiento::updateOrCreate(
                    [
                        'anio' => $anio,
                        'obligacion_codigo' => $codigo,
                        'periodo' => $periodo,
                        'ultimo_digito' => $digito,
                    ],
                    [
                        'fecha_vencimiento' => sprintf(
                            '%04d-%02d-%02d', $anioVence, $mes, (int) $dias[$i]
                        ),
                    ]
                );
            }
        }
    }

    /** Para las obligaciones que la DIAN agrupa de a dos dígitos (RST anual). */
    private function cargarPares(int $anio, string $codigo, int $mes, int $anioVence, array $dias): void
    {
        foreach (self::PARES as $i => $par) {
            foreach ($par as $digito) {
                BrynexCalendarioVencimiento::updateOrCreate(
                    [
                        'anio' => $anio,
                        'obligacion_codigo' => $codigo,
                        'periodo' => 1,
                        'ultimo_digito' => $digito,
                    ],
                    [
                        'fecha_vencimiento' => sprintf(
                            '%04d-%02d-%02d', $anioVence, $mes, $dias[$i]
                        ),
                    ]
                );
            }
        }
    }
}
