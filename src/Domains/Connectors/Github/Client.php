<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Github;

use Baka\Support\Str;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Github\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * A plain GitHub API client. Deliberately knows nothing about Kanvas or which repositories matter —
 * a tenant connecting their own GitHub uses this same class. Anything that knows *whose* repos they
 * are belongs above this layer.
 */
class Client
{
    private const string BASE_URL = 'https://api.github.com/';
    private const int PER_PAGE = 30;
    private const int TIMEOUT = 15;

    public function __construct(
        private readonly string $token,
    ) {
    }

    public static function getInstanceByApp(Apps $app): self
    {
        $token = Str::trimToNull($app->get(ConfigurationEnum::TOKEN->value));

        if ($token === null) {
            throw new ValidationException(
                'No GitHub token configured for app ' . $app->getId()
                . '. Set ' . ConfigurationEnum::TOKEN->value . ' first.'
            );
        }

        return new self($token);
    }

    /**
     * The `owner/repo` the API needs, from whatever a person actually has in hand — the address bar,
     * an SSH clone line, a `.git` suffix, a link to a specific release.
     *
     * Nobody types `owner/repo` when the URL is one copy away, and pasting the URL used to reach
     * `/repos/https://github.com/owner/repo/releases` — a 404 that, on a private repository, reads
     * exactly like a token without access.
     */
    /**
     * The same thing for callers that hold a string which may not be a GitHub reference at all.
     *
     * A coding session's repository is whatever the person typed, and that is sometimes a bare project
     * name with no owner. Those are legitimate and must survive untouched, so this answers null rather
     * than throwing and leaves the caller to keep what it had.
     */
    public static function tryNormalizeRepository(string $repository): ?string
    {
        try {
            return self::normalizeRepository($repository);
        } catch (ValidationException) {
            return null;
        }
    }

    public static function normalizeRepository(string $repository): string
    {
        $value = trim($repository);
        $value = (string) preg_replace('~[?#].*$~', '', $value);
        $value = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $value);
        $value = (string) preg_replace('~^[^/@]+@~', '', $value);
        $value = (string) preg_replace('~^(www\.)?github\.com[:/]~i', '', $value);
        $value = (string) preg_replace('~\.git$~i', '', trim($value, '/'));

        // Anything past the first two segments is web-UI noise: /releases/tag/v1.0, /tree/main, /issues/12.
        $segments = array_slice(explode('/', $value), 0, 2);
        // `.` and `..` pass the charset but would climb out of /repos/ and address an unrelated endpoint.
        $isName = fn (string $segment): bool => preg_match('~^[A-Za-z0-9._\-]+$~', $segment) === 1
            && ! in_array($segment, ['.', '..'], true);

        if (count($segments) < 2 || ! $isName($segments[0]) || ! $isName($segments[1])) {
            throw new ValidationException(
                '"' . $repository . '" is not a GitHub repository. Use owner/repo — its URL works too.'
            );
        }

        return $segments[0] . '/' . $segments[1];
    }

    /**
     * One page of a repository's releases, newest first — GitHub's own ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function releases(string $repository, int $page = 1): array
    {
        $response = Http::withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->timeout(self::TIMEOUT)
            ->get(self::BASE_URL . 'repos/' . self::normalizeRepository($repository) . '/releases', [
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

        if ($response->failed()) {
            throw new ValidationException(
                'GitHub releases request failed for ' . $repository . ': HTTP ' . $response->status()
            );
        }

        return (array) $response->json();
    }
}
