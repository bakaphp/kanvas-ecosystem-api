<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Chat with a deployed Hermes agent via SSH + `docker exec curl` against the
 * container's OpenAI-compatible API server (POST /v1/chat/completions on
 * 127.0.0.1:8642).
 *
 * Same transport as OpenClaw's ChatWithAgentAction (SSH → docker exec → loopback
 * HTTP) but it speaks the Chat Completions API rather than the Responses API,
 * because that is what the Hermes API server exposes. The API server binds to
 * loopback inside the container and authenticates with a bearer key that equals
 * the per-agent gateway token (API_SERVER_KEY == the HERMES_GATEWAY_TOKEN custom
 * field — see DockerComposeBuilder::getApiServerEnvVars()).
 *
 * The endpoint is stateless — each call sends only the current user message.
 * Continuity across turns comes from Hermes's own persistent auto-memory; true
 * per-session message threading (Hermes /v1/responses + `conversation`) is a
 * deliberate follow-up, not wired here.
 */
class ChatWithAgentAction
{
    /**
     * @param list<string> $images URLs to forward as multimodal image content.
     */
    public function __construct(
        protected Agent $agent,
        protected string $message,
        protected array $images = [],
    ) {
    }

    public function execute(): string
    {
        $deployment = $this->agent->activeDeployment;

        if (! $deployment instanceof AgentDeployment || ! $deployment->isRunning()) {
            throw new ValidationException('Agent does not have an active Hermes deployment');
        }

        return $this->sendRequest($deployment, $this->resolveGatewayToken($deployment));
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
     * The API server bearer key is the per-agent gateway token. It is set on the
     * agent custom field at launch (and backfilled by BackfillAgentGatewayTokenAction);
     * the deployment custom field is checked as a fallback for older rows. Unlike
     * OpenClaw there is no config-file fallback — the token is an env var
     * (API_SERVER_KEY), never written into config.yaml.
     */
    private function resolveGatewayToken(AgentDeployment $deployment): string
    {
        $token = (string) ($this->agent->get(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value) ?? '');

        if ($token === '') {
            $token = (string) ($deployment->get(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value) ?? '');
        }

        if ($token === '') {
            throw new ValidationException(
                'Hermes gateway token not set for agent ' . (string) $this->agent->getId()
                . ' — re-launch the deployment to provision API_SERVER_KEY.'
            );
        }

        return $token;
    }

    private function sendRequest(AgentDeployment $deployment, string $token): string
    {
        // The container IS the agent (one Hermes instance per agent), so the model
        // name is the fixed `hermes-agent` literal the API server expects.
        $payload = json_encode([
            'model' => 'hermes-agent',
            'messages' => [
                ['role' => 'user', 'content' => $this->buildContent()],
            ],
            'stream' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new ValidationException('Failed to encode Hermes request payload');
        }

        $client = $this->openSshClient($deployment->machine);

        try {
            $response = $client->exec(
                'docker exec ' . escapeshellarg($deployment->container_name)
                . ' curl -sS --max-time 580 -w "\nHTTP_CODE:%{http_code}"'
                . ' http://127.0.0.1:8642/v1/chat/completions'
                . ' -H ' . escapeshellarg('Authorization: Bearer ' . $token)
                . ' -H ' . escapeshellarg('Content-Type: application/json')
                . ' -d ' . escapeshellarg($payload),
                600,
            );
        } finally {
            $client->disconnect();
        }

        return $this->parseResponse($response);
    }

    private function buildContent(): string|array
    {
        if ($this->images === []) {
            return $this->message;
        }

        $content = [['type' => 'text', 'text' => $this->message]];

        foreach ($this->images as $imageUrl) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]];
        }

        return $content;
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

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ValidationException('Hermes API server returned HTTP ' . $statusCode . ': ' . $body);
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new ValidationException('Hermes returned non-JSON response: ' . $body);
        }

        $choices = $decoded['choices'] ?? null;
        $first = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($first) ? ($first['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (! is_string($content) || $content === '') {
            throw new ValidationException('Hermes response had no message content: ' . $body);
        }

        return trim($content);
    }
}
