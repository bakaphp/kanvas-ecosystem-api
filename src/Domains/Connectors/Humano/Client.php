<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Humano\Enums\ConfigurationEnum;
use Kanvas\Connectors\Humano\Enums\EnvironmentEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Humano's intermediary gateway authenticates on three static headers — an Azure
 * APIM subscription key plus the user key and mediator code they issue per
 * intermediary. There is no token exchange, so unlike the Universal client there is
 * nothing to cache and no Redis dependency.
 *
 * Credentials are company-scoped: they are the aliado's, and quoting reads them off
 * the platform company rather than whoever is acting. Environment and base URL are
 * resolved per instance — never static, so Octane cannot leak one tenant's gateway
 * into another's request.
 */
class Client
{
    protected GuzzleClient $client;
    protected string $apiBaseUrl;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $environment = EnvironmentEnum::tryFrom(
            (string) $company->get(ConfigurationEnum::ENVIRONMENT->value)
        ) ?? EnvironmentEnum::DEV;

        $subscriptionKey = (string) $company->get(ConfigurationEnum::SUBSCRIPTION_KEY->value);
        $userKey = (string) $company->get(ConfigurationEnum::USER_KEY->value);
        $mediatorCode = (string) $company->get(ConfigurationEnum::MEDIATOR_CODE->value);

        if ($subscriptionKey === '' || $userKey === '' || $mediatorCode === '') {
            // Named because quoting reads credentials off the platform company, not
            // the one the caller is acting as.
            throw new ValidationException(
                'Humano credentials are not configured for company ' . $company->getId()
            );
        }

        $this->apiBaseUrl = $environment->apiBaseUrl();

        $this->client = new GuzzleClient([
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'Ocp-Apim-Subscription-Key' => $subscriptionKey,
                'x-user-key' => $userKey,
                'x-codigo-mediador' => $mediatorCode,
            ],
        ]);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    protected function request(string $method, string $path, array $options = []): array
    {
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
     * Their errors are `{statusCode, message}` with a Spanish message and no field
     * key — a bad `uso` and a bad `fechaDesde` are indistinguishable from the
     * response. Surface the message verbatim; validating fields before the request
     * leaves is QuoteRequest's job precisely because this cannot.
     *
     * Note their own sample shows `statusCode: 401` inside an HTTP 400 body, so
     * trust the HTTP status, not that field.
     */
    protected function toValidationException(RequestException $e): ValidationException
    {
        $response = $e->getResponse();
        $body = $response !== null ? (string) $response->getBody() : '';
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $detail = (string) ($decoded['message'] ?? $decoded['Message'] ?? $decoded['title'] ?? '');

            if ($detail !== '') {
                return new ValidationException('Humano request failed: ' . $detail);
            }
        }

        return new ValidationException('Humano request failed: ' . $e->getMessage());
    }
}
