<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\PiDev\Enums\ConfigurationEnum;
use Kanvas\Connectors\PiDev\Exceptions\PiDevApiException;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $apiToken;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        ?GuzzleClient $client = null,
    ) {
        $this->baseUrl = (string) $app->get(ConfigurationEnum::BASE_URL->value);
        $this->apiToken = (string) ($company->get(ConfigurationEnum::API_TOKEN->value)
            ?? $app->get(ConfigurationEnum::API_TOKEN->value));

        if ($this->baseUrl === '' || $this->apiToken === '') {
            throw new ValidationException('pi.dev configuration is missing');
        }

        $this->client = $client ?? new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Liveness probe. No auth required by the server. Returns {status, running, queued}.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->get('/health');
    }

    /**
     * Queue a coding job. Returns the 202 body (status "queued", carries the jobId). The clone
     * has NOT happened yet — poll the job for its real outcome.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function queueWork(array $payload): array
    {
        return $this->post('/agents/work', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getJob(string $jobId): array
    {
        return $this->get('/agents/work/' . $jobId);
    }

    /**
     * Signal cancellation. Returns 202 {status:"cancelling"}; a 409 (already terminal) is
     * surfaced as a ValidationException by the caller-facing action.
     *
     * @return array<string, mixed>
     */
    public function cancelJob(string $jobId): array
    {
        return $this->post('/agents/work/' . $jobId . '/cancel', []);
    }

    /**
     * Drain the SSE `/events` stream and return the frames emitted after `$afterId`. The stream
     * replays everything buffered on connect, then holds open — so we read against a hard wall-clock
     * deadline and close, capturing the replay without hanging on a live job. Best-effort: any
     * transport hiccup returns whatever was parsed (possibly empty), never throws to the caller —
     * progress is a nicety, not load-bearing (coarse status comes from getJob).
     *
     * @return array<int, array{id: int, event: string, data: mixed}>
     */
    public function fetchJobEvents(string $jobId, int $afterId = 0, int $maxSeconds = 4): array
    {
        $options = [
            'stream' => true,
            'read_timeout' => $maxSeconds,
            'connect_timeout' => $maxSeconds,
            'headers' => $afterId > 0 ? ['Last-Event-ID' => (string) $afterId] : [],
        ];

        $buffer = '';

        try {
            $body = $this->client->get('/agents/work/' . $jobId . '/events', $options)->getBody();
            $deadline = microtime(true) + (float) $maxSeconds;

            while (! $body->eof() && microtime(true) < $deadline) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                $buffer .= $chunk;
                if (str_contains($chunk, 'event: done')) {
                    break;
                }
            }

            $body->close();
        } catch (GuzzleException) {
            // Read timeout on a live stream (or transport blip) — parse whatever we captured.
        }

        return $this->parseSseFrames($buffer, $afterId);
    }

    /**
     * @return array<int, array{id: int, event: string, data: mixed}>
     */
    protected function parseSseFrames(string $raw, int $afterId): array
    {
        $frames = [];

        foreach (preg_split("/\r?\n\r?\n/", $raw) ?: [] as $block) {
            $block = trim($block);
            if ($block === '' || str_starts_with($block, ':')) {
                continue;
            }

            $id = null;
            $event = null;
            $dataLines = [];

            foreach (preg_split("/\r?\n/", $block) ?: [] as $line) {
                if (str_starts_with($line, 'id:')) {
                    $id = (int) trim(substr($line, 3));
                } elseif (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $dataLines[] = trim(substr($line, 5));
                }
            }

            if ($event === null || ($id !== null && $id <= $afterId)) {
                continue;
            }

            $dataRaw = implode("\n", $dataLines);
            $data = json_decode($dataRaw, true);

            $frames[] = [
                'id' => $id ?? 0,
                'event' => $event,
                'data' => $data === null && $dataRaw !== 'null' ? $dataRaw : $data,
            ];
        }

        return $frames;
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(string $endpoint): array
    {
        try {
            $response = $this->client->get($endpoint);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (ClientException $e) {
            throw $this->toValidationException($e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->post($endpoint, ['json' => $data]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (ClientException $e) {
            throw $this->toValidationException($e);
        }
    }

    protected function toValidationException(ClientException $e): PiDevApiException
    {
        $body = json_decode($e->getResponse()->getBody()->getContents(), true);
        $message = is_array($body) && isset($body['error'])
            ? (string) $body['error']
            : $e->getMessage();

        return new PiDevApiException($message, $e->getResponse()->getStatusCode());
    }

    /**
     * Confirm the endpoint is reachable and the token authenticates. `/health` needs no auth, so
     * we also probe a protected route: reading a non-existent job returns 404 when the token is
     * valid and 401 when it is not — a side-effect-free way to verify the bearer.
     */
    public static function validateCredentials(string $baseUrl, string $apiToken, ?GuzzleClient $client = null): bool
    {
        if ($baseUrl === '' || $apiToken === '') {
            throw new ValidationException('pi.dev base URL and API token are required');
        }

        $client ??= new GuzzleClient([
            'base_uri' => $baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type' => 'application/json',
            ],
        ]);

        try {
            $client->get('/health');
            $client->get('/agents/work/00000000-0000-0000-0000-000000000000');

            return true;
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();

            if ($status === 404) {
                return true;
            }

            if ($status === 401) {
                throw new ValidationException('pi.dev API token is invalid');
            }

            throw new ValidationException('pi.dev validation failed: ' . $e->getMessage(), $status);
        } catch (GuzzleException $e) {
            throw new ValidationException('Failed to connect to pi.dev: ' . $e->getMessage());
        }
    }
}
