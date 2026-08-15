<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Str;

/**
 * Resolves a delimited list of names in one query instead of one LLM round-trip per row — a 35-row
 * spreadsheet the per-name way spends minutes in provider latency and dies on the 120s request cap.
 *
 * A candidate matches once it shares two significant tokens with the term (one, when the term has
 * only one), which tolerates extra middle names and second surnames while keeping "Sandra Pichardo"
 * off "Sandra Rodriguez".
 */
trait MatchesBulkNameTerms
{
    protected const int BULK_MAX_TERMS = 100;
    protected const int BULK_MAX_CANDIDATE_ROWS = 2000;
    protected const int BULK_MIN_TOKEN_LENGTH = 3;
    protected const int BULK_DEFAULT_MATCHES_PER_TERM = 3;
    protected const int BULK_MAX_MATCHES_PER_TERM = 10;

    /**
     * @return list<array{query: string, tokens: list<string>}>
     */
    protected function parseBulkTerms(string $input): array
    {
        $terms = [];
        $seen = [];

        foreach (preg_split('/[,;\r\n]+/', $input) ?: [] as $part) {
            $query = trim($part);
            $tokens = $this->matchTokens($query);

            if ($tokens === [] || isset($seen[implode(' ', $tokens)])) {
                continue;
            }

            $seen[implode(' ', $tokens)] = true;
            $terms[] = ['query' => $query, 'tokens' => $tokens];

            if (count($terms) >= static::BULK_MAX_TERMS) {
                break;
            }
        }

        return $terms;
    }

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     * @return list<string>
     */
    protected function bulkLikeTokens(array $terms): array
    {
        return array_values(array_unique(array_merge(...array_column($terms, 'tokens'))));
    }

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     * @param list<array{record: mixed, tokens: list<string>}> $candidates
     * @param callable(mixed, int): array<string, mixed> $present
     * @return array{searched: int, matched: int, not_found: list<string>, results: list<array<string, mixed>>}
     */
    protected function assembleBulkResults(array $terms, array $candidates, int $maxMatches, callable $present): array
    {
        $results = [];
        $notFound = [];
        $matched = 0;

        foreach ($terms as $term) {
            $required = count($term['tokens']) === 1 ? 1 : 2;
            $scored = [];

            foreach ($candidates as $candidate) {
                $score = $this->bulkMatchScore($term['tokens'], $candidate['tokens']);

                if ($score >= $required) {
                    $scored[] = ['score' => $score, 'record' => $candidate['record']];
                }
            }

            usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            $matches = array_map(
                fn (array $row): array => $present($row['record'], $row['score']),
                array_slice($scored, 0, $maxMatches),
            );

            $matches === [] ? $notFound[] = $term['query'] : $matched++;

            $results[] = [
                'query' => $term['query'],
                'found' => $matches !== [],
                'matches' => $matches,
            ];
        }

        return [
            'searched' => count($terms),
            'matched' => $matched,
            'not_found' => $notFound,
            'results' => $results,
        ];
    }

    /**
     * Compared at token boundaries in both directions so an extended form still counts; plain
     * substring matching would let "ana" hit "Mariana".
     *
     * @param list<string> $termTokens
     * @param list<string> $candidateTokens
     */
    protected function bulkMatchScore(array $termTokens, array $candidateTokens): int
    {
        $score = 0;

        foreach ($termTokens as $token) {
            foreach ($candidateTokens as $candidateToken) {
                if (str_starts_with($candidateToken, $token) || str_starts_with($token, $candidateToken)) {
                    $score++;

                    break;
                }
            }
        }

        return $score;
    }

    protected function clampBulkMatchesPerTerm(?int $requested): int
    {
        return max(1, min(static::BULK_MAX_MATCHES_PER_TERM, $requested ?? static::BULK_DEFAULT_MATCHES_PER_TERM));
    }

    /**
     * Accents are folded here only to match the DB side, whose collation (utf8mb4_unicode_520_ci)
     * already treats "Duran" and "Durán" as equal — keep both ends folded or scoring silently
     * disagrees with the prefilter.
     *
     * @return list<string>
     */
    protected function matchTokens(string $value): array
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));

        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $token): bool => strlen($token) >= static::BULK_MIN_TOKEN_LENGTH,
        )));
    }
}
