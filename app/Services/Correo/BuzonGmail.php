<?php

namespace App\Services\Correo;

use App\Services\Afiliaciones\DatosAfiliacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envía correos desde la cuenta de Gmail de un aliado (p. ej.
 * seguridadsocial.brygar@gmail.com) por SMTP con la contraseña de aplicación
 * guardada en el módulo de claves. No usa el MAIL_* del .env: BryNex no tiene
 * correo propio y cada aliado envía desde su cuenta.
 */
class BuzonGmail
{
    public function __construct(private string $cuenta, private string $clave) {}

    /**
     * El buzón con el que trabaja el aliado: el suyo, o el de Brygar si tiene el
     * servicio de afiliaciones de BryNex (DatosAfiliacion).
     */
    public static function delAliado(int $aliadoId): self
    {
        $dueno  = DatosAfiliacion::aliadoBuzon($aliadoId);
        $cuenta = config("afiliaciones_correo.buzones.{$dueno}");
        if (! $cuenta) {
            throw new RuntimeException('El aliado no tiene buzón de Gmail configurado para enviar afiliaciones, ni tiene el servicio de afiliaciones de BryNex para usar el de Brygar.');
        }

        return self::conClaveDeAplicacion($cuenta, $dueno);
    }

    /**
     * El buzón de una empresa: su correo de formularios, al que responden las
     * entidades que contestan al correo del formulario (Sanitas). La contraseña
     * de aplicación es de la cuenta, la haya guardado el aliado que sea.
     */
    public static function deCuenta(string $cuenta): self
    {
        return self::conClaveDeAplicacion($cuenta, null);
    }

    /** ¿Hay contraseña de aplicación guardada para esa cuenta? */
    public static function tieneClave(string $cuenta): bool
    {
        return self::claveDeAplicacion($cuenta, null) !== null;
    }

    private static function conClaveDeAplicacion(string $cuenta, ?int $aliadoId): self
    {
        $clave = self::claveDeAplicacion($cuenta, $aliadoId);
        if (! $clave) {
            throw new RuntimeException("No hay contraseña de aplicación de {$cuenta} en el módulo de claves (entidad con GMAIL, 16 letras).");
        }

        return new self($cuenta, $clave);
    }

    private static function claveDeAplicacion(string $cuenta, ?int $aliadoId): ?string
    {
        $fila = DB::table('clave_accesos')
            ->when($aliadoId !== null, fn ($q) => $q->where('aliado_id', $aliadoId), fn ($q) => $q->where('aliado_id', '<>', 1))
            ->where('usuario', $cuenta)
            ->where('entidad', 'like', '%GMAIL%')
            ->where('activo', true)
            ->orderByDesc('updated_at')
            ->get(['contrasena'])
            // La contraseña de aplicación de Google son 16 letras (a veces guardadas con espacios).
            ->first(fn ($c) => strlen(preg_replace('/\s+/', '', (string) $c->contrasena)) === 16);

        return $fila ? preg_replace('/\s+/', '', $fila->contrasena) : null;
    }

    public function cuenta(): string
    {
        return $this->cuenta;
    }

    /**
     * @param  string[]  $para
     * @param  array<int, array{nombre:string, contenido:string, tipo:string}>  $adjuntos
     * @return string Message-ID del correo enviado (sin < >)
     */
    public function enviar(array $para, string $asunto, string $texto, array $adjuntos = [], array $cc = [], ?string $nombreRemitente = null): string
    {
        $messageId = Str::uuid()->toString().'@brynex.co';

        $correo = (new Email())
            ->from(new Address($this->cuenta, $nombreRemitente ?? ''))
            ->to(...$para)
            ->subject($asunto)
            ->text($texto);
        if ($cc) {
            $correo->cc(...$cc);
        }
        $correo->getHeaders()->addIdHeader('Message-ID', $messageId);
        foreach ($adjuntos as $a) {
            $correo->attach($a['contenido'], $a['nombre'], $a['tipo']);
        }

        $this->transporte()->send($correo);

        return $messageId;
    }

    /**
     * Correos recibidos desde una fecha, en modo solo lectura (no los marca como
     * leídos). Se lee "Todos" y no solo la bandeja: si alguien archiva o lee la
     * respuesta en Gmail, igual se procesa. Excluye lo enviado por la cuenta.
     *
     * @return \Generator<int, array{message_id:string, uid:int, in_reply_to:string, referencias:string, de:string, de_nombre:string, asunto:string, fecha:\Carbon\Carbon, texto:string, adjuntos:array}>
     */
    public function recibidos(\Carbon\CarbonInterface $desde, ?callable $interesa = null, int $maximo = 500, ?string $busquedaGmail = null): \Generator
    {
        $cm = new \Webklex\PHPIMAP\ClientManager(['options' => ['fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK, 'soft_fail' => true]]);
        $cliente = $cm->make([
            'host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true,
            'username' => $this->cuenta, 'password' => $this->clave, 'protocol' => 'imap',
        ]);
        $cliente->connect();

        try {
            // "Todos" en cuentas en español, "All Mail" en inglés.
            $carpeta = $cliente->getFolderByPath('[Gmail]/Todos') ?? $cliente->getFolderByPath('[Gmail]/All Mail');
            if (! $carpeta) {
                throw new RuntimeException("No se encontró la carpeta Todos de {$this->cuenta}.");
            }

            // Primero solo encabezados (liviano); el cuerpo y los adjuntos se bajan
            // únicamente de los que interesan, para no llenar la memoria con todo el buzón.
            // Con $busquedaGmail la búsqueda la hace Gmail (X-GM-RAW, misma sintaxis del
            // buscador de Gmail, sin comillas): leer los encabezados de todo el buzón es lento.
            $consulta = $carpeta->query()->leaveUnread()->setFetchBody(false)->setFetchFlags(false)->limit($maximo);
            $consulta = $busquedaGmail
                ? $consulta->where('CUSTOM X-GM-RAW', trim($busquedaGmail).' after:'.$desde->copy()->subDay()->format('Y/m/d'))
                : $consulta->since($desde->copy()->startOfDay());
            $cabeceras = $consulta->get();

            foreach ($cabeceras as $h) {
                $de = $h->getFrom()->first();
                $correoDe = strtolower((string) ($de->mail ?? ''));
                if ($correoDe === strtolower($this->cuenta)) {
                    continue;
                }
                $fecha = \Illuminate\Support\Carbon::parse((string) $h->getDate()->first());
                if ($fecha->lt($desde)) {
                    continue;
                }
                $item = [
                    'message_id'  => trim((string) $h->getMessageId()->first(), '<> '),
                    'uid'         => (int) $h->getUid(),
                    'in_reply_to' => (string) $h->getInReplyTo(),
                    'referencias' => (string) $h->getReferences(),
                    'de'          => $correoDe,
                    'de_nombre'   => self::decodificar((string) ($de->personal ?? '')),
                    'asunto'      => self::decodificar((string) $h->getSubject()->first()),
                    'fecha'       => $fecha,
                ];
                if ($interesa && ! $interesa($item)) {
                    continue;
                }

                // De a uno (generador): un correo con adjuntos grandes no acumula memoria.
                $m = $carpeta->query()->leaveUnread()->setFetchBody(true)->getMessageByUid($item['uid']);
                yield $item + [
                    'texto'    => self::textoNuevo($m->hasTextBody() ? $m->getTextBody() : self::htmlATexto((string) $m->getHTMLBody())),
                    'adjuntos' => $m->getAttachments()->map(fn ($a) => [
                        'nombre' => self::decodificar((string) $a->getName()), 'contenido' => $a->getContent(), 'tipo' => (string) $a->getMimeType(),
                    ])->values()->all(),
                ];
                unset($m);
            }
        } finally {
            $cliente->disconnect();
        }
    }

    public static function htmlATexto(string $html): string
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/tr)\b[^>]*>#i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Asuntos y nombres en MIME encoded-word (=?UTF-8?Q?...?=) a texto. */
    public static function decodificar(string $valor): string
    {
        if (! str_contains($valor, '=?')) {
            return trim($valor);
        }
        $texto = @iconv_mime_decode($valor, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($texto !== false ? $texto : mb_decode_mimeheader($valor));
    }

    /** Quita del cuerpo lo citado de correos anteriores y la firma larga. */
    public static function textoNuevo(string $texto): string
    {
        $texto = str_replace("\r", '', $texto);
        $texto = preg_split('/\n\s*(?:El .{5,120}(?:escribió|escribio):|On .{5,120}wrote:|De: |-{3,}\s*(?:Original|Forwarded|Mensaje))/iu', $texto)[0];
        $lineas = array_filter(explode("\n", $texto), fn ($l) => ! str_starts_with(ltrim($l), '>'));
        $texto = preg_replace('/\s+/u', ' ', implode("\n", $lineas));

        return trim(mb_substr((string) $texto, 0, 2000));
    }

    /** Solo abre sesión SMTP y la cierra: sirve para probar la clave sin enviar nada. */
    public function probar(): void
    {
        $t = new EsmtpTransport('smtp.gmail.com', 465, true);
        $t->setUsername($this->cuenta);
        $t->setPassword($this->clave);
        $t->start();
        $t->stop();
    }

    private function transporte(): Mailer
    {
        $t = new EsmtpTransport('smtp.gmail.com', 465, true);
        $t->setUsername($this->cuenta);
        $t->setPassword($this->clave);

        return new Mailer($t);
    }
}
