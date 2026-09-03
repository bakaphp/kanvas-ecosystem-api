<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Resolves a free-text vendor/customer name (as extracted from an invoice) to a Guild Organization by
 * comparing significant word-tokens rather than requiring an exact or substring match — the extracted
 * name and the Organization's own name (often synced from Acumatica) frequently disagree on legal-form
 * noise ("Penner + Partner WP StB mbB" vs "GmbH-PENNER + PARTNER GBR" are the same firm).
 *
 * Auto-selects only when the best candidate clearly stands out (score + margin over the runner-up) —
 * this decides which vendor a bill gets coded to and who is asked to approve it, so a close call
 * returns candidates for disambiguation instead of guessing.
 */
final class OrganizationVendorMatcherService
{
    private const MIN_SCORE = 0.6;
    private const MIN_MARGIN = 0.15;
    private const MAX_CANDIDATES = 5;

    // A single coincidentally-shared word (e.g. both names happen to contain "Group") is noise,
    // not a real disambiguation candidate — floor it out before deciding matched/ambiguous/none.
    private const MIN_CANDIDATE_SCORE = 0.34;

    /**
     * Legal/professional-designation noise words stripped before comparing — broader than
     * OrganizationNameNormalizerService (which strips only ONE trailing suffix) because these can
     * appear anywhere, in any order, and in combination (e.g. "WP StB mbB").
     */
    private const STOPWORDS = [
        'srl', 'sa', 'sas', 'eirl', 'llc', 'ltd', 'ltda', 'corp', 'corporation', 'inc', 'incorporated', 'co',
        'gmbh', 'mbb', 'gbr', 'ohg', 'kg', 'ug', 'ag', 'ev', 'partg', 'haftungsbeschrankt', 'haftungsbeschränkt',
        'wp', 'stb',
    ];

    public static function match(Apps $app, Companies $company, string $rawName): OrganizationVendorMatchResult
    {
        $needleTokens = self::significantTokens($rawName);

        if ($needleTokens === []) {
            return OrganizationVendorMatchResult::none();
        }

        $organizations = Organization::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->limit(2000)
            ->get(['id', 'name']);

        $scored = [];
        foreach ($organizations as $organization) {
            $score = self::score($needleTokens, self::significantTokens($organization->name));
            if ($score >= self::MIN_CANDIDATE_SCORE) {
                $scored[] = ['organization' => $organization, 'score' => $score];
            }
        }

        if ($scored === []) {
            return OrganizationVendorMatchResult::none();
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $best = $scored[0];
        $runnerUpScore = $scored[1]['score'] ?? 0.0;

        if ($best['score'] >= self::MIN_SCORE && ($best['score'] - $runnerUpScore) >= self::MIN_MARGIN) {
            return OrganizationVendorMatchResult::matched($best['organization'], $best['score']);
        }

        return OrganizationVendorMatchResult::ambiguous(array_map(
            static fn (array $s): Organization => $s['organization'],
            array_slice($scored, 0, self::MAX_CANDIDATES),
        ));
    }

    /**
     * @return list<string>
     */
    private static function significantTokens(string $name): array
    {
        $normalized = OrganizationNameNormalizerService::normalize($name);
        $letters = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized), 'UTF-8');
        $tokens = array_filter(preg_split('/\s+/', trim($letters)) ?: [], static fn (string $t): bool => $t !== '');

        return array_values(array_diff($tokens, self::STOPWORDS));
    }

    /**
     * @param list<string> $needleTokens
     * @param list<string> $candidateTokens
     */
    private static function score(array $needleTokens, array $candidateTokens): float
    {
        if ($needleTokens === [] || $candidateTokens === []) {
            return 0.0;
        }

        $needleSet = array_unique($needleTokens);
        $candidateSet = array_unique($candidateTokens);
        $union = array_unique(array_merge($needleSet, $candidateSet));

        return count(array_intersect($needleSet, $candidateSet)) / count($union);
    }
}
