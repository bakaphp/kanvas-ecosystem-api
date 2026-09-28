<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Services;

use Kanvas\Connectors\WordPress\RestClient;
use Throwable;

/**
 * Turns category/tag names into wp/v2 term ids, creating the term when the site allows it.
 */
class WordPressTermService
{
    public const string CATEGORY_TAXONOMY = 'categories';
    public const string TAG_TAXONOMY = 'tags';

    /** @var array<string, int|null> */
    private array $resolved = [];

    public function __construct(
        private readonly RestClient $client,
        private readonly bool $allowCreation = true,
    ) {
    }

    /**
     * @param list<string|int> $terms
     *
     * @return list<int>
     */
    public function resolveIds(string $taxonomy, array $terms): array
    {
        $ids = [];

        foreach ($terms as $term) {
            if (is_int($term) || ctype_digit((string) $term)) {
                $ids[] = (int) $term;

                continue;
            }

            $id = $this->resolveName($taxonomy, (string) $term);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function resolveName(string $taxonomy, string $name): ?int
    {
        $cacheKey = $taxonomy . '|' . mb_strtolower($name);

        return array_key_exists($cacheKey, $this->resolved)
            ? $this->resolved[$cacheKey]
            : $this->resolved[$cacheKey] = $this->lookup($taxonomy, $name);
    }

    private function lookup(string $taxonomy, string $name): ?int
    {
        $existing = $this->findExact($taxonomy, $name);

        if ($existing !== null || ! $this->allowCreation) {
            return $existing;
        }

        try {
            return (int) $this->client->createTerm($taxonomy, $name)['id'];
        } catch (Throwable $e) {
            // Either a term_exists race (created between the search and the write) or a permission
            // problem. Re-read once; if it still isn't there, drop this one term rather than the post.
            return $this->findExact($taxonomy, $name);
        }
    }

    /**
     * WP's `search` is a fuzzy LIKE across name and slug, so match the name exactly before
     * trusting a hit.
     */
    private function findExact(string $taxonomy, string $name): ?int
    {
        foreach ($this->client->searchTerms($taxonomy, $name) as $term) {
            if (mb_strtolower((string) ($term['name'] ?? '')) === mb_strtolower($name)) {
                return (int) $term['id'];
            }
        }

        return null;
    }
}
