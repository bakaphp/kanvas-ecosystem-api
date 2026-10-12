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

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

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
