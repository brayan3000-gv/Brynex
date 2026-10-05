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
 * Registra en Finanzas los gastos que Brayan le reporta a GARVIS por WhatsApp, anula los
 * que dice que no eran y le arma el resumen del día.
 *
 * GARVIS corre en GitHub Actions y no tiene ni la base ni una sesión de Brynex: cuando
 * Brayan le cuenta un gasto, deja gasto.json y el workflow lo manda por la entrada estándar
 * a /usr/local/sbin/garvis-responder-gh en modo `gasto`, que llama a este comando. Lo que
 * este comando imprime es lo que le llega a Brayan, así que se escribe para él.
 *
 *   echo '{"referencia":"12-345","gastos":[{"monto":45000,"categoria":"Mercado"}]}' \
 *     | php artisan garvis:gasto
 *   echo '{"referencia":"12-346","anular":[123]}' | php artisan garvis:gasto
 *   echo '{"referencia":"12-347","resumen":"hoy"}' | php artisan garvis:gasto
 *   php artisan garvis:gasto --catalogo   # categorías y cuentas, sin saldos
 *
 * Todo o nada: si un renglón no sirve no se registra ni se anula ninguno, para que Brayan no tenga
 * que adivinar cuáles quedaron.
 */
class GarvisGasto extends Command
{
    protected $signature = 'garvis:gasto {--catalogo : Solo lista las categorías y cuentas}';

    protected $description = 'Registra, anula y resume en Finanzas los gastos que Brayan le reporta a GARVIS (JSON por stdin)';

    /** Un gasto personal más grande que esto es casi seguro un cero de más. */
    private const MONTO_MAXIMO = 50_000_000;

    /** Un recibo viejo se registra en la pantalla, con calma; por WhatsApp es lo reciente. */
    private const DIAS_ATRAS = 62;

    private const MAX_RENGLONES = 20;

    /** «No era» se dice enseguida; pasada una semana, se borra en la pantalla. */
    private const DIAS_PARA_ANULAR = 7;

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
        if (! is_array($entrada)) {
            return $this->rechazar('lo que llegó no se entiende.');
        }

        $gastos = $entrada['gastos'] ?? [];
        $anular = $entrada['anular'] ?? [];
        $resumen = $entrada['resumen'] ?? null;
        if (! is_array($gastos) || ! is_array($anular) || ($gastos === [] && $anular === [] && ! $resumen)) {
            return $this->rechazar('lo que llegó no trae gastos.');
        }

        $referencia = (string) ($entrada['referencia'] ?? '');
        if (! preg_match('/^[A-Za-z0-9-]{1,60}$/', $referencia)) {
            return $this->rechazar('falta la referencia del mensaje.');
        }

        if (count($gastos) > self::MAX_RENGLONES || count($anular) > self::MAX_RENGLONES) {
            return $this->rechazar('son más de '.self::MAX_RENGLONES.' gastos en un solo mensaje.');
        }

        // Primero se revisa todo; solo si todo sirve se escribe.
        $renglones = $this->revisarGastos($gastos, $dueno->id, $referencia);
        if (is_string($renglones)) {
            return $this->rechazar($renglones);
        }

        $porAnular = $this->revisarAnulaciones($anular, $dueno->id);
        if (is_string($porAnular)) {
            return $this->rechazar($porAnular);
        }

        $diaResumen = null;
        if ($resumen) {
            $diaResumen = $this->fecha((string) $resumen);
            if (! $diaResumen) {
                return $this->rechazar('la fecha del resumen no es válida.');
            }
        }

        // La bitácora toma el usuario de la sesión; en consola no hay, así que se pone el dueño.
        Auth::setUser($dueno);

        $lineas = [];
        DB::connection('finanzas')->transaction(function () use ($renglones, $porAnular, $dueno, &$lineas) {
            foreach ($porAnular as $gasto) {
                $lineas[] = $this->anularGasto($gasto);
            }
            $creadas = [];
            foreach ($renglones as $r) {
                $lineas[] = $this->registrar($r, $dueno->id, $creadas);
            }
        });

        if ($diaResumen) {
            $lineas[] = $this->resumenDelDia($dueno->id, $diaResumen);
        } elseif ($renglones !== []) {
            $lineas[] = '¿Quieres ver el resumen de hoy? Si alguno no era, dime «anula el #…».';
        }

        $this->line(implode("\n", array_filter($lineas)));

        return self::SUCCESS;
    }

    /** @return array<int, array>|string */
    private function revisarGastos(array $gastos, int $userId, string $referencia): array|string
    {
        if ($gastos === []) {
            return [];
        }

        $categorias = CategoriaGasto::where('user_id', $userId)->activas()->get();
        $cuentas = Cuenta::where('user_id', $userId)->activas()->orderBy('orden')->get();

        $renglones = [];
        foreach (array_values($gastos) as $i => $g) {
            $n = count($gastos) > 1 ? ' (renglón '.($i + 1).')' : '';
            $renglon = is_array($g) ? $this->revisar($g, $categorias, $cuentas) : 'no es un gasto.';
            if (is_string($renglon)) {
                return $renglon.$n;
            }
            $renglon['origen'] = 'garvis:'.$referencia.'#'.($i + 1);
            $renglones[] = $renglon;
        }

        return $renglones;
    }

    /**
     * Por aquí solo se anula lo que entró por GARVIS y es reciente: lo digitado en la
     * pantalla, o un gasto viejo, se borra en la pantalla, mirándolo.
     *
     * @return \Illuminate\Support\Collection<int, Gasto>|string
     */
    private function revisarAnulaciones(array $ids, int $userId)
    {
        if ($ids === []) {
            return collect();
        }

        foreach ($ids as $id) {
            if (! is_int($id) && ! ctype_digit((string) $id)) {
                return 'el número del gasto por anular no es válido.';
            }
        }

        $gastos = Gasto::where('user_id', $userId)->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $gasto = $gastos->get((int) $id);
            if (! $gasto) {
                return 'no encontré el gasto #'.$id.'.';
            }
            if (! str_starts_with((string) $gasto->origen, 'garvis:')) {
                return 'el gasto #'.$id.' no lo registré yo; ese se borra en la pantalla de Finanzas.';
            }
            if ($gasto->created_at && $gasto->created_at->lt(now()->subDays(self::DIAS_PARA_ANULAR))) {
                return 'el gasto #'.$id.' tiene más de '.self::DIAS_PARA_ANULAR.' días; ese se borra en la pantalla de Finanzas.';
            }
        }

        return $gastos->values();
    }

    /** Igual que borrarlo en la pantalla, pero con lo que era guardado en la bitácora. */
    private function anularGasto(Gasto $gasto): string
    {
        $linea = '🗑️ Anulado: $'.number_format((float) $gasto->monto, 0, ',', '.')
            .' · '.($gasto->categoria?->nombre ?? 'sin categoría')
            .($gasto->descripcion ? ' · '.$gasto->descripcion : '').' (#'.$gasto->id.')';

        Bitacora::registrar('deleted', 'FinanzasGasto', $gasto->id,
            'GARVIS anuló por WhatsApp un gasto de $'.number_format((float) $gasto->monto, 0, ',', '.'),
            $gasto->only(['fecha', 'monto', 'descripcion', 'categoria_id', 'cuenta_id', 'origen']));

        if ($gasto->soporte_path) {
            Storage::disk('local')->delete($gasto->soporte_path);
        }
        $gasto->delete();

        return $linea;
    }

    private function registrar(array $r, int $userId, array &$creadas): string
    {
        $ya = Gasto::where('origen', $r['origen'])->first();
        if ($ya) {
            return '↩️ Ya estaba registrado: '.$this->resumen($ya->monto, $r).' (#'.$ya->id.')';
        }

        // Dos renglones con la misma categoría nueva la crean una sola vez.
        $clave = $this->normalizar((string) $r['categoria_nueva']);
        $categoriaId = $r['categoria']?->id ?? $creadas[$clave] ??= CategoriaGasto::create([
            'user_id' => $userId,
            'nombre' => $r['categoria_nueva'],
            'icono' => '📂',
            'color' => '#64748b',
            'es_recurrente' => false,
            'activo' => true,
            'orden' => 50,
        ])->id;

        $gasto = Gasto::create([
            'user_id' => $userId,
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

        Bitacora::registrar('created', 'FinanzasGasto', $gasto->id,
            'GARVIS registró por WhatsApp un gasto de $'.number_format($gasto->monto, 0, ',', '.'),
            ['origen' => $r['origen'], 'categoria_id' => $categoriaId, 'cuenta_id' => $r['cuenta']?->id]);

        return $linea;
    }

    /** Todos los gastos del día, también los digitados en la pantalla. */
    private function resumenDelDia(int $userId, Carbon $dia): string
    {
        $gastos = Gasto::with('categoria')
            ->where('user_id', $userId)
            ->where('tipo_movimiento', 'gasto')
            ->whereDate('fecha', $dia->toDateString())
            ->orderBy('id')
            ->get();

        $titulo = '*Gastos del '.$dia->locale('es')->isoFormat('dddd D [de] MMMM').'*';
        if ($gastos->isEmpty()) {
            return $titulo."\nNinguno.";
        }

        $renglones = $gastos->take(30)->map(fn ($g) => '- $'.number_format((float) $g->monto, 0, ',', '.')
            .' · '.($g->categoria?->nombre ?? 'sin categoría')
            .($g->descripcion ? ' · '.$g->descripcion : '').' (#'.$g->id.')');
        if ($gastos->count() > 30) {
            $renglones->push('- … y '.($gastos->count() - 30).' más');
        }

        return $titulo."\n".$renglones->implode("\n")
            ."\n*Total: $".number_format((float) $gastos->sum('monto'), 0, ',', '.').'*';
    }

    private function fecha(string $texto): ?Carbon
    {
        $hoy = Carbon::now('America/Bogota')->startOfDay();
        if ($texto === 'hoy') {
            return $hoy;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto)) {
            return null;
        }
        $fecha = Carbon::createFromFormat('!Y-m-d', $texto, 'America/Bogota');

        return $fecha && $fecha->format('Y-m-d') === $texto ? $fecha : null;
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
        $fecha = $this->fecha((string) ($g['fecha'] ?? 'hoy'));
        if (! $fecha) {
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
        $this->line('⚠️ No hice nada: '.$motivo);

        return self::FAILURE;
    }
}
