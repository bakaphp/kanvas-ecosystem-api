<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Baka\Http\SafeUrlFetcher;
use finfo;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\Traits\OpensHermesSshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * SSH + `docker exec curl` against the container's OpenAI-compatible API server
 * (POST /v1/chat/completions on 127.0.0.1:8642). API_SERVER_KEY == the per-agent
 * HERMES_GATEWAY_TOKEN custom field (see DockerComposeBuilderService::getApiServerEnvVars()).
 * Endpoint is stateless — cross-turn continuity is Hermes's own persistent auto-memory.
 */
class ChatWithAgentAction
{
    use OpensHermesSshClient;

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
     * Bearer key is the per-agent gateway token. Agent custom field first, deployment field
     * as fallback for older rows. No config-file fallback — the token is an env var
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

        // phpseclib4's exec channel caps a single command around ~200 KB, so a 1+ MB payload
        // (the base64-inlined image case) gets truncated mid-write. Stage via SFTP, `docker cp`
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
     * Hermes' vision pipeline expects image bytes inlined as a base64 `data:` URI — handing it
     * a remote http(s) URL makes the API silently never see the image.
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

    protected function fetchImageBinary(string $url): string
    {
        try {
            return SafeUrlFetcher::fetch($url);
        } catch (Throwable $e) {
            throw new ValidationException('Could not fetch image for Hermes vision: ' . $url);
        }
    }

    private function parseResponse(string $response): string
    {
        // SSH transport can return bytes truncated mid-multi-byte; coerce to valid UTF-8 so
        // nothing built from it poisons Laravel's JSON response with malformed-UTF-8 errors.
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
