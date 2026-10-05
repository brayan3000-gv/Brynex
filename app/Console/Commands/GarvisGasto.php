<?php

namespace App\Console\Commands;

use App\Jobs\GarvisFotoJob;
use App\Models\Bitacora;
use App\Models\Finanzas\CategoriaGasto;
use App\Models\Finanzas\Cuenta;
use App\Models\Finanzas\Gasto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registra en Finanzas los gastos que Brayan le reporta a GARVIS por WhatsApp.
 *
 * GARVIS corre en GitHub Actions y no tiene ni la base ni una sesión de Brynex: cuando
 * Brayan confirma un gasto, deja gasto.json y el workflow lo manda por la entrada estándar
 * a /usr/local/sbin/garvis-responder-gh en modo `gasto`, que llama a este comando. Lo que
 * este comando imprime es lo que le llega a Brayan, así que se escribe para él.
 *
 *   echo '{"referencia":"12-345","gastos":[{"monto":45000,"categoria":"Mercado"}]}' \
 *     | php artisan garvis:gasto
 *   php artisan garvis:gasto --catalogo   # categorías y cuentas, sin saldos
 *
 * Todo o nada: si un renglón no sirve no se registra ninguno, para que Brayan no tenga
 * que adivinar cuáles quedaron.
 */
class GarvisGasto extends Command
{
    protected $signature = 'garvis:gasto {--catalogo : Solo lista las categorías y cuentas}';

    protected $description = 'Registra en Finanzas los gastos que Brayan confirmó con GARVIS (JSON por stdin)';

    /** Un gasto personal más grande que esto es casi seguro un cero de más. */
    private const MONTO_MAXIMO = 50_000_000;

    /** Un recibo viejo se registra en la pantalla, con calma; por WhatsApp es lo reciente. */
    private const DIAS_ATRAS = 62;

    private const MAX_RENGLONES = 20;

    public function handle(): int
    {
        $dueno = User::where('cedula', config('finanzas.cedula_dueno'))->first();

        if (! $dueno) {
            $this->line('⚠️ No encontré al dueño de Finanzas en Brynex; no registré nada.');

            return self::FAILURE;
        }

        if ($this->option('catalogo')) {
            $this->imprimirCatalogo($dueno->id);

            return self::SUCCESS;
        }

        $entrada = json_decode((string) stream_get_contents(STDIN), true);

        if (! is_array($entrada) || ! isset($entrada['gastos']) || ! is_array($entrada['gastos']) || $entrada['gastos'] === []) {
            return $this->rechazar('lo que llegó no trae gastos.');
        }

        $referencia = (string) ($entrada['referencia'] ?? '');
        if (! preg_match('/^[A-Za-z0-9-]{1,60}$/', $referencia)) {
            return $this->rechazar('falta la referencia del mensaje.');
        }

        if (count($entrada['gastos']) > self::MAX_RENGLONES) {
            return $this->rechazar('son más de '.self::MAX_RENGLONES.' gastos en un solo mensaje.');
        }

        $categorias = CategoriaGasto::where('user_id', $dueno->id)->activas()->get();
        $cuentas = Cuenta::where('user_id', $dueno->id)->activas()->orderBy('orden')->get();

        // Primero se revisa todo; solo si todo sirve se escribe.
        $renglones = [];
        foreach (array_values($entrada['gastos']) as $i => $g) {
            $n = count($entrada['gastos']) > 1 ? ' (renglón '.($i + 1).')' : '';
            $renglon = is_array($g) ? $this->revisar($g, $categorias, $cuentas) : 'no es un gasto.';
            if (is_string($renglon)) {
                return $this->rechazar($renglon.$n);
            }
            $renglon['origen'] = 'garvis:'.$referencia.'#'.($i + 1);
            $renglones[] = $renglon;
        }

        // La bitácora toma el usuario de la sesión; en consola no hay, así que se pone el dueño.
        Auth::setUser($dueno);

        $lineas = [];
        DB::connection('finanzas')->transaction(function () use ($renglones, $dueno, &$lineas) {
            $creadas = [];
            foreach ($renglones as $r) {
                $ya = Gasto::where('origen', $r['origen'])->first();
                if ($ya) {
                    $lineas[] = '↩️ Ya estaba registrado: '.$this->resumen($ya->monto, $r).' (#'.$ya->id.')';

                    continue;
                }

                // Dos renglones con la misma categoría nueva la crean una sola vez.
                $clave = $this->normalizar((string) $r['categoria_nueva']);
                $categoriaId = $r['categoria']?->id ?? $creadas[$clave] ??= CategoriaGasto::create([
                    'user_id' => $dueno->id,
                    'nombre' => $r['categoria_nueva'],
                    'icono' => '📂',
                    'color' => '#64748b',
                    'es_recurrente' => false,
                    'activo' => true,
                    'orden' => 50,
                ])->id;

                $gasto = Gasto::create([
                    'user_id' => $dueno->id,
                    'categoria_id' => $categoriaId,
                    'cuenta_id' => $r['cuenta']?->id,
                    'fecha' => $r['fecha']->toDateString(),
                    'monto' => $r['monto'],
                    'descripcion' => $r['descripcion'],
                    'tipo_movimiento' => 'gasto',
                    'es_patrimonio' => false,
                    'soporte_path' => $this->copiarFoto($r['foto']),
                    'origen' => $r['origen'],
                ]);

                $linea = '✅ Registrado: '.$this->resumen($gasto->monto, $r).' (#'.$gasto->id.')';
                if ($r['foto'] && ! $gasto->soporte_path) {
                    $linea .= "\n   La foto del recibo ya no estaba en el servidor; quedó sin soporte.";
                }
                $lineas[] = $linea;

                Bitacora::registrar('created', 'FinanzasGasto', $gasto->id,
                    'GARVIS registró por WhatsApp un gasto de $'.number_format($gasto->monto, 0, ',', '.'),
                    ['origen' => $r['origen'], 'categoria_id' => $categoriaId, 'cuenta_id' => $r['cuenta']?->id]);
            }
        });

        $this->line(implode("\n", $lineas));

        return self::SUCCESS;
    }

    /**
     * Revisa un renglón y lo devuelve listo para guardar, o el motivo por el que no sirve.
     */
    private function revisar(array $g, $categorias, $cuentas): array|string
    {
        $monto = $g['monto'] ?? null;
        if (! is_numeric($monto) || (float) $monto != (int) $monto || (int) $monto < 1) {
            return 'el monto tiene que ser un número entero de pesos.';
        }
        $monto = (int) $monto;
        if ($monto > self::MONTO_MAXIMO) {
            return 'el monto pasa de $'.number_format(self::MONTO_MAXIMO, 0, ',', '.').'; eso regístralo en la pantalla.';
        }

        $hoy = Carbon::now('America/Bogota')->startOfDay();
        $fechaTexto = (string) ($g['fecha'] ?? $hoy->toDateString());
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaTexto)
            ? Carbon::createFromFormat('!Y-m-d', $fechaTexto, 'America/Bogota')
            : false;
        if (! $fecha || $fecha->format('Y-m-d') !== $fechaTexto) {
            return 'la fecha no es válida.';
        }
        if ($fecha->gt($hoy)) {
            return 'la fecha es de un día que todavía no llega.';
        }
        if ($fecha->lt($hoy->copy()->subDays(self::DIAS_ATRAS))) {
            return 'la fecha es de hace más de dos meses; eso regístralo en la pantalla.';
        }

        $nombre = trim((string) ($g['categoria'] ?? ''));
        if ($nombre === '') {
            return 'falta la categoría.';
        }
        $categoria = $categorias->first(fn ($c) => $this->normalizar($c->nombre) === $this->normalizar($nombre));
        $categoriaNueva = null;
        if (! $categoria) {
            // Una categoría nueva solo si Brayan la pidió; si no, es un nombre mal escrito.
            if (empty($g['crear_categoria'])) {
                return 'no tengo la categoría «'.$nombre.'». Las que hay: '.$categorias->pluck('nombre')->implode(', ').'.';
            }
            if (mb_strlen($nombre) > 50) {
                return 'el nombre de la categoría nueva es muy largo.';
            }
            $categoriaNueva = $nombre;
        }

        $cuenta = null;
        $nombreCuenta = trim((string) ($g['cuenta'] ?? ''));
        if ($nombreCuenta !== '') {
            $cuenta = $cuentas->first(fn ($c) => $this->normalizar($c->nombre) === $this->normalizar($nombreCuenta));
            if (! $cuenta) {
                return 'no tengo la cuenta «'.$nombreCuenta.'». Las que hay: '.$cuentas->pluck('nombre')->implode(', ').'.';
            }
        } else {
            // Igual que la pantalla: sin cuenta, sale de la primera.
            $cuenta = $cuentas->first();
        }

        $descripcion = trim((string) ($g['descripcion'] ?? ''));
        $foto = (string) ($g['foto'] ?? '');
        if ($foto !== '' && ! preg_match('/^[A-Za-z0-9]{32}\.(jpe?g|png|webp)$/i', $foto)) {
            return 'el nombre de la foto del recibo no es válido.';
        }

        return [
            'monto' => $monto,
            'fecha' => $fecha,
            'categoria' => $categoria,
            'categoria_nueva' => $categoriaNueva,
            'cuenta' => $cuenta,
            'descripcion' => $descripcion === '' ? null : mb_strimwidth($descripcion, 0, 255, '…'),
            'foto' => $foto === '' ? null : $foto,
        ];
    }

    /** La foto que Brayan mandó por WhatsApp vive un día en garvis/fotos; el soporte se queda. */
    private function copiarFoto(?string $foto): ?string
    {
        if (! $foto) {
            return null;
        }

        $disco = Storage::disk('local');
        $origen = GarvisFotoJob::CARPETA.'/'.$foto;
        if (! $disco->exists($origen)) {
            return null;
        }

        $destino = 'finanzas/gastos_soportes/'.Str::random(40).'.'.strtolower(pathinfo($foto, PATHINFO_EXTENSION));

        return $disco->copy($origen, $destino) ? $destino : null;
    }

    private function resumen($monto, array $r): string
    {
        $partes = [
            '$'.number_format((float) $monto, 0, ',', '.'),
            $r['categoria']?->nombre ?? $r['categoria_nueva'].' (categoría nueva)',
        ];
        if ($r['cuenta']) {
            $partes[] = $r['cuenta']->nombre;
        }
        $partes[] = $r['fecha']->locale('es')->isoFormat('ddd D MMM');
        if ($r['descripcion']) {
            $partes[] = $r['descripcion'];
        }

        return implode(' · ', $partes);
    }

    /** Solo nombres: ni saldos ni montos, porque esto queda en el log de Actions. */
    private function imprimirCatalogo(int $userId): void
    {
        $categorias = CategoriaGasto::where('user_id', $userId)->activas()->orderBy('orden')->orderBy('nombre')->pluck('nombre');
        $cuentas = Cuenta::where('user_id', $userId)->activas()->orderBy('orden')->pluck('nombre');

        $this->line('Categorías de gasto: '.$categorias->implode(', '));
        $this->line('Cuentas (la primera es la de por defecto): '.$cuentas->implode(', '));
    }

    private function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->squish()->toString();
    }

    private function rechazar(string $motivo): int
    {
        $this->line('⚠️ No registré nada: '.$motivo);

        return self::FAILURE;
    }
}
