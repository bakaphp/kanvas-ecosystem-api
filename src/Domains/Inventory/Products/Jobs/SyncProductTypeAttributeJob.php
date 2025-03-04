<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class SyncProductTypeAttributeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Number of products to process per chunk
    protected int $chunkSize = 100;

    public function __construct(
        protected ProductsTypes $productType
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $productTypeId = $this->productType->getId();

        // Process products in chunks to avoid memory issues
        Products::where('products_types_id', $productTypeId)
            ->where('is_deleted', 0)
            ->chunkById($this->chunkSize, function ($products) {
                foreach ($products as $product) {
                    // Sync product attributes
                    $product->syncAttributesFromProductType('product');

                    // Dispatch a separate job for each product's variants
                    // This prevents memory issues with products that have many variants
                    //dispatch(new SyncVariantAttributesForProduct($product));
                    SyncProductTypeAttributeJob::dispatch($product);
                }
            });
    }
}
