<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jira;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Http\SafeUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Jira\Enums\ConfigurationEnum;
use Kanvas\Connectors\Jira\Exceptions\JiraException;
use Kanvas\Exceptions\ValidationException;

/**
 * Thin REST client for Jira Cloud's `/rest/api/3` (https://developer.atlassian.com/cloud/jira/platform/rest/v3/).
 *
 * Jira Cloud authenticates with HTTP Basic auth using the account email + an API token
 * (https://id.atlassian.com/manage-profile/security/api-tokens) — not OAuth, so there is no token
 * refresh to manage.
 */
class Client
{
    protected const string API_PATH = '/rest/api/3/';

    protected string $instanceUrl;
    protected string $email;
    protected string $apiToken;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
        $this->instanceUrl = rtrim((string) ($company->get(ConfigurationEnum::INSTANCE_URL->value)
            ?? $app->get(ConfigurationEnum::INSTANCE_URL->value) ?? ''), '/');
        $this->email = (string) ($company->get(ConfigurationEnum::EMAIL->value)
            ?? $app->get(ConfigurationEnum::EMAIL->value) ?? '');
        $this->apiToken = (string) ($company->get(ConfigurationEnum::API_TOKEN->value)
            ?? $app->get(ConfigurationEnum::API_TOKEN->value) ?? '');

        if ($this->instanceUrl === '' || $this->email === '' || $this->apiToken === '') {
            throw new ValidationException('Jira instance URL, email and API token are required.');
        }

        // Tenant-supplied at setup, so it counts as user input for SSRF purposes.
        SafeUrl::assertSafe($this->instanceUrl);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->handle($this->request()->get($this->url($endpoint), $query), $endpoint);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<array-key, mixed>
     */
    public function post(string $endpoint, array $payload = []): array
    {
        return $this->handle($this->request()->post($this->url($endpoint), $payload), $endpoint);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<array-key, mixed>
     */
    public function put(string $endpoint, array $payload = []): array
    {
        return $this->handle($this->request()->put($this->url($endpoint), $payload), $endpoint);
    }

    protected function request(): PendingRequest
    {
        return Http::withBasicAuth($this->email, $this->apiToken)
            ->acceptJson()
            ->timeout(30);
    }

    protected function url(string $endpoint): string
    {
        return $this->instanceUrl . self::API_PATH . ltrim($endpoint, '/');
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function handle(Response $response, string $endpoint): array
    {
        if (! $response->successful()) {
            $errors = $response->json('errorMessages') ?? $response->json('errors') ?? $response->body();

            throw new JiraException(
                'Jira request to ' . $endpoint . ' failed (HTTP ' . $response->status() . '): '
                    . (is_string($errors) ? $errors : (string) json_encode($errors)),
                $response->status()
            );
        }

        $body = $response->body();

        return $body === '' ? [] : (json_decode($body, true) ?? []);
    }

    /**
     * `/myself` is Jira's cheapest authenticated endpoint — confirms the email/token pair
     * authenticates before it is stored.
     */
    public static function validateCredentials(string $instanceUrl, string $email, string $apiToken): bool
    {
        $instanceUrl = rtrim($instanceUrl, '/');

        if ($instanceUrl === '' || $email === '' || $apiToken === '') {
            throw new ValidationException('Jira instance URL, email and API token are required.');
        }

        SafeUrl::assertSafe($instanceUrl);

        $response = Http::withBasicAuth($email, $apiToken)
            ->acceptJson()
            ->timeout(15)
            ->get($instanceUrl . self::API_PATH . 'myself');

        if ($response->status() === 401) {
            throw new ValidationException('Jira rejected the provided email/API token.');
        }

        if (! $response->successful()) {
            throw new ValidationException('Jira validation failed with HTTP ' . $response->status() . '.');
        }

        return true;
    }
}
