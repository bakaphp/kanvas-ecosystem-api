<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Inventory\Products\Models\Products;

class IndexProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Products $product
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Ensure all relationships are loaded before indexing
        $this->product->load(['files', 'categories', 'attributes', 'variants']);

        // Re-index product in Algolia
        $this->product->searchable();
    }
}
