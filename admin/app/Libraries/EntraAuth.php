<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use Config\Admin;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Sign-in with Entra ID (OpenID Connect, authorization code flow with PKCE).
 * The ID token is verified against the tenant's signing keys (JWKS); administrators need the app role.
 */
class EntraAuth
{
    public function __construct(private readonly Admin $config, private readonly CURLRequest $http)
    {
    }

    public function configured(): bool
    {
        return $this->config->entraTenantId !== '' && $this->config->entraClientId !== '' && $this->config->entraClientSecret !== '';
    }

    private function base(): string
    {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->config->entraTenantId);
    }

    /**
     * @return array{url: string, state: string, nonce: string, verifier: string}
     */
    public function start(string $redirectUri): array
    {
        $state    = bin2hex(random_bytes(16));
        $nonce    = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $url      = $this->base() . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id'             => $this->config->entraClientId,
            'response_type'         => 'code',
            'redirect_uri'          => $redirectUri,
            'response_mode'         => 'query',
            'scope'                 => 'openid profile email',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier];
    }

    /**
     * Exchanges the code and returns the verified ID token claims.
     *
     * @return array<string, mixed>
     */
    public function finish(string $code, string $redirectUri, string $verifier, string $nonce): array
    {
        $resp = $this->http->post($this->base() . '/oauth2/v2.0/token', [
            'form_params' => [
                'client_id'     => $this->config->entraClientId,
                'client_secret' => $this->config->entraClientSecret,
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'code_verifier' => $verifier,
                'scope'         => 'openid profile email',
            ],
            'http_errors' => false,
            'timeout'     => 15,
        ]);
        $data = json_decode((string) $resp->getBody(), true) ?: [];
        if ($resp->getStatusCode() !== 200 || empty($data['id_token'])) {
            throw new RuntimeException('Token request failed: ' . ($data['error'] ?? 'HTTP ' . $resp->getStatusCode()));
        }

        return $this->verify((string) $data['id_token'], $nonce);
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $idToken, string $nonce): array
    {
        $keys = $this->http->get($this->base() . '/discovery/v2.0/keys', ['timeout' => 10, 'http_errors' => false]);
        $set  = json_decode((string) $keys->getBody(), true);
        if (! is_array($set) || empty($set['keys'])) {
            throw new RuntimeException('Signing keys not available');
        }
        JWT::$leeway = 60;
        $claims      = (array) JWT::decode($idToken, JWK::parseKeySet($set, 'RS256'));
        $issuer      = 'https://login.microsoftonline.com/' . $this->config->entraTenantId . '/v2.0';
        if (($claims['iss'] ?? '') !== $issuer || ($claims['aud'] ?? '') !== $this->config->entraClientId
            || ($claims['tid'] ?? '') !== $this->config->entraTenantId || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('ID token not valid for this application');
        }

        return $claims;
    }
}
