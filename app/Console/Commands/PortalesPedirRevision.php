<?php

namespace App\Console\Commands;

use App\Models\PortalPeticion;
use App\Models\Radicado;
use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\AlertaOperativaService;
use App\Services\Sos\SosConciliacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pide por WhatsApp que alguien entre a los portales donde el servidor no puede.
 *
 * S.O.S. pide reCAPTCHA en el login: no hay corrida nocturna posible, y sin
 * alguien que abra la sesión los radicados se quedan sin confirmar y las
 * devoluciones del portal no las ve nadie. Esto convierte esa espera en algo
 * visible: se abre una petición, se avisa una vez, y la revisión —que se hace
 * en Afiliaciones → Conciliar → S.O.S.— la cierra.
 *
 * Avisa una sola vez por petición: insistir cada día a quien ya sabe es la forma
 * de que deje de leer los avisos. Si nadie entra, la petición vence y la semana
 * siguiente se abre otra con la cuenta al día.
 */
class PortalesPedirRevision extends Command
{
    protected $signature = 'portales:pedir-revision
                            {--entidad=sos : Portal a revisar}
                            {--simular : Cuenta y muestra el aviso, pero no lo manda ni abre la petición}';

    protected $description = 'Avisa por WhatsApp cuando un portal necesita que una persona entre a revisarlo';

    public function handle(AlertaOperativaService $alertas): int
    {
        if ($this->option('entidad') !== 'sos') {
            $this->error('Por ahora solo S.O.S. necesita que alguien entre.');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');

        if ($vencidas = PortalPeticion::vencerViejas('sos')) {
            $this->warn("{$vencidas} petición(es) anterior(es) vencieron sin que nadie entrara.");
        }

        $empresas = $this->empresasPorRevisar();
        $pendientes = (int) $empresas->sum('pendientes');

        if (! $pendientes) {
            $this->info('S.O.S. no tiene radicados abiertos en las empresas con clave: no hay nada que pedir.');

            return self::SUCCESS;
        }

        if ($abierta = PortalPeticion::abiertaDe('sos')) {
            $this->info("Ya hay una petición abierta de S.O.S. desde el {$abierta->created_at->format('d/m/Y')}"
                ." ({$abierta->pendientes} pendientes). No se vuelve a avisar.");

            return self::SUCCESS;
        }

        $lista = $empresas->map(fn ($e) => "{$e->razon_social} ({$e->pendientes})")->implode(', ');
        $motivo = "{$pendientes} radicados por confirmar en ".$empresas->count().' empresa(s)';

        // Sin saltos de línea: Meta rechaza la variable de la plantilla si los trae.
        $mensaje = "S.O.S. necesita que alguien entre: {$motivo}. {$lista}."
            .' Abre Chrome, entra a S.O.S. y luego en BryNex: Afiliaciones → Conciliar → pestaña S.O.S.'
            .' Tú resuelves el captcha; el resto lo hace BryNex.';

        $this->line('');
        $this->line($mensaje);
        $this->line('');

        if ($simular) {
            $this->info('(simulación: no se abrió la petición ni se envió el aviso)');

            return self::SUCCESS;
        }

        $peticion = PortalPeticion::create([
            'entidad' => 'sos',
            'motivo' => mb_substr($motivo.': '.$lista, 0, 300),
            'pendientes' => $pendientes,
            'estado' => PortalPeticion::ABIERTA,
        ]);

        // Un aviso que no sale no debe dejar la petición sin abrir: la pantalla
        // de conciliación la muestra igual y alguien puede atenderla sin WhatsApp.
        if ($alertas->enviar('S.O.S.', $mensaje)) {
            $peticion->update(['aviso_enviado_at' => now(), 'avisos' => 1]);
            $this->info("Petición {$peticion->id} abierta y avisada al ".$alertas->numeroDestino().'.');
        } else {
            $this->warn("Petición {$peticion->id} abierta, pero el aviso de WhatsApp no salió (queda en el log).");
        }

        return self::SUCCESS;
    }

    /**
     * Empresas con radicados de S.O.S. abiertos **y** clave del portal.
     *
     * Sin clave nadie puede entrar, así que pedirlo sería pedir algo imposible:
     * esas empresas se tramitan por correo con el asesor.
     */
    private function empresasPorRevisar()
    {
        return collect(DB::table('radicados as r')
            ->join('contratos as c', 'c.id', '=', 'r.contrato_id')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->join('eps', 'eps.id', '=', 'c.eps_id')
            ->where('eps.codigo', SosConciliacionService::CODIGO_EPS)
            ->where('r.tipo', Radicado::TIPO_EPS)
            ->whereIn('r.estado', [Radicado::ESTADO_PENDIENTE, Radicado::ESTADO_TRAMITE, Radicado::ESTADO_ERROR])
            ->where('c.estado', 'vigente')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('clave_accesos as k')
                ->whereColumn('k.razon_social_id', 'rs.id')
                ->where(fn ($q) => PortalesEntidades::filtrarClaves($q, 'sos', 'EPS', '%SOS%', 'k'))
                ->where('k.activo', true)
                ->whereNotNull('k.contrasena')->where('k.contrasena', '<>', ''))
            ->groupBy('rs.nit', 'rs.razon_social')
            ->selectRaw('rs.nit, rs.razon_social, count(*) as pendientes')
            ->orderByDesc('pendientes')
            ->get());
    }
}
