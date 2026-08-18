<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Managed Agents HTTP client.
 *
 * Hand-rolled rather than the official PHP SDK so the Octane "never cache an SDK in a static" rule is
 * trivially satisfiable, and so we don't take a hard dependency on a beta surface whose PHP bindings
 * lag the API. **Build it fresh per use** — a cached instance serves the previous tenant's key.
 */
class Client
{
    public const string DEFAULT_BASE_URL = 'https://api.anthropic.com';
    public const string API_VERSION = '2023-06-01';
    public const string BETA_MANAGED_AGENTS = 'managed-agents-2026-04-01';

    /**
     * Session-scoped file listing is a Files endpoint taking a Managed Agents parameter, so it needs
     * BOTH betas — with only one the API rejects `scope_id` as unknown.
     */
    public const string BETA_FILES = 'files-api-2025-04-14';

    protected string $baseUrl;
    protected string $apiKey;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        ?GuzzleClient $client = null,
    ) {
        $this->baseUrl = self::resolveBaseUrl($app);
        $this->apiKey = self::resolveApiKey($app, $company);

        if ($this->apiKey === '') {
            throw new ValidationException(
                'Claude Managed Agents is not configured — set the API key on the company or the app.'
            );
        }

        $this->client = $client ?? self::buildGuzzle($this->baseUrl, $this->apiKey);
    }

    /**
     * Blank counts as absent, not as a value: clearing a key in the settings UI writes an empty
     * string rather than deleting the row, so a `??` chain would stop there instead of falling back.
     */
    public static function resolveApiKey(AppInterface $app, CompanyInterface $company): string
    {
        $companyKey = trim((string) ($company->get(ConfigurationEnum::API_KEY->value) ?? ''));

        return $companyKey !== ''
            ? $companyKey
            : trim((string) ($app->get(ConfigurationEnum::API_KEY->value) ?? ''));
    }

    public static function resolveBaseUrl(AppInterface $app): string
    {
        $baseUrl = trim((string) ($app->get(ConfigurationEnum::BASE_URL->value) ?? ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : self::DEFAULT_BASE_URL;
    }

    /**
     * Probe a key before it is stored, so a bad one fails at integration setup rather than mid-chat.
     * Static because setup validates config that hasn't been written yet.
     */
    public static function validateCredentials(string $apiKey, string $baseUrl, ?GuzzleClient $client = null): bool
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new ValidationException('A Claude API key is required.');
        }

        $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : self::DEFAULT_BASE_URL;

        self::send($client ?? self::buildGuzzle($baseUrl, $apiKey), 'GET', '/v1/agents', ['query' => ['limit' => 1]]);

        return true;
    }

    /**
     * Cheapest authenticated call the API offers.
     *
     * @return array<string, mixed>
     */
    public function listAgents(int $limit = 1): array
    {
        return $this->get('/v1/agents', ['limit' => $limit]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createEnvironment(array $payload): array
    {
        return $this->post('/v1/environments', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function listEnvironments(int $limit = 100): array
    {
        return $this->get('/v1/environments', ['limit' => $limit]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createAgent(array $payload): array
    {
        return $this->post('/v1/agents', $payload);
    }

    /**
     * Update is a POST, and each one mints a new immutable version. `version` is omitted from the
     * payload deliberately: Kanvas is the only writer of these objects, so the optimistic-lock 409
     * could only ever fire against our own retry.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateAgent(string $agentId, array $payload): array
    {
        return $this->post('/v1/agents/' . $agentId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createVault(array $payload): array
    {
        return $this->post('/v1/vaults', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function listVaultCredentials(string $vaultId, int $limit = 100): array
    {
        return $this->get('/v1/vaults/' . $vaultId . '/credentials', ['limit' => $limit]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createVaultCredential(string $vaultId, array $payload): array
    {
        return $this->post('/v1/vaults/' . $vaultId . '/credentials', $payload);
    }

    /**
     * Only the secret value and display name are mutable — `mcp_server_url` is locked at creation,
     * which is why rotation updates the existing credential instead of replacing it.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateVaultCredential(string $vaultId, string $credentialId, array $payload): array
    {
        return $this->post('/v1/vaults/' . $vaultId . '/credentials/' . $credentialId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSession(array $payload): array
    {
        return $this->post('/v1/sessions', $payload);
    }

    /**
     * Stops new events being accepted while keeping the history readable. Anthropic never expires a
     * session on its own, so a session we have stopped using stays alive — with its sandbox — until
     * we say otherwise.
     *
     * @return array<string, mixed>
     */
    public function archiveSession(string $sessionId): array
    {
        return $this->post('/v1/sessions/' . $sessionId . '/archive');
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function sendEvents(string $sessionId, array $events): array
    {
        return $this->post('/v1/sessions/' . $sessionId . '/events', ['events' => $events]);
    }

    /**
     * Paginated history, deliberately not the `/events/stream` SSE endpoint: a long-lived stream is
     * the wrong shape under Octane, and PHP HTTP timeouts are per-chunk, so a trickling connection
     * blocks a worker indefinitely. The drain loop owns its own wall clock instead.
     *
     * @return array<string, mixed>
     */
    public function listSessionEvents(string $sessionId, ?string $page = null, int $limit = 1000): array
    {
        $query = ['limit' => $limit];

        if ($page !== null && $page !== '') {
            $query['page'] = $page;
        }

        return $this->get('/v1/sessions/' . $sessionId . '/events', $query);
    }

    /**
     * Files the agent wrote to `/mnt/session/outputs/` during a session — the only way an artifact
     * leaves the sandbox.
     *
     * There is a brief indexing lag (~1–3s) between the session going idle and files appearing
     * here, so a caller that lists immediately on terminal may legitimately see an empty page.
     *
     * @return array<string, mixed>
     */
    public function listSessionFiles(string $sessionId, int $limit = 100): array
    {
        return self::send(
            $this->client,
            'GET',
            '/v1/files',
            ['query' => ['scope_id' => $sessionId, 'limit' => $limit]] + self::filesBeta(),
        );
    }

    /**
     * Raw bytes, not JSON — this is the one endpoint whose body must not be decoded.
     */
    public function downloadFile(string $fileId): string
    {
        $response = self::request($this->client, 'GET', '/v1/files/' . $fileId . '/content', self::filesBeta());

        return (string) $response->getBody();
    }

    /**
     * @return array{headers: array<string, string>}
     */
    protected static function filesBeta(): array
    {
        return ['headers' => ['anthropic-beta' => self::BETA_MANAGED_AGENTS . ',' . self::BETA_FILES]];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return self::send($this->client, 'GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return self::send($this->client, 'POST', $path, ['json' => $payload]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected static function send(GuzzleClient $client, string $method, string $path, array $options = []): array
    {
        $response = self::request($client, $method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new ClaudeAgentApiException(
                'Claude Managed Agents returned a non-JSON body.',
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function request(
        GuzzleClient $client,
        string $method,
        string $path,
        array $options = [],
    ): ResponseInterface {
        try {
            return $client->request($method, $path, $options);
        } catch (ClientException $e) {
            throw new ClaudeAgentApiException(self::describeError($e), $e->getResponse()->getStatusCode());
        } catch (GuzzleException $e) {
            // Never reached the API, so there is no status to report — 0 marks that.
            throw new ClaudeAgentApiException('Claude Managed Agents request failed: ' . $e->getMessage(), 0);
        }
    }

    protected static function buildGuzzle(string $baseUrl, string $apiKey): GuzzleClient
    {
        return new GuzzleClient([
            'base_uri' => $baseUrl,
            'timeout' => 30,
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'anthropic-beta' => self::BETA_MANAGED_AGENTS,
                'content-type' => 'application/json',
            ],
        ]);
    }

    /**
     * Surface the API's own `error.message` so a bad key reads as itself rather than a bare status.
     */
    protected static function describeError(ClientException $e): string
    {
        $status = $e->getResponse()->getStatusCode();
        $decoded = json_decode((string) $e->getResponse()->getBody(), true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return is_string($message) && $message !== ''
            ? "Claude Managed Agents error ({$status}): {$message}"
            : "Claude Managed Agents request failed with status {$status}.";
    }
}
