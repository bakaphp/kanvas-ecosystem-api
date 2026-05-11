<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Exceptions\UnauthorizedGatewayException;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Chat with a deployed agent via SSH + `docker exec curl` against the
 * container's local gateway HTTP API (/v1/responses).
 *
 * We go through SSH + docker exec instead of exposing the gateway port,
 * hitting 127.0.0.1:18789 from inside the container over loopback. Session
 * isolation is enforced with the `x-openclaw-session-key` header — the CLI
 * flags `--session-id` / `--to` are documented-but-broken (upstream issues
 * #22085, #36401, both closed as not planned).
 *
 * Gateway token lives on the deployment as a custom field. If missing
 * (pre-existing deployments), we lazy-fetch it from the container's
 * openclaw.json on first chat and cache it for next time. On a 401 we
 * refresh once and retry, then give up.
 */
class ChatWithAgentAction
{
    public function __construct(
        protected Agent $agent,
        protected string $message,
        protected ?string $sessionKey = null,
    ) {
    }

    public function execute(): string
    {
        $deployment = $this->agent->activeDeployment;

        if (! $deployment instanceof AgentDeployment || ! $deployment->isRunning()) {
            throw new ValidationException('Agent does not have an active Docker deployment');
        }

        $token = $this->resolveGatewayToken($deployment);
        $fullSessionKey = $this->buildSessionKey();

        try {
            return $this->sendRequest($deployment, $token, $fullSessionKey);
        } catch (UnauthorizedGatewayException) {
            $token = $this->fetchGatewayToken($deployment);
            $deployment->set(CustomFieldEnum::OPENCLAW_GATEWAY_TOKEN->value, $token);

            return $this->sendRequest($deployment, $token, $fullSessionKey);
        }
    }

    /**
     * Factory for the SSH transport — overridable in tests to inject a fake
     * SshClient without touching the network.
     */
    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return SshClient::fromMachine($machine);
    }

    /**
     * Delegate to FetchGatewayTokenAction — overridable in tests.
     */
    protected function fetchGatewayToken(AgentDeployment $deployment): string
    {
        return new FetchGatewayTokenAction($deployment)->execute();
    }

    private function resolveGatewayToken(AgentDeployment $deployment): string
    {
        $cached = $deployment->get(CustomFieldEnum::OPENCLAW_GATEWAY_TOKEN->value);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->fetchGatewayToken($deployment);
        $deployment->set(CustomFieldEnum::OPENCLAW_GATEWAY_TOKEN->value, $token);

        return $token;
    }

    private function buildSessionKey(): string
    {
        $suffix = $this->sessionKey !== null && $this->sessionKey !== '' ? $this->sessionKey : 'main';

        return 'agent:' . $this->agent->slug . ':' . $suffix;
    }

    private function sendRequest(AgentDeployment $deployment, string $token, string $sessionKey): string
    {
        $payload = json_encode([
            'model' => 'openclaw/' . $this->agent->slug,
            'input' => $this->message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new ValidationException('Failed to encode OpenClaw request payload');
        }

        $client = $this->openSshClient($deployment->machine);

        try {
            $response = $client->exec(
                'docker exec ' . escapeshellarg($deployment->container_name)
                . ' curl -sS --max-time 580 -w "\nHTTP_CODE:%{http_code}"'
                . ' http://127.0.0.1:18789/v1/responses'
                . ' -H ' . escapeshellarg('Authorization: Bearer ' . $token)
                . ' -H ' . escapeshellarg('Content-Type: application/json')
                . ' -H ' . escapeshellarg('x-openclaw-agent-id: ' . $this->agent->slug)
                . ' -H ' . escapeshellarg('x-openclaw-session-key: ' . $sessionKey)
                . ' -d ' . escapeshellarg($payload),
                600,
            );
        } finally {
            $client->disconnect();
        }

        return $this->parseResponse($response);
    }

    private function parseResponse(string $response): string
    {
        $lines = explode("\n", trim($response));
        $statusLine = array_pop($lines) ?: '';
        $body = implode("\n", $lines);

        $statusCode = 0;
        if (preg_match('/HTTP_CODE:(\d+)/', $statusLine, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }

        if ($statusCode === 401) {
            throw new UnauthorizedGatewayException();
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ValidationException('OpenClaw gateway returned HTTP ' . $statusCode . ': ' . $body);
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new ValidationException('OpenClaw returned non-JSON response: ' . $body);
        }

        $output = $decoded['output'] ?? null;

        if (! is_array($output) || $output === []) {
            throw new ValidationException('OpenClaw returned no output: ' . $body);
        }

        $first = $output[0] ?? null;
        $content = is_array($first) ? ($first['content'] ?? null) : null;
        $firstContent = is_array($content) ? ($content[0] ?? null) : null;
        $text = is_array($firstContent) ? ($firstContent['text'] ?? null) : null;

        if (! is_string($text) || $text === '') {
            throw new ValidationException('OpenClaw response had no text content: ' . $body);
        }

        return trim($text);
    }
}
