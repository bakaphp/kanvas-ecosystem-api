<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ProductEnrichment;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ProductEnrichment\Actions\EnrichProductAction;
use Kanvas\Connectors\ProductEnrichment\Enums\CustomFieldEnum;
use Kanvas\Connectors\ProductEnrichment\Jobs\EnrichProductJob;
use Kanvas\Connectors\ProductEnrichment\Services\ProductEnrichmentAgentService;
use Kanvas\Inventory\Products\Models\Products;
use Throwable;

/**
 * Backfills enrichment across an existing catalog.
 *
 * The workflow activity only fires on product create/update, so a catalog that
 * predates the connector never gets a blurb — and without one, semantic search
 * has nothing to embed.
 */
class BackfillProductEnrichmentCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:backfill-product-enrichment
                            {app_id : App whose catalog to enrich}
                            {--company_id= : Limit to one company, otherwise the whole app}
                            {--agent_id= : Enrichment agent to use, defaults to the app default}
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

        $agentId = $this->option('agent_id') !== null ? (int) $this->option('agent_id') : null;

        // Resolve up front so a misconfigured app fails immediately rather than
        // after queueing a job per product that each throw the same error.
        try {
            ProductEnrichmentAgentService::resolveAgent($app, $agentId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $query = $this->buildQuery($app);
        $total = (int) $this->option('limit') ?: $query->clone()->count();

        if ($total === 0) {
            $this->info('Nothing to enrich.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $this->info(sprintf('Enriching %d product(s) %s.', $total, $sync ? 'inline' : 'via the product-enrichment queue'));

        $bar = $this->output->createProgressBar($total);
        $processed = 0;
        $failed = 0;

        $query->chunkById(100, function ($products) use ($app, $agentId, $sync, $total, $bar, &$processed, &$failed): bool {
            foreach ($products as $product) {
                if ($processed >= $total) {
                    return false;
                }

                if ($this->option('force')) {
                    $product->set(CustomFieldEnum::ENRICHMENT_HASH->value, null);
                }

                $failed += $this->dispatchOne($app, $product, $agentId, $sync);

                $processed++;
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Processed %d product(s).', $processed));

        if ($failed > 0) {
            $this->warn(sprintf('%d failed inline — see the log for details.', $failed));
        }

        return self::SUCCESS;
    }

    /**
     * @return int 1 when an inline run failed, 0 otherwise
     */
    private function dispatchOne(Apps $app, Products $product, ?int $agentId, bool $sync): int
    {
        if (! $sync) {
            EnrichProductJob::dispatch($app, $product, $agentId);

            return 0;
        }

        try {
            $result = new EnrichProductAction($product, $agentId)->execute();

            // --sync exists so a human can judge blurb quality on a handful before
            // paying for the whole catalog. Printing it is the whole point.
            $this->newLine();
            $this->line("<info>{$product->name}</info>");
            $this->line('  ' . (($result['blurb'] ?? '') ?: '(' . ($result['status'] ?? 'unknown') . ($result['reason'] ?? '' ? ' — ' . $result['reason'] : '') . ')'));

            return 0;
        } catch (Throwable $e) {
            // One bad product must not abort a catalog-wide run.
            report($e);
            $this->newLine();
            $this->warn("Product {$product->getId()}: {$e->getMessage()}");

            return 1;
        }
    }

    private function buildQuery(Apps $app): Builder
    {
        $query = Products::fromApp($app)
            ->notDeleted()
            ->where('is_published', 1)
            ->with(['categories', 'variants']);

        if ($this->option('company_id') !== null) {
            $query->fromCompany(Companies::getById((int) $this->option('company_id')));
        }

        return $query;
    }
}
