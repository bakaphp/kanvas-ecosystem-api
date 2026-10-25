<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Actions\BackfillProductEnrichmentAction;
use Kanvas\Inventory\Recommendations\Enrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Recommendations\Enrichment\Services\EnrichmentUsageService;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentAgentService;
use Throwable;

/**
 * Enrichment otherwise runs only when a product is created or edited, so a catalog that predates it
 * never gets a blurb — and without one, semantic search has nothing to embed.
 */
class BackfillProductEnrichmentCommand extends Command
{
    use KanvasJobsTrait;

    private EnrichmentUsageService $usage;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:backfill-product-enrichment
                            {app_id : App whose catalog to enrich}
                            {--company_id= : Limit to one company, otherwise the whole app}
                            {--agent_id= : Enrichment agent to use, defaults to the app default, or the shipped defaults when it has none}
                            {--limit= : Stop after this many products}
                            {--force : Re-enrich even when the content hash is unchanged}
                            {--sync : Run inline instead of queueing, for a dry run on a few products}';

    /**
     * @var string
     */
    protected $description = 'Generate enrichment facets and search blurbs for products that do not have them yet';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->usage = app(EnrichmentUsageService::class);

        $agentId = $this->option('agent_id') !== null ? (int) $this->option('agent_id') : null;

        // An explicit agent that does not exist must fail now rather than after
        // queueing a job per product that each throw the same error.
        try {
            ProductEnrichmentAgentService::resolveAgent($app, $agentId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $backfill = new BackfillProductEnrichmentAction(
            app: $app,
            company: $this->option('company_id') !== null ? Companies::getById((int) $this->option('company_id')) : null,
            agentId: $agentId,
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            force: (bool) $this->option('force'),
        );
        $total = $backfill->total();

        if ($total === 0) {
            $this->info('Nothing to enrich.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $this->info(sprintf('Enriching %d product(s) %s.', $total, $sync ? 'inline' : 'via the product-enrichment queue'));

        $bar = $this->output->createProgressBar($total);
        $failed = 0;
        $inline = null;

        if ($sync) {
            $inline = function (Products $product) use ($agentId, &$failed): void {
                $failed += $this->enrichInline($product, $agentId);
            };
        }

        $processed = $backfill->execute($inline, fn () => $bar->advance());

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Processed %d product(s).', $processed));

        if ($backfill->skippedUnchanged() > 0) {
            $this->info(sprintf('Skipped %d already enriched and unchanged (use --force to redo them).', $backfill->skippedUnchanged()));
        }

        if ($failed > 0) {
            $this->warn(sprintf('%d failed inline — see the log for details.', $failed));
        }

        if ($sync) {
            foreach ($this->usage->totals() as $line) {
                $this->info($line);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return int 1 when an inline run failed, 0 otherwise
     */
    private function enrichInline(Products $product, ?int $agentId): int
    {
        try {
            $result = new EnrichProductAction($product, $agentId)->execute();

            // --sync exists so a human can judge blurb quality on a handful before
            // paying for the whole catalog. Printing it is the whole point.
            $this->newLine();
            $this->line("<info>{$product->name}</info>");
            $this->line('  ' . $this->describeResult($result));

            if (isset($result['usage'])) {
                $this->line('  <comment>' . $this->usage->record($result['usage']) . '</comment>');
            }

            return 0;
        } catch (Throwable $e) {
            // One bad product must not abort a catalog-wide run.
            report($e);
            $this->newLine();
            $this->warn("Product {$product->getId()}: {$e->getMessage()}");

            return 1;
        }
    }

    private function describeResult(array $result): string
    {
        if (($result['blurb'] ?? '') !== '') {
            return $result['blurb'];
        }

        $status = $result['status'] ?? 'unknown';

        return isset($result['reason']) ? "({$status} — {$result['reason']})" : "({$status})";
    }
}
