<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Baka\Contracts\AppInterface;
use Baka\Http\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;

/**
 * Write-back client for BrushCrazy's `/api/kanvas/*` endpoints. Signed the way the mirror signs
 * towards Kanvas, in reverse: hex HMAC-SHA256 of "{timestamp}.{raw body}". BrushCrazy rejects a
 * timestamp more than 300 s off its clock.
 *
 * Built per call from app settings, never cached in a static (see src/Domains/Connectors/CLAUDE.md).
 */
final readonly class BrushCrazyApi
{
    private const int TIMEOUT_SECONDS = 20;
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private string $baseUrl,
        private string $secret,
    ) {
    }

    /**
     * Null when the app has no write-back configured, which the activities report as a failed
     * run rather than an exception: retrying cannot fix a missing setting.
     */
    public static function forApp(AppInterface $app): ?self
    {
        $url = trim((string) $app->get(ConfigurationEnum::BRUSHCRAZY_API_URL->value));
        $secret = (string) $app->get(ConfigurationEnum::BRUSHCRAZY_API_SECRET->value);

        if ($url === '' || $secret === '') {
            return null;
        }

        return new self(rtrim($url, '/'), $secret);
    }

    public static function signature(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * @throws RequestException on any 4xx/5xx; the caller decides which ones are worth a retry
     * @throws ConnectionException on a timeout or refused connection
     */
    public function post(string $path, array $payload): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        SafeUrl::assertSafe($url);

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->acceptJson()
            ->withHeaders([
                'X-Kanvas-Timestamp' => $timestamp,
                'X-Kanvas-Signature' => self::signature($timestamp, $body, $this->secret),
            ])
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }
}
