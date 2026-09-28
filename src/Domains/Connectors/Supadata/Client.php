<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Supadata;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Supadata\Enums\ConfigurationEnum;
use Kanvas\Connectors\Supadata\Exceptions\TranscriptUnavailableException;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Supadata REST client — video transcripts from YouTube, TikTok, Instagram, X, Facebook and public
 * file URLs.
 *
 * Two response shapes callers have to know about, both of which arrive as HTTP 2xx:
 *
 *  - **202 + `{jobId}`** — the media was too long to transcribe inline. The transcript is only
 *    reachable by polling `/transcript/{jobId}`, so a caller that ignores the key silently returns
 *    an empty transcript for exactly the long recordings people care most about.
 *  - **206 + an error envelope** — Supadata's "transcript unavailable". It is a success status
 *    carrying `{error, message}`, so `$response->failed()` is false and the body has to be inspected.
 *
 * Payloads are returned decoded and untouched; shaping for an LLM (truncation, timestamp
 * formatting) belongs in the tools, which know their own context budget.
 */
class Client
{
    protected string $baseUrl = 'https://api.supadata.ai/v1';
    protected string $apiKey;

    /**
     * Company first, app as the fallback — the shape RespondIO uses. Transcription is metered and
     * billed per minute of media, so a tenant that brings its own Supadata account spends its own
     * credits; the app key is the platform-wide default for everyone who has not.
     */
    public function __construct(AppInterface $app, CompanyInterface $company)
    {
        // A blank company key falls through to the app, not just a null one. `?? ` alone would strand a
        // company that connected Supadata once and later cleared it: the setting is then '' (or int 0,
        // since settings round-trip through json_decode), which is not null, so the platform key never
        // gets a look and the company loses transcription entirely.
        $key = self::readKey($company->get(ConfigurationEnum::SUPADATA_API_KEY->value));

        if ($key === '') {
            $key = self::readKey($app->get(ConfigurationEnum::SUPADATA_API_KEY->value));
        }

        if ($key === '') {
            throw new ValidationException(
                'Supadata API key is not set for company ' . $company->getId() . ' or app ' . $app->getId()
            );
        }

        $this->apiKey = $key;
    }

    private static function readKey(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '0' ? '' : $value;
    }

    /**
     * @param array<string, mixed> $options `lang`, `text`, `chunkSize`, `mode`
     * @return array<string, mixed> Either the transcript, or `{jobId}` when it went async.
     */
    public function transcript(string $url, array $options = []): array
    {
        return $this->get('/transcript', array_merge(
            ['mode' => 'auto', 'text' => true],
            $options,
            ['url' => $url],
        ), timeout: 120);
    }

    /**
     * @return array<string, mixed> `{status, content, lang, availableLangs}` — status is one of
     *                              queued, active, completed, failed.
     */
    public function transcriptJob(string $jobId): array
    {
        return $this->get('/transcript/' . rawurlencode($jobId));
    }

    /**
     * `/me` rather than a transcript call: it is the only endpoint that proves the key without
     * spending a credit against the account being validated.
     */
    public static function validateCredentials(string $key): bool
    {
        try {
            /** @var Response $response */
            $response = Http::withHeaders(['x-api-key' => $key])
                ->timeout(10)
                ->acceptJson()
                ->get('https://api.supadata.ai/v1/me');

            // A 2xx carrying an error envelope still means the key did not work. `error` is a string
            // in that envelope, so an is_array() check on it would never fire.
            return $response->successful() && $response->json('error') === null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $query = [], int $timeout = 30): array
    {
        // http_build_query renders booleans as 1/0, which Supadata reads as neither true nor false
        // and silently ignores — `text=0` comes back as timestamped chunks anyway.
        $query = array_map(
            static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value,
            $query,
        );

        try {
            /** @var Response $response */
            $response = Http::withHeaders(['x-api-key' => $this->apiKey])
                ->timeout($timeout)
                ->acceptJson()
                ->get($this->baseUrl . $endpoint, $query);
        } catch (Throwable $e) {
            throw new ValidationException('Supadata ' . $endpoint . ' request failed: ' . $e->getMessage());
        }

        return $this->handleResponse($response, $endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(Response $response, string $endpoint): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        // `error` is the documented envelope key on every failure, and on the 206 that is not one.
        if (! $response->failed() && ! isset($body['error'])) {
            return $body;
        }

        $detail = $body['message'] ?? $body['error'] ?? $response->body();
        $message = sprintf(
            'Supadata %s failed (HTTP %d): %s',
            $endpoint,
            $response->status(),
            is_string($detail) ? $detail : (string) json_encode($detail),
        );

        throw ($body['error'] ?? null) === 'transcript-unavailable'
            ? new TranscriptUnavailableException($message)
            : new ValidationException($message);
    }
}
