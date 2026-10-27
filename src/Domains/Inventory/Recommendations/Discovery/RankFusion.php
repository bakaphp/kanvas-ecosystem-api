<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery;

/**
 * Reciprocal-rank fusion. Searches score on different scales (hybrid text+vector,
 * raw cosine, Algolia's tie-breaking), so they are merged on rank position, never
 * on score.
 */
final class RankFusion
{
    /** Dampener; stops the top slot dominating everything below. */
    private const int K = 60;

    private const int MAX_POOL_SIZE = 100;

    /** Wider than the page: fusion and caller-side filtering both drop candidates. */
    public static function poolSize(int $limit): int
    {
        return min($limit * 3, self::MAX_POOL_SIZE);
    }

    /**
     * Rank-fuses several searches and returns the documents themselves, so a caller can
     * render from the hits without reading them back from the database.
     *
     * @param array<int, array<string, mixed>> $results one engine result per search, each with its `hits`
     * @param callable(array<string, mixed>): array<string, mixed> $documentOf the product record inside one hit
     *
     * @return list<array<string, mixed>>
     */
    public static function fuseDocuments(array $results, callable $documentOf, int $limit): array
    {
        $documents = [];
        $rankings = [];

        foreach ($results as $result) {
            $ranking = [];

            foreach ($result['hits'] ?? [] as $hit) {
                $document = $documentOf($hit);
                $id = (int) ($document['id'] ?? 0);

                if ($id > 0) {
                    $documents[$id] ??= $document;
                    $ranking[] = $id;
                }
            }

            $rankings[] = $ranking;
        }

        return array_map(
            static fn (int $id): array => $documents[$id],
            self::fuse($rankings, $limit),
        );
    }

    /**
     * @param list<list<int>> $rankings ids, best first, one list per search
     *
     * @return list<int>
     */
    public static function fuse(array $rankings, int $limit): array
    {
        $scores = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking as $rank => $id) {
                if ($id <= 0) {
                    continue;
                }

                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (float) (self::K + $rank + 1);
            }
        }

        arsort($scores);

        return array_slice(array_map('intval', array_keys($scores)), 0, $limit);
    }
}
