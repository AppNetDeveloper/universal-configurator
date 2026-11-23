<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Proveedores de acceso. «local» (correo + contraseña) está implementado y activo.
 * «bionicadri» (fase 2) entra con el BionicEye del cliente (Laravel Passport) por OAuth2 Authorization Code + PKCE:
 * solo aparece si bionicadri_url y bionicadri_client_id están en Ajustes. La persona se crea o enlaza por
 * external_id (auth_source = 'bionicadri', sin contraseña local); nunca se apodera de una cuenta local con su correo.
 */

interface AuthProvider
{
    public function id(): string;
    public function label(): string;
    public function enabled(): bool;
}

final class LocalAuthProvider implements AuthProvider
{
    public function id(): string { return 'local'; }
    public function label(): string { return t('Correo y contraseña'); }
    public function enabled(): bool { return true; }

    public function login(string $email, string $password): array
    {
        return login_attempt($email, $password);
    }
}

final class BionicAdriAuthProvider implements AuthProvider
{
    /** HTTP inyectable (pruebas): fn(string $method, string $url, array $data, array $headers): ?array */
    public ?Closure $http = null;

    public function id(): string { return 'bionicadri'; }
    public function label(): string { return t('Entrar con BionicEye'); }

    public function enabled(): bool
    {
        return $this->baseUrl() !== '' && trim(setting('bionicadri_client_id')) !== '';
    }

    private function baseUrl(): string
    {
        $u = rtrim(trim(setting('bionicadri_url')), '/');
        return preg_match('#^https?://[^\s/]+#', $u) ? $u : '';
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** URL a la que se manda a la persona. Guarda state y code_verifier en la sesión. */
    public function authorizeUrl(string $redirectUri): string
    {
        if (!$this->enabled()) throw new UcError(t('El acceso con BionicEye no está disponible.'), [], 404);
        $verifier = self::b64url(random_bytes(48));
        $state = self::b64url(random_bytes(24));
        $_SESSION['oauth'][$this->id()] = ['state' => $state, 'verifier' => $verifier, 'redirect' => $redirectUri, 'at' => time()];
        return $this->baseUrl() . '/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => trim(setting('bionicadri_client_id')), 'redirect_uri' => $redirectUri,
            'scope' => '', 'state' => $state, 'code_challenge' => self::b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ]);
    }

    /** Vuelta de BionicEye: valida state y PKCE, cambia el código por un token, lee la persona y abre sesión. */
    public function callback(array $query, string $redirectUri): array
    {
        $saved = $_SESSION['oauth'][$this->id()] ?? null;
        unset($_SESSION['oauth'][$this->id()]);
        $state = (string) ($query['state'] ?? '');
        if (!$this->enabled() || !$saved || $state === '' || !hash_equals((string) $saved['state'], $state) || time() - (int) $saved['at'] > 600
            || ($saved['redirect'] ?? '') !== $redirectUri || empty($query['code']) || !is_string($query['code'])) {
            throw new UcError(t('Entrada con BionicEye no válida o caducada: vuelve a intentarlo.'), [], 400);
        }
        $tok = $this->request('POST', $this->baseUrl() . '/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => trim(setting('bionicadri_client_id')),
            'redirect_uri' => $redirectUri, 'code' => $query['code'], 'code_verifier' => $saved['verifier'],
        ], []);
        $access = (string) ($tok['access_token'] ?? '');
        if ($access === '') throw new UcError(t('BionicEye no devolvió acceso.'), [], 502);
        $me = $this->request('GET', $this->baseUrl() . '/api/user', [], ['Authorization: Bearer ' . $access, 'Accept: application/json']);
        $ext = (string) ($me['id'] ?? '');
        $email = strtolower(trim((string) ($me['email'] ?? '')));
        if ($ext === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UcError(t('BionicEye no devolvió los datos de la persona.'), [], 502);

        $u = row("SELECT * FROM users WHERE auth_source = 'bionicadri' AND external_id = ?", [$ext]);
        if (!$u) {
            if (user_by_email($email)) throw new UcError(t('Ya existe una cuenta local con el correo {c}: pide al administrador que la enlace.', ['c' => $email]), [], 409);
            $u = user_by_id(user_create($email, (string) ($me['name'] ?? $email), 'cliente', null, 'bionicadri', $ext));
        }
        if (!$u['active']) throw new UcError(t('Tu cuenta está desactivada.'), [], 403);
        session_login($u);
        audit('user.login', $email . ' (BionicEye)');
        return user_by_id((int) $u['id']);
    }

    private function request(string $method, string $url, array $data, array $headers): array
    {
        if ($this->http) return (array) ($this->http)($method, $url, $data, $headers);
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'timeout' => 10, 'ignore_errors' => true,
            'header' => implode("\r\n", array_merge($headers, $method === 'POST' ? ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'] : [])),
            'content' => $method === 'POST' ? http_build_query($data) : '',
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $j = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($j)) throw new UcError(t('No se pudo hablar con BionicEye.'), [], 502);
        return $j;
    }
}

/** @return array<string, AuthProvider> */
function auth_providers(): array
{
    static $all = null;
    return $all ??= ['local' => new LocalAuthProvider(), 'bionicadri' => new BionicAdriAuthProvider()];
}

function auth_provider(string $id): AuthProvider
{
    return auth_providers()[$id] ?? throw new UcError(t('Proveedor de acceso desconocido.'), [], 404);
}

/** @return list<AuthProvider> */
function auth_providers_enabled(): array
{
    return array_values(array_filter(auth_providers(), fn(AuthProvider $p) => $p->enabled()));
}
