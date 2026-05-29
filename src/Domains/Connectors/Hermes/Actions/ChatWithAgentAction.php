<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use finfo;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

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
 * field — see DockerComposeBuilderService::getApiServerEnvVars()).
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

        // phpseclib3's exec channel caps a single command around ~200 KB, so a 1+ MB
        // payload (the base64-inlined image case) gets truncated mid-write — phpseclib
        // raises "Only N of M bytes were sent" and the request never reaches Hermes.
        // Stage the payload as a file via SFTP (which chunks properly), `docker cp` it
        // into the container, and have curl read it from disk with --data-binary.
        $hostTmp = '/tmp/hermes-chat-' . bin2hex(random_bytes(8)) . '.json';
        $containerTmp = '/tmp/hermes-chat-' . bin2hex(random_bytes(8)) . '.json';
        $containerArg = escapeshellarg($deployment->container_name);

        try {
            if (! $client->writeFile($hostTmp, $payload)) {
                throw new ValidationException('Failed to stage Hermes payload on remote host');
            }

            $response = $client->exec(
                'docker cp ' . escapeshellarg($hostTmp) . ' '
                . escapeshellarg($deployment->container_name . ':' . $containerTmp)
                . ' && docker exec ' . $containerArg
                . ' curl -sS --max-time 580 -w "\nHTTP_CODE:%{http_code}"'
                . ' http://127.0.0.1:8642/v1/chat/completions'
                . ' -H ' . escapeshellarg('Authorization: Bearer ' . $token)
                . ' -H ' . escapeshellarg('Content-Type: application/json')
                . ' --data-binary @' . escapeshellarg($containerTmp)
                . ' ; docker exec ' . $containerArg . ' rm -f ' . escapeshellarg($containerTmp),
                600,
            );
        } finally {
            // Best-effort cleanup of the host-side staging file — never mask the
            // real response or exception with a cleanup failure.
            try {
                $client->exec('rm -f ' . escapeshellarg($hostTmp), 5);
            } catch (Throwable) {
                // ignore
            }
            $client->disconnect();
        }

        return $this->parseResponse($response);
    }

    protected function buildContent(): string|array
    {
        if ($this->images === []) {
            return $this->message;
        }

        $content = [['type' => 'text', 'text' => $this->message]];

        foreach ($this->images as $imageUrl) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $this->toInlineImage($imageUrl)],
            ];
        }

        return $content;
    }

    /**
     * Hermes' vision pipeline expects the image bytes inlined as a base64 `data:` URI — its
     * docs state the image is sent as `{ "url": "data:image/...;base64,..." }`. Handing the
     * API a remote http(s) URL instead makes the Hermes server fetch the asset itself, which
     * it does not do reliably for API requests, so the agent silently never sees the image.
     * Inline the bytes here so the request is self-contained.
     */
    private function toInlineImage(string $imageUrl): string
    {
        if (str_starts_with($imageUrl, 'data:')) {
            return $imageUrl;
        }

        $binary = $this->fetchImageBinary($imageUrl);
        $detected = new finfo(FILEINFO_MIME_TYPE)->buffer($binary);
        $mimeType = is_string($detected) && $detected !== '' ? $detected : 'image/png';

        return 'data:' . $mimeType . ';base64,' . base64_encode($binary);
    }

    /**
     * Fetch the raw image bytes. Isolated so tests can supply canned bytes without network.
     */
    protected function fetchImageBinary(string $url): string
    {
        $binary = @file_get_contents($url);

        if ($binary === false) {
            throw new ValidationException('Could not fetch image for Hermes vision: ' . $url);
        }

        return $binary;
    }

    private function parseResponse(string $response): string
    {
        // The SSH transport can return bytes truncated mid-multi-byte (curl hitting
        // --max-time, mixed stderr, etc.) — coerce to valid UTF-8 so neither the parsed
        // content nor any exception message built from it can poison Laravel's JSON
        // response with `InvalidArgumentException: Malformed UTF-8 characters`.
        $response = (string) mb_convert_encoding($response, 'UTF-8', 'UTF-8');

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
