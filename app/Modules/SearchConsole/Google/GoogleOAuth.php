<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Google;

use SensitiveParameter;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;

/**
 * Google OAuth 2.0 for web server apps, with PKCE (INTEGRATIONS.md -> Google Search Console).
 * Requests read-only Search Console access plus the account e-mail.
 */
final readonly class GoogleOAuth
{
    public const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    public const string USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    public const array SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/webmasters.readonly'];
    private const array HOSTS = ['oauth2.googleapis.com', 'openidconnect.googleapis.com'];

    public function __construct(private HttpClient $http) {}

    public function authorizationUrl(OAuthClientCredentials $client, string $redirectUri, string $state, string $codeVerifier, ?string $loginHint = null): string
    {
        $params = [
            'client_id' => $client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            // Always ask for consent so Google returns a refresh token on reconnect as well.
            'prompt' => 'consent select_account',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ];
        if ($loginHint !== null) {
            $params['login_hint'] = $loginHint;
        }

        return self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function newCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function exchangeCode(OAuthClientCredentials $client, string $code, string $redirectUri, string $codeVerifier): GoogleTokenSet
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $client->clientId,
            'client_secret' => $client->clientSecret,
            'code_verifier' => $codeVerifier,
        ]);
    }

    public function refresh(OAuthClientCredentials $client, #[SensitiveParameter] string $refreshToken): GoogleTokenSet
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client->clientId,
            'client_secret' => $client->clientSecret,
        ]);
    }

    /**
     * Revokes a token at Google. Failures are ignored by callers: local
     * tokens are deleted regardless.
     */
    public function revoke(#[SensitiveParameter] string $token): bool
    {
        try {
            $response = $this->http->send('POST', self::REVOKE_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['token' => $token]), self::HOSTS);

            return $response->isSuccess();
        } catch (HttpClientException) {
            return false;
        }
    }

    public function accountEmail(#[SensitiveParameter] string $accessToken): string
    {
        $response = $this->call('GET', self::USERINFO_URL, ['Authorization' => 'Bearer ' . $accessToken], null);
        $email = $response->json()['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw new IntegrationException('Google did not return the account e-mail address.', false);
        }

        return strtolower($email);
    }

    /**
     * @param array<string, string> $form
     */
    private function tokenRequest(array $form): GoogleTokenSet
    {
        $response = $this->call('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query($form));
        $data = $response->json() ?? [];
        $access = $data['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw new IntegrationException('Google returned no access token.', false);
        }
        $refresh = $data['refresh_token'] ?? null;

        return new GoogleTokenSet(
            $access,
            is_string($refresh) && $refresh !== '' ? $refresh : null,
            is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 3600,
            is_string($data['scope'] ?? null) ? $data['scope'] : '',
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        try {
            $response = $this->http->send($method, $url, $headers, $body, self::HOSTS);
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not reach Google: ' . $e->getMessage(), $e->retryable);
        }

        return GoogleErrors::check($response);
    }
}
