<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class MigrateCorporateUserVariantsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public string $queue = 'workflow';

    public function __construct(
        public readonly int $userId,
        public readonly int $sourceCompanyId,
        public readonly int $targetCompanyId,
    ) {
    }

    public function handle(): void
    {
        $products = Products::where('users_id', $this->userId)
            ->where('companies_id', $this->sourceCompanyId)
            ->get();

        $migratedProducts = 0;
        $migratedVariants = 0;

        foreach ($products as $product) {
            $variantUpdates = Variants::where('products_id', $product->getId())
                ->where('companies_id', $this->sourceCompanyId)
                ->update(['companies_id' => $this->targetCompanyId]);

            $product->companies_id = $this->targetCompanyId;
            $product->saveQuietly();

            $migratedProducts++;
            $migratedVariants += $variantUpdates;
        }

        Log::info('Movipass corporate migration completed', [
            'user_id' => $this->userId,
            'source_company_id' => $this->sourceCompanyId,
            'target_company_id' => $this->targetCompanyId,
            'products' => $migratedProducts,
            'variants' => $migratedVariants,
        ]);
    }
}
