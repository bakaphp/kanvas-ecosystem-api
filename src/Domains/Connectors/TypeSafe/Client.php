<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\TypeSafe\Contracts\Question;
use Kanvas\Connectors\TypeSafe\DataTransferObject\SystemOneResult;
use Kanvas\Connectors\TypeSafe\Exceptions\TypeSafeException;
use Kanvas\Connectors\TypeSafe\Services\TypeSafeConfigService;
use Throwable;

/**
 * TypeSafe System One (Jev): typed questions answered against a state, with calibrated probabilities.
 * It classifies, it never generates text.
 *
 * The timeouts here are deliberately short. System One exists to be faster than the LLM it stands in
 * front of, and every call site keeps that LLM as its fallback — so failing quickly is strictly better
 * than waiting, and this client never blocks a request for longer than a fallback would have taken.
 */
final class Client
{
    private const string BASE_URL = 'https://api.typesafe.ai/v1';
    private const int DEFAULT_TIMEOUT = 5;
    private const int MAX_ATTEMPTS = 3;
    private const int BASE_BACKOFF_MS = 150;
    private const int MAX_BACKOFF_MS = 1500;

    /** 429 and 529 are the two TypeSafe documents as retryable; everything else is our bug or a dead key. */
    private const array RETRYABLE_STATUSES = [429, 529];

    private readonly string $apiKey;
    private readonly string $model;

    public function __construct(
        AppInterface $app,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
    ) {
        $config = new TypeSafeConfigService($app);
        $apiKey = $config->apiKey();

        if ($apiKey === null) {
            throw new TypeSafeException('TypeSafe API key is not set for app ' . (string) $app->getId() . '.');
        }

        $this->apiKey = $apiKey;
        $this->model = $config->model();
    }

    /**
     * Every question is evaluated against the same state, in parallel — so asking twenty of them costs
     * roughly what one does. Send only the fields the questions need: irrelevant state measurably
     * lowers accuracy, so passing the whole entity is not the free convenience it looks like.
     *
     * @param string|array<array-key, mixed> $state
     * @param array<string, Question>        $questions Keyed by the id the answers come back under.
     */
    public function ask(string|array $state, array $questions, ?string $model = null): SystemOneResult
    {
        if ($questions === []) {
            throw new TypeSafeException('A System One request needs at least one question.');
        }

        $payload = [
            'state' => $state,
            'model' => $model ?? $this->model,
            'questions' => array_map(static fn (Question $question): array => $question->toArray(), $questions),
        ];

        $startedAt = hrtime(true);
        $response = $this->send('systemone', $payload);
        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return SystemOneResult::fromResponse($response->json() ?? [], $latencyMs);
    }

    /**
     * @return array<array-key, mixed> One entry per model: name, description, release_date.
     */
    public function models(): array
    {
        /** @var Response $response */
        $response = $this->request()->get(self::BASE_URL . '/models');

        $this->assertSuccessful($response, 'models');

        $models = $response->json('models');

        return is_array($models) ? $models : [];
    }

    /**
     * `GET /models` is the cheapest authenticated call there is — no tokens billed, no state sent.
     */
    public static function validateCredentials(string $key): bool
    {
        try {
            // No retry: a key the API refuses is not going to be accepted on the second ask.
            /** @var Response $response */
            $response = self::http($key, timeout: 15)->get(self::BASE_URL . '/models');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $endpoint, array $payload): Response
    {
        try {
            /** @var Response $response */
            $response = $this->request()->post(self::BASE_URL . '/' . $endpoint, $payload);
        } catch (Throwable $e) {
            // A timeout or a DNS failure arrives as a transport exception, not a response — normalize
            // it so a call site only ever has one exception type to fall back on.
            throw new TypeSafeException('TypeSafe ' . $endpoint . ' request failed: ' . $e->getMessage());
        }

        $this->assertSuccessful($response, $endpoint);

        return $response;
    }

    private static function http(string $apiKey, int $timeout): PendingRequest
    {
        return Http::withToken($apiKey)->timeout($timeout)->acceptJson();
    }

    private function request(): PendingRequest
    {
        return self::http($this->apiKey, $this->timeout)
            ->retry(
                times: self::MAX_ATTEMPTS,
                sleepMilliseconds: fn (int $attempt, Throwable $exception): int => $this->backoffMs($attempt, $exception),
                when: static fn (Throwable $exception): bool => $exception instanceof RequestException
                    && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true),
                throw: false,
            );
    }

    /**
     * Honour the server's `retry-after`, but never past our own ceiling. A wait measured in seconds
     * defeats the point of System One, and the caller's LLM fallback is sitting right there.
     */
    private function backoffMs(int $attempt, Throwable $exception): int
    {
        $backoff = self::BASE_BACKOFF_MS * 2 ** ($attempt - 1);

        $retryAfter = $exception instanceof RequestException
            ? $this->retryAfterMs($exception->response)
            : null;

        return min(max($backoff, $retryAfter ?? 0), self::MAX_BACKOFF_MS);
    }

    private function retryAfterMs(Response $response): ?int
    {
        $milliseconds = $response->header('retry-after-ms');

        if (is_numeric($milliseconds)) {
            return (int) $milliseconds;
        }

        $seconds = $response->header('retry-after');

        return is_numeric($seconds) ? (int) ((float) $seconds * 1000.0) : null;
    }

    private function assertSuccessful(Response $response, string $endpoint): void
    {
        if ($response->successful()) {
            return;
        }

        throw new TypeSafeException(sprintf(
            'TypeSafe %s failed (HTTP %d): %s',
            $endpoint,
            $response->status(),
            $this->errorDetail($response),
        ));
    }

    /**
     * TypeSafe does not document its error body, so read the shapes their SDKs allow for and fall back
     * to the raw body rather than swallowing a message we did not anticipate.
     */
    private function errorDetail(Response $response): string
    {
        foreach (['error.message', 'message', 'detail', 'error'] as $path) {
            $detail = $response->json($path);

            if (is_string($detail) && trim($detail) !== '') {
                return $detail;
            }
        }

        return mb_substr($response->body(), 0, 500);
    }
}
