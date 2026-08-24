<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Throwable;

/**
 * Scores product discovery against a human-judged query set.
 *
 * Exists so prompt, weight and alpha changes stop being argued from anecdotes.
 * Enrichment costs about a dollar to regenerate, which makes trying four profile
 * templates cheap — but only if there is a number saying which one won.
 */
class EvaluateProductDiscoveryCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:evaluate-product-discovery
                            {app_id : App to evaluate}
                            {company_id : Company whose catalog to search}
                            {--file= : Golden-set JSON, defaults to the example fixture}
                            {--k=10 : Cut-off for recall@k}
                            {--limit= : Results to request per query, defaults to k}
                            {--min-recall= : Exit non-zero when mean recall falls below this (0-1), for CI}
                            {--show-misses : Print the returned ids for cases that scored zero}
                            {--allow-unjudged : Score cases still flagged unjudged, which self-compare and always pass}';

    /**
     * @var string
     */
    protected $description = 'Score product discovery against a judged query set (recall@k and MRR)';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        $cases = $this->loadCases();

        if ($cases === []) {
            return self::FAILURE;
        }

        $unjudged = count(array_filter($cases, static fn (array $case): bool => (bool) ($case['unjudged'] ?? false)));

        if ($unjudged > 0) {
            $this->error($unjudged . ' of ' . count($cases) . ' case(s) are still marked unjudged.');
            $this->line('They hold whatever discovery returned when the set was drafted, so scoring them '
                . 'compares discovery against itself and reports a perfect result. Delete the wrong ids, '
                . 'then remove the "unjudged" flag. Pass --allow-unjudged to score anyway.');

            if (! $this->option('allow-unjudged')) {
                return self::FAILURE;
            }
        }

        $k = max(1, (int) $this->option('k'));
        $limit = (int) ($this->option('limit') ?: $k);

        // A cached candidate list would score the previous configuration, which
        // is exactly the mistake this command exists to prevent.
        Cache::flush();

        $rows = [];
        $recalls = [];
        $reciprocalRanks = [];

        foreach ($cases as $index => $case) {
            $result = $this->evaluateCase($app, $company, $case, $k, $limit);

            $recalls[] = $result['recall'];
            $reciprocalRanks[] = $result['reciprocal_rank'];

            $rows[] = [
                $index + 1,
                mb_strimwidth($case['query'], 0, 48, '…'),
                count($case['relevant_product_ids']),
                $result['hits'],
                sprintf('%.2f', $result['recall']),
                $result['first_hit_rank'] ?? '—',
                sprintf('%.3f', $result['reciprocal_rank']),
            ];

            if ($this->option('show-misses') && $result['recall'] === 0.0) {
                $this->line(sprintf(
                    '  miss: "%s" → returned [%s], wanted [%s]',
                    $case['query'],
                    implode(', ', $result['returned_ids']),
                    implode(', ', $case['relevant_product_ids']),
                ));
            }
        }

        $this->table(
            ['#', 'Query', 'Judged', 'Hits', "Recall@{$k}", 'First hit', 'RR'],
            $rows,
        );

        $meanRecall = $this->mean($recalls);
        $mrr = $this->mean($reciprocalRanks);
        $zeroCases = count(array_filter($recalls, static fn (float $r): bool => $r === 0.0));

        $this->newLine();
        $this->line(sprintf('cases=%d  recall@%d=%.3f  MRR=%.3f  zero-recall=%d', count($cases), $k, $meanRecall, $mrr, $zeroCases));

        $minRecall = $this->option('min-recall');

        if ($minRecall !== null && $meanRecall < (float) $minRecall) {
            $this->error(sprintf('Mean recall %.3f is below the %.3f threshold.', $meanRecall, (float) $minRecall));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array{query: string, relevant_product_ids: list<int>} $case
     *
     * @return array{recall: float, reciprocal_rank: float, hits: int, first_hit_rank: int|null, returned_ids: list<int>}
     */
    private function evaluateCase(Apps $app, Companies $company, array $case, int $k, int $limit): array
    {
        try {
            $results = new RecommendProductsAction($app, $company)->execute($case['query'], $limit);
        } catch (Throwable $e) {
            $this->warn("Query \"{$case['query']}\" failed: {$e->getMessage()}");
            $results = [];
        }

        $returned = array_slice(array_column(array_column($results, 'product'), 'id'), 0, $k);
        $relevant = $case['relevant_product_ids'];

        $hits = count(array_intersect($returned, $relevant));
        $firstHitRank = null;

        foreach ($returned as $position => $id) {
            if (in_array($id, $relevant, true)) {
                $firstHitRank = $position + 1;

                break;
            }
        }

        return [
            // Recall, not precision: the question is how much of what a human
            // called relevant actually surfaced.
            'recall' => $relevant === [] ? 0.0 : $hits / count($relevant),
            'reciprocal_rank' => $firstHitRank === null ? 0.0 : 1 / $firstHitRank,
            'hits' => $hits,
            'first_hit_rank' => $firstHitRank,
            'returned_ids' => $returned,
        ];
    }

    /**
     * @return list<array{query: string, relevant_product_ids: list<int>, unjudged: bool}>
     */
    private function loadCases(): array
    {
        $file = (string) ($this->option('file') ?: base_path('tests/fixtures/product-discovery-golden-set.example.json'));

        if (! is_readable($file)) {
            $this->error("Golden set not readable: {$file}");

            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded) || ! isset($decoded['cases']) || ! is_array($decoded['cases'])) {
            $this->error('Golden set must be a JSON object with a "cases" array.');

            return [];
        }

        $cases = [];

        foreach ($decoded['cases'] as $case) {
            if (! is_array($case) || ! isset($case['query']) || ! is_array($case['relevant_product_ids'] ?? null)) {
                continue;
            }

            $cases[] = [
                'query' => (string) $case['query'],
                'relevant_product_ids' => array_map('intval', $case['relevant_product_ids']),
                'unjudged' => (bool) ($case['unjudged'] ?? false),
            ];
        }

        if ($cases === []) {
            $this->error('No usable cases found — each needs a "query" and a "relevant_product_ids" array.');
        }

        return $cases;
    }

    /**
     * @param list<float> $values
     */
    private function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }
}
