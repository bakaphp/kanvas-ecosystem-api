<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\Services;

use Baka\Http\SafeUrl;
use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Support\WebsiteUrl;
use Kanvas\Connectors\Jina\Client as JinaClient;
use Kanvas\Connectors\Tavily\Client as TavilyClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Knowledge\Research\DataTransferObject\CollectedPages;

/**
 * Tavily (map, then extract) when the app has a key, Jina on the home page when it only has that one,
 * a direct fetch otherwise. Every path starts with the SSRF check, so a private host never reaches a
 * vendor either.
 */
class WebsitePageCollector
{
    public const int MAX_PAGES = 12;
    public const int MAX_PAGE_CHARS = 6_000;

    /** Path keywords in the order they earn a slot; an unmatched path only fills what is left. */
    private const array PATH_PRIORITY = [
        'about',
        'services',
        'products',
        'pricing',
        'contact',
        'team',
        'faq',
        'locations',
        'company',
        'solutions',
    ];

    public function __construct(private readonly Apps $app)
    {
    }

    public function collect(string $website): CollectedPages
    {
        $url = WebsiteUrl::withScheme($website);
        SafeUrl::assertSafe($url);
        $home = rtrim($url, '/');

        try {
            $tavily = new TavilyClient($this->app);
        } catch (ValidationException) {
            $tavily = null;
        }

        if ($tavily !== null) {
            return new CollectedPages($home, 'tavily', $this->viaTavily($tavily, $home));
        }

        try {
            $jina = new JinaClient($this->app);
        } catch (ValidationException) {
            $jina = null;
        }

        if ($jina !== null) {
            return new CollectedPages($home, 'jina', $this->viaJina($jina, $home));
        }

        return new CollectedPages($home, 'direct', $this->viaDirectFetch($home));
    }

    /**
     * @return list<array{url: string, title: ?string, content: string}>
     */
    private function viaTavily(TavilyClient $client, string $home): array
    {
        $mapped = (array) ($client->map($home, ['limit' => 50])['results'] ?? []);
        $extracted = (array) ($client->extract($this->pickUrls($home, $mapped))['results'] ?? []);

        $pages = [];
        foreach ($extracted as $result) {
            $page = $this->page(
                (string) ($result['url'] ?? ''),
                $result['title'] ?? null,
                (string) ($result['raw_content'] ?? ''),
            );
            if ($page !== null) {
                $pages[] = $page;
            }
        }

        return array_slice($pages, 0, self::MAX_PAGES);
    }

    /**
     * @return list<array{url: string, title: ?string, content: string}>
     */
    private function viaJina(JinaClient $client, string $home): array
    {
        $data = $client->read($home);
        $page = $this->page($home, $data['title'] ?? null, (string) ($data['content'] ?? ''));

        return $page === null ? [] : [$page];
    }

    /**
     * @return list<array{url: string, title: ?string, content: string}>
     */
    private function viaDirectFetch(string $home): array
    {
        $html = SafeUrlFetcher::fetch($home);
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $match) === 1
            ? Str::trimToNull(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : null;
        $body = (string) preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $html);
        $page = $this->page($home, $title, Str::htmlToText($body));

        return $page === null ? [] : [$page];
    }

    /**
     * The home page plus the best MAX_PAGES - 1 others on the same host.
     *
     * @param list<mixed> $mapped
     * @return list<string>
     */
    private function pickUrls(string $home, array $mapped): array
    {
        $host = WebsiteUrl::host($home);
        $ranked = [];
        foreach ($mapped as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = rtrim(strtok($candidate, '#'), '/');
            if ($candidate === $home || WebsiteUrl::host($candidate) !== $host || isset($ranked[$candidate])) {
                continue;
            }
            $ranked[$candidate] = $this->pathRank(strtolower((string) parse_url($candidate, PHP_URL_PATH)));
        }

        asort($ranked);

        return [$home, ...array_slice(array_keys($ranked), 0, self::MAX_PAGES - 1)];
    }

    private function pathRank(string $path): int
    {
        foreach (self::PATH_PRIORITY as $rank => $keyword) {
            if (str_contains($path, $keyword)) {
                return $rank;
            }
        }

        return count(self::PATH_PRIORITY);
    }

    /**
     * @return array{url: string, title: ?string, content: string}|null
     */
    private function page(string $url, mixed $title, string $content): ?array
    {
        $content = trim($content);
        if ($url === '' || $content === '') {
            return null;
        }

        return [
            'url' => rtrim($url, '/'),
            'title' => is_string($title) ? Str::trimToNull($title) : null,
            'content' => mb_substr($content, 0, self::MAX_PAGE_CHARS),
        ];
    }
}
