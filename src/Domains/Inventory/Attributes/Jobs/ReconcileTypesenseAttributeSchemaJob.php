<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Jobs;

use Baka\Search\TypesenseSchemaReconciler;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Throwable;

class ReconcileTypesenseAttributeSchemaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
    ) {
        $this->onQueue('scout');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $product = new Products();
        $product->apps_id = $this->app->getId();

        try {
            TypesenseSchemaReconciler::forApp($this->app)->reconcile($product);
        } catch (Throwable) {
        }
    }
}
