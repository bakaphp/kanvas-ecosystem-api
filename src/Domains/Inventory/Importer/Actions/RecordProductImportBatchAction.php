<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Importer\Enums\ProductImportRunStatusEnum;
use Kanvas\Inventory\Importer\Models\ProductImportRun;

/**
 * Marks one batch of variant SKUs as seen by the company's open run, synchronously, before the
 * batch is imported. This is what lets finish sweep without waiting for the queue: by the time it
 * runs, every SKU the feed sent is already stamped, whether or not its import has run — or failed.
 */
class RecordProductImportBatchAction
{
    /**
     * @param list<string|int> $skus variant SKUs
     */
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected array $skus,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $products importProduct input rows
     *
     * @return list<string>
     */
    public static function variantSkusOf(array $products): array
    {
        return array_merge(...array_map(
            fn (array $product) => array_column($product['variants'] ?? [], 'sku'),
            $products
        ));
    }

    public function execute(): ProductImportRun
    {
        $skus = collect($this->skus)
            ->map(fn (mixed $sku) => trim((string) $sku))
            ->filter(fn (string $sku) => $sku !== '')
            ->unique()
            ->values();

        $run = $this->openRun();

        $skus->chunk(500)->each(
            fn (Collection $chunk) => DB::connection('inventory')
                ->table('products_variants_channels as pvc')
                ->join('products_variants as pv', 'pv.id', '=', 'pvc.products_variants_id')
                ->where('pv.apps_id', $this->app->getId())
                ->where('pv.companies_id', $this->company->getId())
                ->where('pv.is_deleted', 0)
                ->whereIn('pv.sku', $chunk->all())
                ->update(['pvc.last_import_run_id' => $run->getId()])
        );

        $run->increment('batches_count', 1, [
            'skus_count' => DB::raw('skus_count + ' . $skus->count()),
            'last_activity_at' => now(),
        ]);

        return $run;
    }

    /**
     * Serialized per company: a script sending batches back to back must never open two runs.
     */
    protected function openRun(): ProductImportRun
    {
        $lockKey = 'product-import-run:' . $this->app->getId() . ':' . $this->company->getId();

        return Cache::lock($lockKey, 10)->block(5, function (): ProductImportRun {
            $run = ProductImportRun::latestOpen($this->app, $this->company);

            if ($run !== null && ! $run->isStale()) {
                return $run;
            }

            $run?->update([
                'status' => ProductImportRunStatusEnum::ABANDONED->value,
                'finished_at' => now(),
            ]);

            return ProductImportRun::create([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'users_id' => $this->user->getId(),
                'status' => ProductImportRunStatusEnum::OPEN->value,
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        });
    }
}
