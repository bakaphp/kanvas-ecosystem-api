<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Throwable;

/**
 * Writes a golden set pre-filled with what discovery answers today.
 *
 * Judging is the expensive part, and it is much faster to delete wrong ids from a
 * list than to look up right ones by hand. The queries come from the impression
 * log, so the set scores what shoppers actually ask rather than what we imagine.
 */
class ScaffoldGoldenSetCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:scaffold-golden-set
                            {app_id : App to pull queries from}
                            {company_id : Company whose catalog to search}
                            {--out= : Where to write the JSON, defaults to a storage/app file named for the app and company}
                            {--cases=20 : How many queries to include}
                            {--limit=10 : Results to pre-fill per query}
                            {--min-runs=1 : Only include queries asked at least this many times}
                            {--query=* : Use these queries instead of the impression log}';

    /**
     * @var string
     */
    protected $description = 'Draft a product-discovery golden set from the impression log for a human to prune';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        $queries = $this->queries($app, $company);

        if ($queries === []) {
            $this->error('No queries found. Pass --query="..." or wait for the impression log to fill.');

            return self::FAILURE;
        }

        // A cached candidate list would pre-fill the previous configuration's answers.
        Cache::flush();

        $limit = max(1, (int) $this->option('limit'));
        $cases = [];

        foreach ($queries as $query) {
            $ids = $this->discover($app, $company, $query, $limit);
            $cases[] = [
                'query' => $query,
                'relevant_product_ids' => $ids,
                // Machine-readable so the evaluate command can refuse to score a
                // file nobody has judged: it would report a perfect result.
                'unjudged' => true,
                'note' => 'Delete the ids that are wrong for this query, add any that are missing, then remove "unjudged".',
            ];

            $this->line(sprintf('  %-60s %d result(s)', mb_strimwidth($query, 0, 58, '…'), count($ids)));
        }

        $path = (string) ($this->option('out')
            ?: storage_path('app/golden-set-' . $app->getId() . '-' . $company->getId() . '.json'));

        file_put_contents($path, json_encode([
            '_readme' => [
                'Every id below is what discovery returned, NOT a judgement. Prune it.',
                'Keep the cases that returned nothing — they are the ones a better blurb should fix.',
            ],
            'cases' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info(count($cases) . ' case(s) written to ' . $path);
        $this->line('Prune it, then: php artisan kanvas-inventory:evaluate-product-discovery '
            . $app->getId() . ' ' . $company->getId() . ' --file=' . $path);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function queries(Apps $app, Companies $company): array
    {
        $supplied = array_values(array_filter(array_map('trim', (array) $this->option('query'))));

        if ($supplied !== []) {
            return $supplied;
        }

        // query_raw, not query_normalized: the set should read like a shopper wrote it.
        return DB::connection('inventory')
            ->table('product_recommendation_impressions')
            ->selectRaw('MIN(query_raw) AS query_raw, COUNT(*) AS runs')
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->groupBy('query_normalized')
            ->havingRaw('COUNT(*) >= ?', [max(1, (int) $this->option('min-runs'))])
            ->orderByDesc('runs')
            ->limit(max(1, (int) $this->option('cases')))
            ->pluck('query_raw')
            ->all();
    }

    /**
     * @return list<int>
     */
    private function discover(Apps $app, Companies $company, string $query, int $limit): array
    {
        try {
            $results = new RecommendProductsAction($app, $company)->execute($query, $limit);
        } catch (Throwable $e) {
            $this->warn('  ' . $query . ' — ' . $e->getMessage());

            return [];
        }

        return array_map(static fn (array $result): int => (int) $result['product']['id'], $results);
    }
}
