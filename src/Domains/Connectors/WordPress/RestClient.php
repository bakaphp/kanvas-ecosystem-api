<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Http\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Authenticated wp/v2 REST client.
 *
 * Separate from the sibling Client, which scrapes a public inventory JSON endpoint with no auth —
 * this one talks to the WordPress core REST API using an Application Password (WP 5.6+), which is
 * Basic auth over HTTPS and the only credential a third-party site can hand us without a plugin.
 */
class RestClient
{
    protected const string API_NAMESPACE = '/wp-json/wp/v2';
    protected const int TIMEOUT_SECONDS = 45;
    protected const int MAX_RETRIES = 3;
    protected const int RETRY_DELAY_MS = 1500;
    protected const int TERM_SEARCH_PER_PAGE = 100;

    protected string $siteUrl;
    protected string $username;
    protected string $applicationPassword;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null,
    ) {
        $siteUrl = rtrim((string) $this->config(ConfigurationEnum::SITE_URL), '/');
        $username = (string) $this->config(ConfigurationEnum::USERNAME);
        $applicationPassword = (string) $this->config(ConfigurationEnum::APPLICATION_PASSWORD);

        if ($siteUrl === '' || $username === '' || $applicationPassword === '') {
            throw new ValidationException('WordPress REST configuration is missing (site url / username / application password)');
        }

        // Tenant-supplied at setup, so it counts as user input for SSRF purposes.
        SafeUrl::assertSafe($siteUrl);

        $this->siteUrl = $siteUrl;
        $this->username = $username;
        // WP prints application passwords in spaced groups; the spaces are display-only.
        $this->applicationPassword = str_replace(' ', '', $applicationPassword);
    }

    public static function isConfigured(AppInterface $app, ?CompanyInterface $company = null): bool
    {
        foreach ([ConfigurationEnum::SITE_URL, ConfigurationEnum::USERNAME, ConfigurationEnum::APPLICATION_PASSWORD] as $key) {
            $value = $company?->get($key->value) ?? $app->get($key->value);

            if (empty($value)) {
                return false;
            }
        }

        return true;
    }

    public function getSiteUrl(): string
    {
        return $this->siteUrl;
    }

    /**
     * Edit context so a rejected credential fails here rather than silently degrading to an
     * anonymous read, and because `capabilities` is only exposed in that context.
     */
    public function me(): array
    {
        return $this->send(
            'get',
            '/users/me',
            ['context' => 'edit']
        );
    }

    public function createPost(array $payload): array
    {
        return $this->send(
            'post',
            '/posts',
            $payload
        );
    }

    public function updatePost(int $postId, array $payload): array
    {
        return $this->send(
            'post',
            '/posts/' . $postId,
            $payload
        );
    }

    public function postExists(int $postId): bool
    {
        return $this->request()->get($this->endpoint('/posts/' . $postId))->successful();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchTerms(string $taxonomy, string $name): array
    {
        return $this->send(
            'get',
            '/' . $taxonomy,
            [
                'search' => $name,
                'per_page' => self::TERM_SEARCH_PER_PAGE,
            ]
        );
    }

    public function createTerm(string $taxonomy, string $name): array
    {
        return $this->send(
            'post',
            '/' . $taxonomy,
            ['name' => $name]
        );
    }

    /**
     * `post` is wp/v2's name for `post_parent`.
     */
    public function attachMediaToPost(int $mediaId, int $postId): array
    {
        return $this->send(
            'post',
            '/media/' . $mediaId,
            ['post' => $postId]
        );
    }

    /**
     * WP takes the raw bytes as the request body; the filename only travels in Content-Disposition.
     */
    public function uploadMedia(
        string $filename,
        string $contents,
        string $mimeType
    ): array {
        $response = $this->request()
            ->withHeaders(['Content-Disposition' => 'attachment; filename="' . $filename . '"'])
            ->withBody($contents, $mimeType)
            ->post($this->endpoint('/media'));

        return $this->parse($response, 'POST /media');
    }

    protected function send(
        string $method,
        string $path,
        array $payload = []
    ): array {
        $endpoint = $this->endpoint($path);

        $response = $method === 'get'
            ? $this->request()->get($endpoint, $payload)
            : $this->request()->asJson()->post($endpoint, $payload);

        return $this->parse($response, strtoupper($method) . ' ' . $path);
    }

    protected function request(): PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->applicationPassword)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(self::TIMEOUT_SECONDS)
            // Guzzle drops the Authorization header across any host or scheme change, so following
            // a redirect (http -> https, bare -> www) would arrive unauthenticated as a bare 401.
            ->withoutRedirecting()
            // throw: false hands the failed Response back to parse() so the caller gets WP's own
            // error message instead of a bare RequestException.
            ->retry(
                self::MAX_RETRIES,
                self::RETRY_DELAY_MS,
                $this->shouldRetry(...),
                throw: false
            );
    }

    /**
     * A rejected credential or a validation error fails the same way next attempt — only transport
     * failures and throttling are worth repeating.
     *
     * Nullable because Laravel passes `Response::toException()`, which is null for any non-failed
     * response — a 3xx reaches here as null and must not be retried.
     */
    protected function shouldRetry(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    protected function endpoint(string $path): string
    {
        return $this->siteUrl . self::API_NAMESPACE . '/' . ltrim($path, '/');
    }

    protected function parse(Response $response, string $context): array
    {
        if ($response->redirect()) {
            throw new ValidationException(
                'WordPress ' . $context . ' was redirected to "' . $response->header('Location')
                . '". Basic auth cannot survive a redirect — set the WordPress site url to the exact '
                . 'Site Address from Settings → General (matching scheme and www).'
            );
        }

        if ($response->failed()) {
            $body = json_decode($response->body(), true);
            $reason = is_array($body) && isset($body['message'])
                ? strip_tags((string) $body['message'])
                : $response->body();

            throw new ValidationException(
                'WordPress ' . $context . ' failed (HTTP ' . $response->status() . '): ' . mb_substr($reason, 0, 500)
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new ValidationException('WordPress ' . $context . ' returned an invalid JSON response');
        }

        return $data;
    }

    protected function config(ConfigurationEnum $key): mixed
    {
        return $this->company?->get($key->value) ?? $this->app->get($key->value);
    }
}
