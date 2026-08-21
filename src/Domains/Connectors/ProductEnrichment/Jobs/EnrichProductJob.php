<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ProductEnrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Products\Models\Products;

/**
 * Enriches one product off the request path.
 *
 * The workflow activity covers products as they are created or edited; this is
 * what a catalog that predates enrichment goes through, one job per product so a
 * single LLM failure costs one product rather than the whole run.
 */
class EnrichProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Products $product,
        public readonly ?int $agentId = null,
    ) {
        $this->onQueue('product-enrichment');
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
