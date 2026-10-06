<?php

namespace App\Services;

use App\Models\WhatsappConversacion;
use Illuminate\Support\Facades\Log;

/**
 * Prospectos que quieren trabajar con el aliado: asesores (afilian bajo la empresa
 * del aliado) y empresas aliadas (su propia marca con la plataforma).
 *
 * Orienta con los mismos números públicos de brynex.co/aliados (config/alianzas.php
 * y la escalera de config/asesores.php), guarda en la conversación qué dijo ser y
 * cuántas personas maneja, y avisa por WhatsApp a quien deba atenderlo según el
 * tamaño. No negocia nada: lo que no cubre, lo cierra una persona.
 */
class ProspectoAliadoService
{
    public const TIPOS = ['asesor' => 'Asesor', 'empresa' => 'Empresa', 'empleador' => 'Empleador'];

    public function __construct(private AlertaOperativaService $alertas) {}

    /**
     * Camino, cifras y texto listo para que la IA lo ponga en palabras.
     *
     * @return array{camino: string, sugerencia: string, resumen: string, url: string}
     */
    public function orientar(int $aliadoId, string $tipo, int $personas): array
    {
        $c = config('alianzas');
        $n = max(0, $personas);
        $url = $c['url'].'?personas='.$n.'&perfil='.($tipo === 'empresa' ? 'empresa' : 'asesor');

        if ($tipo === 'empleador') {
            return [
                'camino' => 'cliente',
                'sugerencia' => 'Cliente empleador',
                'resumen' => 'Quiere afiliar a sus propios trabajadores: es un cliente, no un aliado. Cotízale con cotizar_plan como '
                    .'dependientes (pregunta a qué se dedican para el nivel de riesgo) y, cuando quiera avanzar, pásalo con hablar_con_asesor.',
                'url' => '',
            ];
        }

        $umbral = (int) $c['umbral_empresa'];
        $cerca = (int) $c['cerca'];

        if ($tipo === 'empresa' && $n >= $cerca) {
            $base = max($n, $umbral);
            $precios = [];
            foreach ($c['planes'] as $p) {
                $precios[] = $p['nombre'].' $'.number_format(max($base * $p['valor'], $c['min_plataforma']), 0, ',', '.').' al mes ('.$p['incluye'].')';
            }
            $faltan = max($umbral - $n, 0);
            $resumen = ($faltan
                    ? "Le faltan {$faltan} para el mínimo de {$umbral} afiliados de una alianza: puede empezar con {$n} y tiene tres meses para llegar a {$umbral}; desde el tercer mes se factura sobre {$umbral}. "
                    : "Con {$n} afiliados su camino es una alianza con su propio logo y todo a su nombre. ")
                .'Valores al mes con su propia marca: '.implode('; ', $precios).'. '
                .'Si además quiere que Brygar le haga las afiliaciones: $'.number_format($c['afiliacion']['brygar'], 0, ',', '.').' por contrato una sola vez (o $'
                .number_format($c['afiliacion']['equipo'], 0, ',', '.').' si las hace su equipo con la plataforma); en la Integral van incluidas. '
                .'La plataforma dedicada tiene un mínimo de $'.number_format($c['min_plataforma'], 0, ',', '.').' al mes. Valores antes de IVA.';

            return ['camino' => 'alianza', 'sugerencia' => $faltan ? "Alianza (le faltan {$faltan} para 100)" : 'Alianza para empresas', 'resumen' => $resumen, 'url' => $url];
        }

        // Asesor (o empresa pequeña): la escalera del aliado
        $escalera = config("asesores.escaleras.$aliadoId");
        $niveles = $escalera['niveles'] ?? [1 => 20, 5 => 30, 10 => 40, 20 => 50];
        $pct = 0;
        $siguiente = null;
        foreach ($niveles as $desde => $p) {
            if ($n >= $desde) {
                $pct = (int) $p;
            } elseif ($siguiente === null) {
                $siguiente = ['desde' => (int) $desde, 'pct' => (int) $p];
            }
        }
        $admon = (int) $c['asesor']['admon_lista'];
        $gana = (int) round($n * $admon * $pct / 100);
        $afil = (int) $c['asesor']['afiliacion_ejemplo'];
        $maximo = max($niveles);

        $resumen = ($tipo === 'empresa'
                ? "Con {$n} personas todavía no le conviene una alianza (son desde {$umbral} afiliados y la plataforma dedicada cuesta mínimo $"
                    .number_format($c['min_plataforma'], 0, ',', '.')." al mes): le recomendamos el Plan Asesor, y cuando llegue a {$umbral} da el salto a su propia marca. "
                : "Con {$n} personas su camino es el Plan Asesor: trabaja con la empresa de Brygar, sin pagar plataforma. ")
            .'Gana la mitad de cada afiliación (de un plan de $'.number_format($afil, 0, ',', '.').' son $'.number_format($afil / 2, 0, ',', '.')
            .' para él; el valor depende del plan) y un porcentaje de la administración mensual que sube con la cartera: '
            .implode(', ', array_map(fn ($d, $p) => "{$p} % desde {$d}", array_keys($niveles), $niveles)).'. '
            .($n > 0
                ? "Con {$n} personas estaría en el {$pct} %: unos $".number_format($gana, 0, ',', '.').' al mes de administración (sobre $'.number_format($admon, 0, ',', '.').' por persona), más las afiliaciones. '
                : '')
            .($siguiente ? "Con {$siguiente['desde']} sube al {$siguiente['pct']} %. " : 'Ya está en el nivel más alto. ')
            ."Los tres primeros meses arranca con el {$maximo} % mientras cumple las metas (10 personas al cierre del segundo mes y 20 al del tercero). "
            .($n < 5 ? 'Con menos de 5 personas empieza refiriendo (20 % durante 6 meses por cada cliente) y al llegar a 5 recibe su acceso al programa. ' : '')
            .($tipo === 'asesor' && $n >= $umbral ? "Con {$n} también le alcanza para una alianza con su propia marca, si la prefiere. " : '');

        return ['camino' => 'asesor', 'sugerencia' => "Plan Asesor {$pct} %", 'resumen' => $resumen, 'url' => $url];
    }

    /**
     * Es un prospecto que quiere trabajar con nosotros: ya perfilado como asesor o
     * empresa, o llegó por un anuncio para asesores (aunque todavía no haya contestado).
     */
    public function esProspectoAliado(WhatsappConversacion $conv): bool
    {
        if (in_array($conv->perfil_aliado, ['asesor', 'empresa'], true)) {
            return true;
        }
        if ($conv->origen_publicacion_id) {
            $pieza = \App\Models\Publicacion::find($conv->origen_publicacion_id);

            return $pieza && \App\Services\Ia\AsistenteIaService::esPiezaDeAsesores($pieza);
        }

        return false;
    }

    /**
     * Si este usuario NO debe tomar la conversación, devuelve el mensaje que explica
     * quién la atiende; null si puede. Pueden: el superadmin, los responsables
     * configurados y quien ya la tenga asignada.
     */
    public function porQueNoPuedeAtender(?\App\Models\User $user, WhatsappConversacion $conv): ?string
    {
        if (! $user || ! $this->esProspectoAliado($conv)) {
            return null;
        }
        $contactos = config("alianzas.contactos.{$conv->aliado_id}");
        if (! $contactos) {
            return null;
        }
        $permitidos = array_filter([$contactos['mayor']['user_id'] ?? null, $contactos['menor']['user_id'] ?? null, $conv->asignado_a]);
        if (in_array((int) $user->id, array_map('intval', $permitidos), true) || (method_exists($user, 'hasRole') && $user->hasRole('superadmin'))) {
            return null;
        }
        $responsable = $conv->personas_declaradas !== null
            ? $this->responsable((int) $conv->aliado_id, (int) $conv->personas_declaradas)
            : null;
        $quien = $responsable
            ? $responsable['nombre']
            : $contactos['menor']['nombre'].' o '.$contactos['mayor']['nombre'].' (según cuántas personas maneje)';

        return 'Este contacto quiere trabajar con nosotros como asesor o empresa. Lo atiende '.$quien
            .': no lo tomes ni le escribas por aquí. La IA ya lo está orientando y a esa persona se le avisa por WhatsApp.';
    }

    /** Quién atiende a este prospecto según cuántas personas maneja. */
    public function responsable(int $aliadoId, int $personas): ?array
    {
        $c = config("alianzas.contactos.$aliadoId");
        if (! $c) {
            return null;
        }

        return $personas > (int) $c['mayores_de'] ? $c['mayor'] : $c['menor'];
    }

    /**
     * Guarda el perfil en la conversación, orienta y avisa a quien lo atiende.
     * Con $simular no escribe ni avisa (simulador de la IA).
     */
    public function aplicar(WhatsappConversacion $conv, string $tipo, int $personas, bool $avisar = true, bool $simular = false): array
    {
        $tipo = array_key_exists($tipo, self::TIPOS) ? $tipo : 'asesor';
        $orientacion = $this->orientar((int) $conv->aliado_id, $tipo, $personas);
        $responsable = $tipo === 'empleador' ? null : $this->responsable((int) $conv->aliado_id, $personas);

        if (! $simular) {
            $conv->forceFill([
                'perfil_aliado' => $tipo,
                'personas_declaradas' => $personas,
                'perfil_sugerencia' => $orientacion['sugerencia'],
                'perfil_aliado_at' => $conv->perfil_aliado_at ?? now(),
            ])->save();

            if ($avisar && $responsable) {
                $this->avisar($conv, $orientacion, $responsable);
            }
        }

        return $orientacion + ['responsable' => $responsable];
    }

    /**
     * Aviso por WhatsApp a quien atiende, con el resumen. Una vez por responsable:
     * si el prospecto corrige la cantidad y cambia de responsable, se avisa al nuevo.
     */
    public function avisar(WhatsappConversacion $conv, array $orientacion, array $responsable): bool
    {
        if ($conv->perfil_avisado_a === $responsable['nombre']) {
            return true;
        }
        $mensaje = ($conv->nombreMostrar() ?: 'Sin nombre').' ('.$conv->wa_contact_id.') · '
            .strtolower(self::TIPOS[$conv->perfil_aliado] ?? $conv->perfil_aliado).' · '
            .$conv->personas_declaradas.' personas · sugerido: '.$orientacion['sugerencia'].'. '
            .'La IA lo está orientando; para concretar escríbele por el chat: '.route('admin.whatsapp.chat.show', $conv->id);

        $ok = false;
        try {
            $ok = $this->alertas->enviarDesdeAliado((int) $conv->aliado_id, $responsable['numero'], 'Prospecto aliado', $mensaje);
        } catch (\Throwable $e) {
            Log::warning('Prospecto aliado: no se pudo avisar', ['conversacion' => $conv->id, 'error' => $e->getMessage()]);
        }
        if ($ok) {
            $conv->forceFill(['perfil_avisado_a' => $responsable['nombre']])->save();
        }

        return $ok;
    }

    /**
     * Al pasar la conversación a una persona: queda con el responsable del tamaño
     * (asignada si esa persona es usuario del programa) y con el motivo claro para
     * que nadie más la tome.
     */
    public function pasarAlResponsable(WhatsappConversacion $conv, ?string $motivo = null): ?array
    {
        if (! in_array($conv->perfil_aliado, ['asesor', 'empresa'], true)) {
            return null;
        }
        $responsable = $this->responsable((int) $conv->aliado_id, (int) $conv->personas_declaradas);
        if (! $responsable) {
            return null;
        }
        $texto = '🤝 Prospecto '.strtolower(self::TIPOS[$conv->perfil_aliado]).' · '.$conv->personas_declaradas.' personas → lo atiende '
            .$responsable['nombre'].($motivo ? '. '.$motivo : '');
        $datos = ['bot_activo' => false, 'pendiente_atencion' => true, 'pendiente_motivo' => mb_substr($texto, 0, 255)];
        if (! empty($responsable['user_id'])) {
            $datos += ['asignado_a' => $responsable['user_id'], 'asignado_at' => now(), 'estado' => 'asignada'];
        }
        $conv->forceFill($datos)->save();
        $this->avisar($conv, ['sugerencia' => $conv->perfil_sugerencia ?? ''], $responsable);

        return $responsable;
    }
}
