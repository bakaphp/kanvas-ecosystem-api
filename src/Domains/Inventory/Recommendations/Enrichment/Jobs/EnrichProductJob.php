<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentFingerprintService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoverySettings;

/**
 * Enriches one product off the request path: the backfill, one job per product so a single LLM
 * failure costs one product, and the automatic trigger from the product actions.
 *
 * Unique until processing starts, not until it ends: an import that touches a product several times
 * queues one job, while an edit that lands mid-run still queues another, since the running job may
 * have read the old state. The hash gate makes that extra run free when nothing relevant changed.
 */
class EnrichProductJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly Apps $app,
        public readonly Products $product,
        public readonly ?int $agentId = null,
    ) {
        $this->onQueue('product-enrichment');
        // Imports call the product actions inside their own transaction and attach categories afterwards.
        $this->afterCommit();
    }

    /**
     * Most product saves (price, stock, publish state, a re-save) change nothing the
     * prompt reads, so the fingerprint is checked here, before a job exists — not
     * only inside the job, where an unchanged product still costs a queue slot.
     */
    public static function dispatchIfEnabled(Products $product): void
    {
        if (! ProductDiscoverySettings::for($product->app, $product->company)->enrichmentExplicitlyOn()) {
            return;
        }

        if (ProductEnrichmentFingerprintService::isCurrent($product)) {
            return;
        }

        self::dispatch($product->app, $product);
    }

    public function uniqueId(): string
    {
        return (string) $this->product->getId();
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new EnrichProductAction(
            $this->product,
            $this->agentId
        )->execute();
    }
}
