<?php

namespace App\Services\ArlSura;

use App\Models\ArlCentroTrabajo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Radicado;
use App\Models\RazonSocial;
use Illuminate\Support\Collection;

/**
 * Compara los afiliados que ARL Sura tiene en una póliza contra los contratos
 * vigentes de BryNex.
 *
 * Las dos listas se desfasan solas: alguien retira en el portal y no en BryNex,
 * o al revés, y nadie lo nota hasta que hay un accidente sin cobertura o se
 * paga por gente que ya no está. Nadie compara 52 nombres a mano.
 *
 * El cruce se hace por NIT y no por razón social: la misma empresa está
 * registrada en varios aliados y sus trabajadores viven repartidos entre ellos,
 * mientras que en Sura hay una sola póliza para todos.
 */
class ArlConciliacionService
{
    /** Sura pagina de a bloques; con este tamaño una empresa normal cabe en dos o tres. */
    private const POR_PAGINA = 50;

    /** Tope de seguridad: ninguna póliza del aliado se acerca, pero evita un bucle infinito. */
    private const MAXIMO = 2000;

    /**
     * El aliado BryNex es donde se prueba: tiene sus propias copias de las
     * empresas, con el mismo NIT, llenas de contratos y gente falsa. Si entra
     * al cruce, cada persona inventada sale como «sin cobertura» y abre una
     * tarea real de afiliar a alguien que no existe.
     */
    private const ALIADO_PRUEBAS = 1;

    public function __construct(private ArlSuraApiService $api) {}

    public static function paraPoliza(int $aliadoId, string $poliza): self
    {
        return new self(new ArlSuraApiService($aliadoId, $poliza));
    }

    /**
     * Los afiliados activos de la póliza según el portal, por cédula.
     *
     * @return Collection<string, array{documento: string, tipo: string, nombre: string}>
     */
    public function afiliadosEnSura(string $poliza): Collection
    {
        $afiliados = collect();

        for ($desde = 0; $desde < self::MAXIMO; $desde += self::POR_PAGINA) {
            $pagina = $this->api->post('/sel-services/personas/afiliados/', [
                'dni' => '', 'nombre' => '', 'apellido1' => '', 'apellido2' => '',
                'poliza' => $poliza,
                'desde'  => $desde,
                'hasta'  => $desde + self::POR_PAGINA,
            ]);

            foreach ($pagina as $p) {
                $documento = self::normalizar($p['numeroDni'] ?? '');

                if ($documento !== '') {
                    $afiliados[$documento] = [
                        'documento' => $documento,
                        'tipo'      => $p['tipoDni'] ?? 'C',
                        'nombre'    => trim(preg_replace('/\s+/', ' ', (string) ($p['nombre'] ?? ''))),
                    ];
                }
            }

            if (count($pagina) < self::POR_PAGINA) {
                break;
            }
        }

        return $afiliados;
    }

    /**
     * Los contratos vigentes de esa empresa en BryNex, de todos los aliados
     * menos el de pruebas.
     *
     * Se dejan fuera los planes sin ARL: un contrato de solo salud no tiene por
     * qué aparecer en el portal, y contarlo como faltante sería ruido.
     */
    public function vigentesEnBrynex(string $nit): Collection
    {
        $razones = RazonSocial::where('nit', preg_replace('/\D/', '', $nit))->pluck('id');

        return Contrato::whereIn('razon_social_id', $razones)
            ->where('estado', 'vigente')
            ->where('aliado_id', '<>', self::ALIADO_PRUEBAS)
            ->with(['plan:id,nombre,incluye_arl', 'aliado:id,nombre', 'razonSocial:id,razon_social,nit'])
            ->get()
            ->filter(fn ($c) => $c->plan?->incluye_arl)
            ->keyBy(fn ($c) => self::normalizar((string) $c->cedula));
    }

    /**
     * El cruce completo de una empresa.
     *
     * @return array{poliza: string, nit: string, en_sura: int, en_brynex: int,
     *               sobran: array, faltan: array}
     */
    public function conciliar(string $nit, string $poliza): array
    {
        $sura   = $this->afiliadosEnSura($poliza);
        $brynex = $this->vigentesEnBrynex($nit);

        // En el portal pero sin contrato vigente: se está pagando cobertura de
        // alguien que en BryNex ya no está, o que nunca estuvo.
        $sobran = $sura->keys()->diff($brynex->keys())->map(function ($doc) use ($sura, $nit) {
            // Se busca su contrato más reciente en cualquier empresa: casi
            // siempre la persona sí existe en BryNex, y lo que hay que decir es
            // POR QUÉ no cuadra —está retirada, o está vigente pero en otra
            // razón social, que es un desfase distinto y se resuelve distinto—.
            $contrato = Contrato::where('cedula', $doc)
                ->where('aliado_id', '<>', self::ALIADO_PRUEBAS)
                ->with(['razonSocial:id,razon_social,nit', 'aliado:id,nombre'])
                ->orderByRaw("CASE WHEN estado = 'vigente' THEN 0 ELSE 1 END")
                ->latest('id')
                ->first(['id', 'estado', 'aliado_id', 'razon_social_id', 'cedula']);

            $mismaEmpresa = $contrato && preg_replace('/\D/', '', (string) $contrato->razonSocial?->nit)
                === preg_replace('/\D/', '', $nit);

            return [
                'documento'   => $doc,
                'nombre'      => $sura[$doc]['nombre'],
                'situacion'   => match (true) {
                    ! $contrato                                   => 'no existe en BryNex',
                    $contrato->estado !== 'vigente'               => 'en BryNex está '.$contrato->estado,
                    $mismaEmpresa                                 => 'vigente en BryNex pero sin ARL en su plan',
                    default                                       => 'vigente en BryNex, pero en otra empresa',
                },
                'otra_empresa' => $mismaEmpresa ? null : $contrato?->razonSocial?->razon_social,
                'contrato_id'  => $contrato?->id,
                'aliado_id'    => $contrato ? (int) $contrato->aliado_id : null,
                'aliado'       => $contrato?->aliado?->nombre,
            ];
        })->values()->all();

        // Con contrato vigente pero sin cobertura: el trabajador se cree
        // cubierto y no lo está.
        $faltan = $brynex->keys()->diff($sura->keys())->map(function ($doc) use ($brynex) {
            $c       = $brynex[$doc];
            $cliente = Cliente::where('cedula', $doc)->where('aliado_id', $c->aliado_id)->first();

            // Si la afiliación a la ARL sigue en la cola de radicados, ya hay
            // quien la esté haciendo: avisar aparte solo la duplica.
            $radicado = Radicado::where('contrato_id', $c->id)
                ->where('tipo', Radicado::TIPO_ARL)
                ->latest('id')
                ->value('estado');

            return [
                'documento'   => $doc,
                'nombre'      => trim(($cliente?->primer_nombre ?? '').' '.($cliente?->primer_apellido ?? '')) ?: '(sin cliente)',
                'contrato_id' => $c->id,
                'aliado_id'   => (int) $c->aliado_id,
                'aliado'      => $c->aliado?->nombre,
                'en_tramite'  => in_array($radicado, [Radicado::ESTADO_PENDIENTE, Radicado::ESTADO_TRAMITE], true),
                'plan'        => $c->plan?->nombre,
                'riesgo'      => $c->n_arl,
                'desde'       => $c->fecha_ingreso?->format('d/m/Y'),
            ];
        })->values()->all();

        return [
            'poliza'    => $poliza,
            'nit'       => $nit,
            'en_sura'   => $sura->count(),
            'en_brynex' => $brynex->count(),
            'sobran'    => $sobran,
            'faltan'    => $faltan,
        ];
    }

    /**
     * De los que están en ambos lados, cuáles tienen distinto nivel de riesgo.
     *
     * Es el desfase más caro y el que nadie ve: se cotiza una tarifa y la
     * cobertura real es otra, así que el trabajador queda mal amparado o la
     * empresa paga de más, y el error solo sale a la luz con un accidente.
     *
     * Cuesta una consulta al portal por trabajador, así que va aparte del cruce
     * y solo cuando alguien lo pide.
     */
    public function diferenciasDeRiesgo(string $nit, string $poliza): array
    {
        $sura   = $this->afiliadosEnSura($poliza);
        $brynex = $this->vigentesEnBrynex($nit);
        $ambos  = $sura->keys()->intersect($brynex->keys());

        $diferencias = [];

        foreach ($ambos as $doc) {
            $contrato = $brynex[$doc];
            $tipo     = $sura[$doc]['tipo'] ?: 'C';

            try {
                $coberturas = $this->api->coberturasRetirables($tipo.$doc);
            } catch (\Throwable $e) {
                continue; // una consulta caída no invalida el resto del informe
            }

            $viva = collect($coberturas)->firstWhere('fechaRetiro', null);

            if (! $viva) {
                continue;
            }

            // El portal no devuelve la clase de riesgo, sino el centro de
            // trabajo. La clase sale del centro, que es como se tarifa.
            $centroSura = trim(explode(' ', (string) ($viva['dsCentroTrabajo'] ?? ''))[0]);
            $riesgoSura = ArlCentroTrabajo::where('codigo_centro', $centroSura)
                ->whereIn('razon_social_id', RazonSocial::where('nit', $nit)->pluck('id'))
                ->value('nivel_riesgo');

            if ($riesgoSura && (int) $riesgoSura !== (int) $contrato->n_arl) {
                $diferencias[] = [
                    'documento'    => $doc,
                    'nombre'       => $sura[$doc]['nombre'],
                    'contrato_id'  => $contrato->id,
                    'aliado'       => $contrato->aliado?->nombre,
                    'riesgo_brynex' => (int) $contrato->n_arl,
                    'riesgo_sura'   => (int) $riesgoSura,
                    'centro_sura'   => $viva['dsCentroTrabajo'] ?? null,
                ];
            }
        }

        return $diferencias;
    }

    /** Los documentos se comparan sin ceros a la izquierda ni separadores. */
    private static function normalizar(string $documento): string
    {
        return ltrim(preg_replace('/\D/', '', $documento), '0');
    }
}
