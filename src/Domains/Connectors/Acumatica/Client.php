<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Acumatica contract-based REST client — WRITE-BACK transport only
 * (create/update entities + invoke actions). Reads come from the SQL replica
 * (see SqlClient). Stateless factory: rebuilt per call so rotated service-account
 * credentials are always picked up (Octane rule — never cache in static state).
 */
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
            'timeout' => 60,
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
     * Invoke an Acumatica action (Confirm, Release, …) on an entity.
     *
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
}
