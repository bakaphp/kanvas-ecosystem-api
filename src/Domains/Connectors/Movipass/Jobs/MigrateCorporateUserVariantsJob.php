<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\RelinkVariantsToCompanyWarehouseAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;

class MigrateCorporateUserVariantsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public function __construct(
        public readonly Apps $app,
        public readonly int $userId,
        public readonly int $sourceCompanyId,
        public readonly int $targetCompanyId,
    ) {
        $this->onQueue('workflow');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        // Both companies are in scope so a retry still repairs a run that moved
        // ownership but died before relinking the warehouses.
        $products = Products::where('users_id', $this->userId)
            ->whereIn('companies_id', [$this->sourceCompanyId, $this->targetCompanyId])
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $migratedProducts = 0;
        $migratedVariants = 0;

        foreach ($products as $product) {
            if ((int) $product->companies_id !== $this->sourceCompanyId) {
                continue;
            }

            $migratedVariants += Variants::where('products_id', $product->getId())
                ->where('companies_id', $this->sourceCompanyId)
                ->update(['companies_id' => $this->targetCompanyId]);

            $product->companies_id = $this->targetCompanyId;
            $product->saveQuietly();

            $migratedProducts++;
        }

        $relinked = new RelinkVariantsToCompanyWarehouseAction(
            app: $this->app,
            company: Companies::getById($this->targetCompanyId),
            user: Users::getById($this->userId),
        )->execute(
            Variants::whereIn('products_id', $products->pluck('id'))->get()
        );

        Log::info('Movipass corporate migration completed', [
            'user_id' => $this->userId,
            'source_company_id' => $this->sourceCompanyId,
            'target_company_id' => $this->targetCompanyId,
            'products' => $migratedProducts,
            'variants' => $migratedVariants,
            'warehouse_links_relinked' => $relinked,
        ]);
    }
}
