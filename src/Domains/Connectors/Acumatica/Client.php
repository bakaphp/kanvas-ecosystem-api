<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected CookieJar $cookieJar;

    /** @var array<string, mixed> */
    protected array $config;
    protected string $entityBase;

    public function __construct(protected AppInterface $app)
    {
        $config = $this->app->get(ConfigurationEnum::ACUMATICA_CONFIG->value);

        if (empty($config) || empty($config['baseUrl'])) {
            throw new ValidationException('Acumatica REST configuration is missing.');
        }

        $this->config = $config;
        $this->cookieJar = new CookieJar();
        $this->client = new GuzzleClient([
            'base_uri' => rtrim((string) $config['baseUrl'], '/') . '/',
            'cookies' => $this->cookieJar,
            // Action invocations (Release, Reverse, etc.) on this tenant intermittently run past 60s
            // before responding, even though they complete server-side — a short client timeout would
            // abort the connection while the caller loses visibility into the actual result.
            'timeout' => 180,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        $this->entityBase = sprintf(
            'entity/%s/%s',
            $config['endpointName'] ?? '',
            $config['endpointVersion'] ?? '23.200.001'
        );
    }

    public static function getInstance(AppInterface $app): self
    {
        return new self($app);
    }

    public function login(): void
    {
        $this->client->post('entity/auth/login', [
            'json' => [
                'name' => $this->config['username'],
                'password' => $this->config['password'],
                'company' => $this->config['acumaticaCompany'],
                'branch' => $this->config['branch'] ?? '',
            ],
        ]);
    }

    public function logout(): void
    {
        $this->client->post('entity/auth/logout');
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<int|string, mixed>
     */
    public function get(string $entity, array $query = []): array
    {
        $response = $this->client->get($this->entityBase . '/' . $entity, ['query' => $query]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<int|string, mixed>
     */
    public function put(string $entity, array $body): array
    {
        $response = $this->client->put($this->entityBase . '/' . $entity, ['json' => $body]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * @param array<string, mixed> $body
     */
    public function invokeAction(string $entity, string $action, array $body): int
    {
        $response = $this->client->post(
            $this->entityBase . '/' . $entity . '/' . $action,
            ['json' => $body]
        );

        return $response->getStatusCode();
    }

    public function putFile(
        string $path,
        string $contents,
        string $contentType = 'application/octet-stream'
    ): int {
        $response = $this->client->put(ltrim($path, '/'), [
            'body' => $contents,
            'headers' => ['Content-Type' => $contentType],
        ]);

        return $response->getStatusCode();
    }
}
