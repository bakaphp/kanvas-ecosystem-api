<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Redis;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\EnvironmentEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * OAuth2 client_credentials. The bearer token is cached in Redis keyed per
 * app+company+environment so credential/environment changes don't bleed across
 * tenants under Octane. Environment + URLs are per-instance — never static.
 */
class Client
{
    protected GuzzleClient $client;
    protected string $apiBaseUrl;
    protected string $idpTokenUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $scopes;
    protected string $redisKey;
    protected bool $verifySsl;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $environment = EnvironmentEnum::tryFrom(
            (string) $company->get(ConfigurationEnum::ENVIRONMENT->value)
        ) ?? EnvironmentEnum::QA;

        $this->clientId = (string) $company->get(ConfigurationEnum::CLIENT_ID->value);
        $this->clientSecret = (string) $company->get(ConfigurationEnum::CLIENT_SECRET->value);
        $this->scopes = (string) ($company->get(ConfigurationEnum::SCOPES->value) ?: ConfigurationEnum::defaultScopes());

        if (empty($this->clientId) || empty($this->clientSecret)) {
            // Named because quoting reads credentials off the platform company, not
            // the one the caller is acting as.
            throw new ValidationException(
                'Universal Seguros credentials are not configured for company ' . $company->getId()
            );
        }

        $this->apiBaseUrl = $environment->apiBaseUrl();
        $this->idpTokenUrl = $environment->idpTokenUrl();
        $this->redisKey = sprintf(
            'universalSegurosToken-%s-%s-%s',
            $app->getId(),
            $company->getId(),
            $environment->value
        );

        $this->verifySsl = self::resolveVerifySsl($company->get(ConfigurationEnum::VERIFY_SSL->value));

        $this->client = new GuzzleClient([
            'timeout' => 60,
            'verify' => $this->verifySsl,
        ]);
    }

    public function verifiesSsl(): bool
    {
        return $this->verifySsl;
    }

    /**
     * Escape hatch for Universal's QA host only. Since their 2026-07-23 reissue,
     * qa.universal.com.do chains to `GoDaddy TLS Root CA - R1`, a root Mozilla's
     * store still doesn't ship (checked against cacert.pem of 2026-08-13), so every
     * OpenSSL client fails with cURL 60 until they reissue it. Prod chains to
     * DigiCert Global Root G2 and verifies fine — leave the flag unset there.
     */
    protected static function resolveVerifySsl(mixed $flag): bool
    {
        if ($flag === null || $flag === '') {
            return true;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function auth(): string
    {
        if ($token = Redis::get($this->redisKey)) {
            return $token;
        }

        try {
            $response = $this->client->post($this->idpTokenUrl, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => $this->scopes,
                ],
            ]);
        } catch (RequestException $e) {
            throw $this->toValidationException($e, 'Universal Seguros authentication failed');
        }

        $decoded = json_decode($response->getBody()->getContents(), true);
        $data = is_array($decoded) ? $decoded : [];
        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            throw new ValidationException('Universal Seguros did not return an access token');
        }

        // Refresh a little before the real expiry to avoid mid-request token death.
        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 300);
        Redis::setex($this->redisKey, $ttl, $token);

        return $token;
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, ['json' => $body]);
    }

    public function uploadDocument(string $path, string $filePath): array
    {
        return $this->request('POST', $path, [
            'multipart' => [
                [
                    'name' => 'Archivo',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                ],
            ],
        ]);
    }

    protected function request(string $method, string $path, array $options = []): array
    {
        $headers = (array) ($options['headers'] ?? []);
        $headers['Authorization'] = 'Bearer ' . $this->auth();
        $options['headers'] = $headers;

        try {
            $response = $this->client->request($method, $this->apiBaseUrl . $path, $options);
        } catch (RequestException $e) {
            throw $this->toValidationException($e);
        }

        $body = $response->getBody()->getContents();
        $decoded = $body === '' ? [] : json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Universal returns RFC7231 problem+json on errors and, on validation failures,
     * a field-keyed `errors` map telling you the allowed values. Surface it verbatim
     * so callers see Universal's guidance instead of a generic HTTP error.
     *
     * Known QA quirk: an unrecognized `chasis` currently returns 500
     * "Ha ocurrido un error desconocido" instead of a clean 400 — treat a 500 on
     * cotizar as a likely chassis-not-in-registry until Universal fixes it.
     */
    protected function toValidationException(RequestException $e, string $prefix = 'Universal Seguros request failed'): ValidationException
    {
        $response = $e->getResponse();
        $body = $response !== null ? (string) $response->getBody() : '';
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            if (! empty($decoded['errors']) && is_array($decoded['errors'])) {
                $messages = [];
                foreach ($decoded['errors'] as $field => $fieldErrors) {
                    $values = array_map('strval', (array) $fieldErrors);
                    $messages[] = $field . ': ' . implode(' ', $values);
                }
                $detail = implode(' | ', $messages);
            } else {
                $detail = (string) ($decoded['detail'] ?? $decoded['title'] ?? '');
            }

            if ($detail !== '') {
                return new ValidationException($prefix . ': ' . $detail);
            }
        }

        return new ValidationException($prefix . ': ' . $e->getMessage());
    }
}
