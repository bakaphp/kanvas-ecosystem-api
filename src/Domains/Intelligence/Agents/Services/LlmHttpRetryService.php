<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Retry policy for Neuron LLM provider calls.
 *
 * Neuron forwards every provider error straight to the caller by design — the maintainer closed
 * both neuron-core/neuron-ai#256 and #480 with "the original exception is thrown ... let developers
 * decide what to do", and its only `maxRetries` is structured-output re-prompting, not transport.
 * So a Gemini 503 "model is currently experiencing high demand" surfaces as a hard failure on the
 * first attempt (Sentry KANVAS-ECOSYSTEM-61Z). This pushes the retry into the Guzzle handler stack,
 * which every Neuron provider shares through AgentProviderService.
 */
class LlmHttpRetryService
{
    public const int MAX_RETRIES = 2;

    private const int BASE_DELAY_MS = 1000;

    /**
     * The provider rejected the call outright, so no tokens were generated and there is no partial
     * state a replay could corrupt. Streaming is safe for the same reason: Guzzle hands the response
     * back at header time, so a 503 here means nothing was ever emitted to the consumer.
     */
    private const array RETRYABLE_STATUS_CODES = [
        429,
        502,
        503,
        504,
    ];

    /**
     * A provider asking for a longer hold than this is having an outage, not a blip — better to
     * fail the turn and let the queue's own backoff take over than to pin a worker.
     */
    private const int MAX_RETRY_AFTER_SECONDS = 10;

    /**
     * @param (callable(RequestInterface, array): PromiseInterface)|null $handler Underlying
     *        transport; tests pass a MockHandler.
     */
    public static function handlerStack(?callable $handler = null): HandlerStack
    {
        $stack = HandlerStack::create($handler);

        // Pushed last, which resolves to innermost, so the decider sees the raw 503 response
        // before Guzzle's http_errors middleware turns it into a ServerException.
        /** @var callable(callable): callable $retry */
        $retry = Middleware::retry(self::decider(), self::delay());
        $stack->push($retry);

        return $stack;
    }

    private static function decider(): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            // Responses only, never transport exceptions. Guzzle folds read timeouts into
            // ConnectException (CURLE_OPERATION_TIMEOUTED is in CurlFactory's $connectionErrors),
            // so retrying those would replay a request the model may already be answering and
            // stretch the worst case to three times the 220s provider timeout. A rejected call
            // always comes back fast, which is what keeps this bounded.
            return $response instanceof ResponseInterface
                && in_array($response->getStatusCode(), self::RETRYABLE_STATUS_CODES, true);
        };
    }

    private static function delay(): callable
    {
        return static function (int $retries, ?ResponseInterface $response): int {
            $retryAfter = self::retryAfterMs($response);

            if ($retryAfter !== null) {
                return $retryAfter;
            }

            $backoff = self::BASE_DELAY_MS * (2 ** $retries);

            // Full jitter: a demand spike hits every worker at once, so a fixed backoff would
            // march the whole fleet back to the provider in the same millisecond.
            return random_int((int) ($backoff / 2), $backoff);
        };
    }

    private static function retryAfterMs(?ResponseInterface $response): ?int
    {
        $header = trim($response?->getHeaderLine('Retry-After') ?? '');

        // Only the delta-seconds form; the HTTP-date form is rare here and not worth parsing.
        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return min((int) $header, self::MAX_RETRY_AFTER_SECONDS) * 1000;
    }
}
