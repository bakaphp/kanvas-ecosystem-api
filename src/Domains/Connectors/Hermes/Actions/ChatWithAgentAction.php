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
 * SSH + `docker exec curl` against the container's loopback /v1/responses on 127.0.0.1:8642
 * (same transport shape as OpenClaw). The bearer key is API_SERVER_KEY == the
 * HERMES_GATEWAY_TOKEN custom field (see DockerComposeBuilderService::getApiServerEnvVars()).
 * Cross-turn continuity comes from the `conversation` id we attach — the Kanvas Session.uuid,
 * shared with the chat UI, the Social layer, and the on-disk transcript. When no session is
 * supplied the field is omitted and Hermes falls back to its own persistent auto-memory.
 */
class ChatWithAgentAction
{
    /**
     * @param list<string> $images URLs to forward as multimodal image content.
     */
    public function __construct(
        protected Agent $agent,
        protected string $message,
        protected ?string $sessionKey = null,
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
        $payload = $this->buildPayload();

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
                . ' http://127.0.0.1:8642/v1/responses'
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

    /**
     * The container IS the agent (one Hermes instance per agent), so `model` is the fixed
     * `hermes-agent` literal the API server expects.
     */
    protected function buildPayload(): string
    {
        $payload = [
            'model' => 'hermes-agent',
            'input' => $this->buildInput(),
            'store' => true,
        ];

        if ($this->sessionKey !== null && $this->sessionKey !== '') {
            $payload['conversation'] = $this->sessionKey;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ValidationException('Failed to encode Hermes request payload');
        }

        return $json;
    }

    protected function buildInput(): string|array
    {
        if ($this->images === []) {
            return $this->message;
        }

        $content = [['type' => 'input_text', 'text' => $this->message]];

        foreach ($this->images as $imageUrl) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $this->toInlineImage($imageUrl),
            ];
        }

        return [['type' => 'message', 'role' => 'user', 'content' => $content]];
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

        $content = $this->extractContent($decoded);

        if ($content === null || $content === '') {
            throw new ValidationException('Hermes response had no message content: ' . $body);
        }

        return trim($content);
    }

    /**
     * The Responses API answers with `output_text` / `output[].content[].text`; older or
     * alternate gateway builds may still reply in Chat Completions shape. Accept both so a
     * gateway version bump can't silently break chat.
     *
     * @param array<array-key, mixed> $decoded
     */
    private function extractContent(array $decoded): ?string
    {
        if (isset($decoded['output_text']) && is_string($decoded['output_text'])) {
            return $decoded['output_text'];
        }

        $output = $decoded['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $item) {
                $contentList = is_array($item) ? ($item['content'] ?? null) : null;
                if (! is_array($contentList)) {
                    continue;
                }

                foreach ($contentList as $chunk) {
                    $text = is_array($chunk) ? ($chunk['text'] ?? null) : null;
                    if (is_string($text) && $text !== '') {
                        return $text;
                    }
                }
            }
        }

        $choices = $decoded['choices'] ?? null;
        $first = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($first) ? ($first['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        return is_string($content) ? $content : null;
    }
}
