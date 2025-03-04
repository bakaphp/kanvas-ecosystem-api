<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Inventory\Products\Models\Products;

class SyncVariantAttributeForProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Number of variants to process per chunk
    protected int $chunkSize = 200;

    public function __construct(
        protected Products $product
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Process variants in chunks
        $this->product->variants()
            ->where('is_deleted', 0)
            ->chunkById($this->chunkSize, function ($variants) {
                foreach ($variants as $variant) {
                    $variant->syncAttributesFromProductType();
                }
            });
    }
}
