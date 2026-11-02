<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use Config\Admin;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Sign-in with Entra ID (OpenID Connect, authorization code flow with PKCE).
 * The ID token is verified against the tenant's signing keys (JWKS); access comes from the
 * membership in the configured groups, checked at sign-in via Microsoft Graph (POST /me/checkMemberGroups,
 * transitive, needs only the delegated permission User.Read).
 */
class EntraAuth
{
    private const SCOPE = 'openid profile email User.Read';

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
            'scope'                 => self::SCOPE,
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier];
    }

    /**
     * Exchanges the code; returns the verified ID token claims and the Graph access token.
     *
     * @return array{claims: array<string, mixed>, access_token: string}
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
                'scope'         => self::SCOPE,
            ],
            'http_errors' => false,
            'timeout'     => 15,
        ]);
        $data = json_decode((string) $resp->getBody(), true) ?: [];
        if ($resp->getStatusCode() !== 200 || empty($data['id_token'])) {
            throw new RuntimeException('Token request failed: ' . ($data['error'] ?? 'HTTP ' . $resp->getStatusCode()));
        }

        return ['claims' => $this->verify((string) $data['id_token'], $nonce), 'access_token' => (string) ($data['access_token'] ?? '')];
    }

    /**
     * Returns those of $groupIds the signed-in user is a member of (transitive). Max. 20 ids per Graph call.
     *
     * @param list<string> $groupIds
     *
     * @return list<string>
     */
    public function memberGroups(string $accessToken, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        if ($accessToken === '') {
            throw new RuntimeException('No access token for Microsoft Graph');
        }
        $found = [];
        foreach (array_chunk(array_values(array_unique($groupIds)), 20) as $chunk) {
            $resp = $this->http->post('https://graph.microsoft.com/v1.0/me/checkMemberGroups', [
                'headers'     => ['Authorization' => 'Bearer ' . $accessToken, 'Content-Type' => 'application/json'],
                'body'        => json_encode(['groupIds' => $chunk]),
                'http_errors' => false,
                'timeout'     => 15,
            ]);
            $data = json_decode((string) $resp->getBody(), true) ?: [];
            if ($resp->getStatusCode() !== 200 || ! isset($data['value']) || ! is_array($data['value'])) {
                throw new RuntimeException('Group check failed: ' . ($data['error']['code'] ?? 'HTTP ' . $resp->getStatusCode()));
            }
            array_push($found, ...array_map('strval', $data['value']));
        }

        return $found;
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
