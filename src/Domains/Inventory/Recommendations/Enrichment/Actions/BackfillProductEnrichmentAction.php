<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\CustomFieldEnum;
use Kanvas\Inventory\Recommendations\Enrichment\Jobs\EnrichProductJob;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentFingerprintService;

/**
 * Walks the published catalog and queues one enrichment job per product. The backfill command and
 * the discovery enable flow both go through here so they select the same products.
 */
class BackfillProductEnrichmentAction
{
    private int $skippedUnchanged = 0;

    public function __construct(
        private readonly Apps $app,
        private readonly ?Companies $company = null,
        private readonly ?int $agentId = null,
        private readonly ?int $limit = null,
        private readonly bool $force = false,
    ) {
    }

    /** Products the last execute() left alone because nothing the prompt reads had changed. */
    public function skippedUnchanged(): int
    {
        return $this->skippedUnchanged;
    }

    public function total(): int
    {
        $count = $this->query()->count();

        return $this->limit ? min($this->limit, $count) : $count;
    }

    /**
     * @param callable(Products): void|null $inline runs in place of queueing, for a dry run on a few products
     * @param callable(): void|null $afterEach
     *
     * @return int products handled
     */
    public function execute(?callable $inline = null, ?callable $afterEach = null): int
    {
        $total = $this->total();
        $processed = 0;

        $this->query()->chunkById(100, function ($products) use ($inline, $afterEach, $total, &$processed): bool {
            foreach ($products as $product) {
                if ($processed >= $total) {
                    return false;
                }

                if ($this->force) {
                    $product->set(CustomFieldEnum::ENRICHMENT_HASH->value, null);
                }

                if ($inline !== null) {
                    $inline($product);
                } elseif (! $this->force && ProductEnrichmentFingerprintService::isCurrent($product)) {
                    $this->skippedUnchanged++;
                } else {
                    EnrichProductJob::dispatch($this->app, $product, $this->agentId);
                }

                if ($afterEach !== null) {
                    $afterEach();
                }

                $processed++;
            }

            return true;
        });

        return $processed;
    }

    private function query(): Builder
    {
        $query = Products::fromApp($this->app)
            ->notDeleted()
            ->where('is_published', 1)
            ->with(['categories', 'variants']);

        if ($this->company !== null) {
            $query->fromCompany($this->company);
        }

        return $query;
    }
}
