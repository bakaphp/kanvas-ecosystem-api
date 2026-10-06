<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Services;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class RoadsideProviderClient
{
    protected string $baseUrl;
    protected string $token;
    protected GuzzleClient $client;

    public function __construct(protected AppInterface $app)
    {
        $this->baseUrl = (string) ($this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_BASE_URL->value) ?? '');
        $this->token = (string) ($this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_API_TOKEN->value) ?? '');

        if ($this->baseUrl === '' || $this->token === '') {
            throw new ValidationException(
                'Movipass roadside assistance provider configuration is missing. Run the Movipass connector setup first.',
            );
        }

        $authHeader = (string) ($this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_AUTH_HEADER->value)
            ?? ConfigurationEnum::ROADSIDE_PROVIDER_DEFAULT_AUTH_HEADER->value);
        $authScheme = (string) ($this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_AUTH_SCHEME->value)
            ?? ConfigurationEnum::ROADSIDE_PROVIDER_DEFAULT_AUTH_SCHEME->value);

        $this->client = new GuzzleClient([
            'base_uri' => rtrim($this->baseUrl, '/'),
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                $authHeader => trim($authScheme . ' ' . $this->token),
            ],
        ]);
    }

    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, $query === [] ? [] : ['query' => $query]);
    }

    public function post(string $endpoint, array $body): array
    {
        return $this->request('POST', $endpoint, ['json' => $body]);
    }

    public function put(string $endpoint, array $body): array
    {
        return $this->request('PUT', $endpoint, ['json' => $body]);
    }

    protected function request(string $method, string $endpoint, array $options): array
    {
        try {
            $response = $this->client->request($method, $endpoint, $options);
        } catch (RequestException $exception) {
            // Their 4xx bodies carry the actual rejection reason (unknown cause, provider out of
            // zone, duplicate case). Surfacing only "HTTP 400" would send an operator digging
            // through logs for something the response already said.
            $response = $exception->getResponse();

            throw new ValidationException(sprintf(
                'Roadside assistance provider %s %s failed with %s: %s',
                $method,
                $endpoint,
                $response?->getStatusCode() ?? 'no response',
                $response === null ? $exception->getMessage() : $response->getBody()->getContents(),
            ));
        } catch (GuzzleException $exception) {
            throw new ValidationException(sprintf(
                'Roadside assistance provider %s %s failed: %s',
                $method,
                $endpoint,
                $exception->getMessage(),
            ));
        }

        $body = $response->getBody()->getContents();

        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new ValidationException(sprintf(
                'Roadside assistance provider %s %s returned a non-JSON body: %s',
                $method,
                $endpoint,
                mb_substr($body, 0, 500),
            ));
        }

        return $decoded;
    }
}
