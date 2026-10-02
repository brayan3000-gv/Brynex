<?php

namespace App\Console\Commands;

use App\Models\{OperadorCredencial, OperadorPlanilla};
use App\Services\SuaporteApiService;
use Illuminate\Console\Command;

/**
 * Registra (o prueba) las credenciales de las APIs PILA de Enlace Operativo.
 *
 * Los secretos se piden por consola con entrada oculta y quedan cifrados en
 * `operadores_credenciales`; nunca pasan por un archivo del repo. Como la BD
 * es la misma de producción, basta ejecutarlo una vez desde cualquier lado.
 */
class EnlaceCredenciales extends Command
{
    protected $signature = 'enlace:credenciales
                            {--aliado= : ID del aliado dueño de la credencial}
                            {--probar : Solo prueba las credenciales ya guardadas, sin modificarlas}';

    protected $description = 'Configura o prueba las credenciales de las APIs de Enlace Operativo (SuAporte)';

    public function handle(): int
    {
        $aliadoId = (int) ($this->option('aliado') ?: $this->ask('ID del aliado'));

        if (!$aliadoId) {
            $this->error('Debe indicar el aliado.');
            return self::FAILURE;
        }

        $operador = OperadorPlanilla::paraAliado($aliadoId)
            ->where('codigo', 'ARUS')
            ->first();

        if (!$operador) {
            $this->error('El operador Enlace no está activo para este aliado.');
            return self::FAILURE;
        }

        $this->info("Operador: {$operador->nombre} (id {$operador->id})");

        if ($this->option('probar')) {
            return $this->probar($aliadoId, $operador->id);
        }

        $this->line('');
        $this->comment('Las credenciales quedarán cifradas en la base de datos.');
        $this->line('');

        $usuario = $this->ask('Usuario (tipo + número de documento, ej: CC1234567)');
        $contrasena = $this->secret('Contraseña (4 dígitos numéricos)');
        $claveSecreta = $this->secret('Clave secreta generada en el tablero de SuAporte');

        if (!$usuario || !$contrasena || !$claveSecreta) {
            $this->error('Los tres datos son obligatorios.');
            return self::FAILURE;
        }

        $expira = $this->ask('Fecha de vencimiento de la clave secreta (YYYY-MM-DD, Enter para un año desde hoy)')
            ?: now()->addYear()->format('Y-m-d');

        $credencial = OperadorCredencial::updateOrCreate(
            [
                'aliado_id'            => $aliadoId,
                'operador_planilla_id' => $operador->id,
                'razon_social_id'      => null, // una sola cuenta para todas las razones sociales
            ],
            [
                'usuario'                 => $usuario,
                'contrasena'              => $contrasena,
                'clave_secreta'           => $claveSecreta,
                'clave_secreta_expira_at' => $expira,
            ]
        );

        $this->info("Credencial guardada (id {$credencial->id}).");
        $this->line('');

        return $this->probar($aliadoId, $operador->id);
    }

    /** Hace un login real contra Enlace para confirmar que los datos sirven. */
    private function probar(int $aliadoId, int $operadorId): int
    {
        $credencial = OperadorCredencial::paraOperador($aliadoId, $operadorId)->first();

        if (!$credencial) {
            $this->error('No hay credenciales guardadas para este aliado.');
            return self::FAILURE;
        }

        if ($credencial->claveSecretaVencida()) {
            $this->warn('⚠️  La clave secreta figura como vencida el '
                . $credencial->clave_secreta_expira_at->format('Y-m-d') . '.');
        }

        $this->line('Probando autenticación contra Enlace Operativo...');

        $api = new SuaporteApiService([
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'clave_secreta' => $credencial->clave_secreta,
        ]);

        $resultado = $api->autenticar(forzar: true);

        if (!$resultado['success']) {
            $this->error('✗ ' . $resultado['message']);
            return self::FAILURE;
        }

        $this->info('✓ Autenticación exitosa.');

        // Si el aliado indica un NIT, se prueba también la autorización, que es
        // donde falla si la cuenta no tiene perfil sobre ese aportante.
        $nit = $this->ask('NIT de una razón social para probar la autorización (Enter para omitir)');

        if ($nit) {
            $nit = preg_replace('/\D/', '', $nit);

            $aportante = $api->consultarAportante('NI', $nit);
            if (!$aportante['success']) {
                $this->error('✗ ' . $aportante['message']);
                return self::FAILURE;
            }

            $this->info("✓ Aportante encontrado (id {$aportante['id']}).");

            $autorizacion = $api->autorizar($aportante['id'], 'NI', $nit);
            if (!$autorizacion['success']) {
                $this->error('✗ ' . $autorizacion['message']);
                return self::FAILURE;
            }

            $this->info('✓ El usuario tiene autorización sobre el aportante.');
        }

        return self::SUCCESS;
    }
}
