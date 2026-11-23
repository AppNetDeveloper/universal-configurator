<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Correo: mail() de PHP, SMTP propio (SSL / STARTTLS / AUTH) o «log» (lo escribe en data/mail.log, para
 * desarrollo y pruebas). Se configura en Ajustes. La contraseña SMTP se guarda cifrada con la clave de config.php.
 */

function secret_key(): string
{
    $secret = (string) config('secret', '');
    if ($secret === '') throw new UcError('Falta la clave "secret" en data/config.php');
    return hash('sha256', $secret . '|secrets', true);
}

function secret_encrypt(string $plain): string
{
    if ($plain === '') return '';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}

function secret_decrypt(string $stored): string
{
    if ($stored === '') return '';
    $bin = base64_decode(substr($stored, 3), true);
    $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    $plain = $bin !== false && str_starts_with($stored, 's1:') ? sodium_crypto_secretbox_open(substr($bin, $n), substr($bin, 0, $n), secret_key()) : false;
    if ($plain === false) throw new UcError(t('No se pudo descifrar la contraseña SMTP guardada: vuelve a escribirla en Ajustes.'));
    return $plain;
}

/** Envía un correo de texto. Lanza UcError si no se pudo. */
function mail_send(array $to, string $subject, string $body): void
{
    $clean = fn(string $s) => trim(str_replace(["\r", "\n"], ' ', $s));
    $to = array_values(array_filter(array_map($clean, $to), fn($t) => filter_var($t, FILTER_VALIDATE_EMAIL)));
    if (!$to) throw new UcError(t('No hay destinatarios válidos.'));
    $transport = setting('mail_transport');
    if ($transport === 'log') {
        $line = '[' . now() . '] Para: ' . implode(', ', $to) . "\nAsunto: " . $clean($subject) . "\n" . $body . "\n----\n";
        if (file_put_contents(UC_DATA . '/mail.log', $line, FILE_APPEND | LOCK_EX) === false) throw new UcError('No se pudo escribir data/mail.log');
        @chmod(UC_DATA . '/mail.log', 0600);
        return;
    }
    $from = $clean(setting('mail_from')) ?: 'no-reply@' . preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new UcError(t('El remitente de los correos no es válido: revísalo en Ajustes.'));
    $encode = fn(string $s) => preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $headers = [
        'Date' => date(DATE_RFC2822),
        'From' => $encode($clean(setting('empresa'))) . " <$from>",
        'To' => implode(', ', $to),
        'Subject' => $encode($clean($subject)),
        'Message-ID' => '<' . bin2hex(random_bytes(12)) . "@$domain>",
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
        'X-Mailer' => 'Universal Configurator ' . UC_VERSION,
    ];
    $encodedBody = rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n"));
    if ($transport !== 'smtp') {
        $h = $headers;
        unset($h['To'], $h['Subject']);
        $lines = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($h), $h));
        if (!@mail(implode(', ', $to), $headers['Subject'], $encodedBody, $lines)) throw new UcError(t('La función mail() de PHP falló: este servidor no envía así. Configura SMTP en Ajustes.'));
        return;
    }
    $cfg = ['smtp_host' => setting('smtp_host'), 'smtp_port' => (int) setting('smtp_port'), 'smtp_encryption' => setting('smtp_encryption'),
        'smtp_username' => setting('smtp_username'), 'smtp_password' => secret_decrypt(setting('smtp_password')), 'smtp_verify_cert' => true];
    if ($cfg['smtp_host'] === '') throw new UcError(t('Falta el servidor SMTP en Ajustes.'));
    $data = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $encodedBody;
    (new SmtpClient($cfg))->send($from, $to, $data);
}

/** Cliente SMTP mínimo: SSL implícito o STARTTLS, AUTH PLAIN/LOGIN. */
final class SmtpClient
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(private array $cfg) {}

    public function send(string $from, array $to, string $data): void
    {
        try {
            $this->connect();
            $ehlo = $this->ehlo();
            if ($this->cfg['smtp_encryption'] === 'tls') {
                if (!str_contains($ehlo, 'STARTTLS')) throw new UcError('El servidor no ofrece STARTTLS: prueba con cifrado SSL (puerto 465) o ninguno');
                $this->cmd('STARTTLS', 220);
                if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new UcError('Falló la negociación TLS (STARTTLS). Revisa el certificado del servidor o desactiva su verificación.');
                }
                $ehlo = $this->ehlo();
            }
            if ($this->cfg['smtp_username'] !== '') $this->auth($ehlo);
            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            foreach ($to as $rcpt) $this->cmd('RCPT TO:<' . $rcpt . '>', [250, 251]);
            $this->cmd('DATA', 354);
            // Cuerpo en base64: ninguna línea empieza por "." (no hace falta dot-stuffing).
            $this->cmd($data . "\r\n.", 250);
            $this->cmd('QUIT', 221);
        } finally {
            if ($this->sock) fclose($this->sock);
            $this->sock = null;
        }
    }

    private function connect(): void
    {
        $host = $this->cfg['smtp_host'];
        $port = (int) $this->cfg['smtp_port'];
        $verify = (bool) $this->cfg['smtp_verify_cert'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'peer_name' => $host]]);
        $scheme = $this->cfg['smtp_encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $target = $host;
        $sock = @stream_socket_client("$scheme://$target:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new UcError($errstr === '' && $scheme === 'ssl'
                ? "Falló la conexión SSL con $host:$port: el certificado no es válido" . ($verify ? ' (o es autofirmado)' : '') . ' o ese puerto no usa SSL directo (prueba STARTTLS en el 587).'
                : "No se pudo conectar a $host:$port ($errstr). Revisa servidor, puerto y cifrado.");
        }
        stream_set_timeout($sock, 15);
        $this->sock = $sock;
        $this->expect(220);
    }

    private function ehlo(): string
    {
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost')) ?: 'localhost';
        return $this->cmd("EHLO $host", 250);
    }

    private function auth(string $ehlo): void
    {
        $user = $this->cfg['smtp_username'];
        $pass = $this->cfg['smtp_password'];
        try {
            if (preg_match('/AUTH[ =][^\n]*PLAIN/i', $ehlo)) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), 235, true);
            } else {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($user), 334, true);
                $this->cmd(base64_encode($pass), 235, true);
            }
        } catch (UcError $ex) {
            throw new UcError('Autenticación SMTP rechazada: revisa usuario y contraseña. ' . $ex->getMessage());
        }
    }

    /** Envía una línea y comprueba el código de respuesta. $secret evita que la línea aparezca en errores. */
    private function cmd(string $line, int|array $expect, bool $secret = false): string
    {
        if (fwrite($this->sock, $line . "\r\n") === false) throw new UcError('Se perdió la conexión con el servidor SMTP');
        return $this->expect($expect, $secret ? '' : strtok($line, "\r\n"));
    }

    private function expect(int|array $codes, string $after = ''): string
    {
        $resp = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        if ($resp === '') throw new UcError('El servidor SMTP no responde (tiempo agotado)');
        if (!in_array((int) substr($resp, 0, 3), (array) $codes, true)) {
            $cmd = $after !== '' && !str_starts_with($after, 'AUTH') ? ' tras «' . mb_substr($after, 0, 40) . '»' : '';
            throw new UcError('El servidor SMTP respondió' . $cmd . ': ' . trim($resp));
        }
        return $resp;
    }
}
