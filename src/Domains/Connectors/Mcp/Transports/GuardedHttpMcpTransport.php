<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Transports;

use Baka\Http\SafeUrl;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Exceptions\McpFetchException;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Connectors\Mcp\Support\McpErrorReason;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;
use NeuronAI\MCP\McpTransportInterface;
use Override;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Our own MCP transport, injected via `config['transport']` so NeuronAI's Guzzle path is never used.
 *
 * The vendor's StreamableHttpTransport hands a bare Guzzle client a URL with no SSRF guard, follows
 * redirects by default and reads an unbounded body — none of which is acceptable for a URL that can
 * come from tenant input on the BYO path.
 *
 * It also resolves the agent's credential HERE, at send time, rather than accepting one in config. That
 * is what keeps the token out of `McpConnector::__serialize()`, which stores its whole config verbatim
 * into whatever persists an interrupted workflow.
 *
 * Remote HTTP only, and `stdio` will never be added: NeuronAI's StdioTransport executes a configured
 * command with caller-supplied env, which is remote code execution on the API container. A stdio MCP
 * server belongs inside the per-tenant OpenClaw/Hermes containers, not here. A server advertising SSE is
 * served by this same class — `decode()` reads the `data:` frame off the single POST.
 */
class GuardedHttpMcpTransport implements McpTransportInterface
{
    private const int MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    private const int READ_CHUNK_BYTES = 8192;

    private ?Client $httpClient = null;

    private ?string $token = null;

    private bool $tokenResolved = false;

    private ?McpCredentialService $credentials = null;

    private bool $credentialsResolved = false;

    private ?string $sessionId = null;

    private ?ResponseInterface $lastResponse = null;

    public function __construct(
        private ?string $url,
        private int $agentsId,
        private int $integrationsId,
        private int $timeoutMs = 20000,
        private ?string $authQueryParam = null,
    ) {
    }

    /**
     * Only ids and scalars cross the wire. The Guzzle client holds handlers that cannot serialize, and
     * the token must never be written into a serialized payload — both are rebuilt lazily on the far
     * side. The query parameter is a field name, not a secret; the key it carries is added at send time.
     */
    public function __serialize(): array
    {
        return [
            'url' => $this->url,
            'agentsId' => $this->agentsId,
            'integrationsId' => $this->integrationsId,
            'timeoutMs' => $this->timeoutMs,
            'authQueryParam' => $this->authQueryParam,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->url = $data['url'];
        $this->agentsId = $data['agentsId'];
        $this->integrationsId = $data['integrationsId'];
        $this->timeoutMs = $data['timeoutMs'];
        $this->authQueryParam = $data['authQueryParam'] ?? null;
        $this->httpClient = null;
        $this->token = null;
        $this->tokenResolved = false;
        $this->credentials = null;
        $this->credentialsResolved = false;
        $this->sessionId = null;
        $this->lastResponse = null;
    }

    #[Override]
    public function connect(): void
    {
        // On every connect, not only when the URL was saved: DNS can later resolve somewhere private.
        SafeUrl::assertSafe($this->resolveUrl());
    }

    #[Override]
    public function send(array $data): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'User-Agent' => 'kanvas-mcp/1.0',
        ];

        $token = $this->resolveToken();

        // A vendor that reads its key from the query string gets it there and nowhere else — sending the
        // same secret in a header it never reads would only widen where it can leak.
        if ($token !== null && $this->authQueryParam === null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new McpFetchException('Failed to encode MCP request: ' . $e->getMessage(), 0, $e);
        }

        try {
            $response = $this->client()->send(new Request(
                'POST',
                $this->resolveUrl(),
                $headers,
                $body
            ));
        } catch (GuzzleException $e) {
            // Guzzle puts the whole URL in its message, and on this path the URL carries the key.
            throw new McpFetchException('MCP request failed: ' . $this->redact($e->getMessage()), $e->getCode(), $e);
        }

        if ($response->getStatusCode() >= 400) {
            $this->throwForErrorStatus($response);
        }

        if ($response->hasHeader('Mcp-Session-Id')) {
            $this->sessionId = $response->getHeader('Mcp-Session-Id')[0];
        }

        // Streaming means an unread body is an open socket, not just bytes — a caller that sends twice
        // without receiving would otherwise leak the first one until GC.
        $this->discardBody();
        $this->lastResponse = $response;
    }

    #[Override]
    public function receive(): array
    {
        if (! $this->lastResponse instanceof ResponseInterface) {
            throw new McpFetchException('No MCP response available — send() must be called first.');
        }

        $raw = $this->readCapped($this->lastResponse);
        $this->lastResponse = null;

        if (trim($raw) === '') {
            throw new McpFetchException('Empty MCP response body.');
        }

        $decoded = $this->decode($raw);

        if (! is_array($decoded)) {
            throw new McpFetchException('MCP response was not a JSON object.');
        }

        return $decoded;
    }

    #[Override]
    public function disconnect(): void
    {
        $this->sessionId = null;
        $this->discardBody();
        $this->lastResponse = null;
    }

    /**
     * A rejected credential and an unreachable server are different outcomes to the cache: one strips the
     * capability, the other serves the last good snapshot.
     *
     * @throws McpAuthException|McpFetchException always
     */
    private function throwForErrorStatus(ResponseInterface $response): never
    {
        $status = $response->getStatusCode();
        $body = $response->getBody()->read(4096);
        $reason = $this->redact(McpErrorReason::fromBody($body, $response->getHeaderLine('WWW-Authenticate')));

        // The request's own headers carry the token; only the response's are logged, minus cookies.
        Log::warning('MCP server returned an error status', [
            'status' => $status,
            'agents_id' => $this->agentsId,
            'integrations_id' => $this->integrationsId,
            'headers' => array_filter(
                $response->getHeaders(),
                fn (int|string $name): bool => strtolower((string) $name) !== 'set-cookie',
                ARRAY_FILTER_USE_KEY
            ),
            'body' => mb_substr($this->redact($body), 0, 1000),
        ]);

        $response->getBody()->close();

        throw $status === 401 || $status === 403
            ? new McpAuthException(trim('MCP server rejected the credential (HTTP ' . $status . '). ' . $reason))
            : new McpFetchException(trim('MCP server returned HTTP ' . $status . '. ' . $reason));
    }

    private function discardBody(): void
    {
        $this->lastResponse?->getBody()->close();
    }

    /**
     * A body arrives either as plain JSON or wrapped in SSE frames — servers advertising
     * `text/event-stream` still answer a single POST with one `data:` line.
     */
    private function decode(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = $this->extractSseData($raw);

            if ($payload === null) {
                throw new McpFetchException('MCP response was neither JSON nor a parseable SSE frame.');
            }

            try {
                return json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new McpFetchException('MCP SSE frame did not contain valid JSON: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    private function extractSseData(string $raw): ?string
    {
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'data: ')) {
                return substr($line, 6);
            }
        }

        return null;
    }

    /**
     * Read with a hard ceiling. It bounds the transfer itself only while client() keeps `stream => true`;
     * buffered, the body is already spooled in full before the first check and the cap bounds the parse.
     */
    private function readCapped(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        $content = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(self::READ_CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $content .= $chunk;

            if (strlen($content) > self::MAX_RESPONSE_BYTES) {
                $stream->close();

                throw new McpFetchException('MCP response exceeded the ' . self::MAX_RESPONSE_BYTES . ' byte cap.');
            }
        }

        return $content;
    }

    private function resolveToken(): ?string
    {
        if (! $this->tokenResolved) {
            $this->tokenResolved = true;

            try {
                $this->token = $this->credentials()?->token();
            } catch (Throwable) {
                $this->token = null;
            }
        }

        return $this->token;
    }

    /**
     * A server whose address each connection supplies is read from the agent's credential, like the
     * token — some providers put the secret in the URL itself, so it must not be serialized either.
     *
     * A vendor that wants its key as a query parameter has it appended here, at send time, so the admin
     * still pastes only the key and the stored address stays free of it.
     */
    private function resolveUrl(): string
    {
        $url = $this->url ?? $this->credentials()?->serverUrl();

        if ($url === null) {
            throw new McpFetchException('This MCP connection has no server URL — connect it again.');
        }

        if ($this->authQueryParam === null) {
            return $url;
        }

        $token = $this->resolveToken();

        if ($token === null) {
            return $url;
        }

        return $url
            . (str_contains($url, '?') ? '&' : '?')
            . rawurlencode($this->authQueryParam) . '=' . rawurlencode($token);
    }

    /** Keeps a key that travels in the URL out of exception messages, logs and the grant's last_error. */
    private function redact(string $message): string
    {
        $token = $this->authQueryParam === null ? null : $this->resolveToken();

        return $token === null || $token === ''
            ? $message
            : str_replace([$token, rawurlencode($token)], '***', $message);
    }

    private function credentials(): ?McpCredentialService
    {
        if (! $this->credentialsResolved) {
            $this->credentialsResolved = true;

            $agent = Agent::query()->where('id', $this->agentsId)->first();
            $integration = Integrations::query()->where('id', $this->integrationsId)->first();

            $this->credentials = $agent instanceof Agent && $integration instanceof Integrations
                ? new McpCredentialService($agent, $integration)
                : null;
        }

        return $this->credentials;
    }

    private function client(): Client
    {
        return $this->httpClient ??= new Client([
            'timeout' => max(1, (int) ceil($this->timeoutMs / 1000)),
            'connect_timeout' => 10,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            // Hands back the live socket so readCapped() can abort a hostile body mid-transfer instead
            // of capping one Guzzle already spooled to php://temp in full. Guzzle routes this to its
            // StreamHandler, which needs allow_url_fopen and ignores connect_timeout above — `timeout`
            // bounds the exchange there, and without the ini it degrades to the buffered curl path.
            'stream' => true,
        ]);
    }
}
