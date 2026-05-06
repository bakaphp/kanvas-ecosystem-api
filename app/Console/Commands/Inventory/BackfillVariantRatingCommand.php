<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Actions\SyncVariantRatingAction;
use Kanvas\Inventory\Variants\Models\Variants;

class BackfillVariantRatingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-inventory:backfill-variant-rating-from-category {app_id}';

    protected $description = 'Recompute Variant.rating from product category weights for an app';

    public function handle(): int
    {
        $appId = (int) $this->argument('app_id');
        $app = Apps::find($appId);

        if (! $app) {
            $this->error("App {$appId} not found");

            return self::FAILURE;
        }

        $this->overwriteAppService($app);

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $started = microtime(true);

        Variants::fromApp($app)
            ->where('is_deleted', 0)
            ->with('product.categories')
            ->chunkById(500, function ($variants) use (&$processed, &$updated, &$skipped, $started): void {
                foreach ($variants as $variant) {
                    $result = new SyncVariantRatingAction($variant)->execute();
                    $processed++;

                    match ($result['status'] ?? null) {
                        'updated' => $updated++,
                        default => $skipped++,
                    };

                    if ($processed % 100 === 0) {
                        $elapsed = round(microtime(true) - $started, 1);
                        $this->info("Processed {$processed} variants ({$elapsed}s)");
                    }
                }
            });

        $elapsed = round(microtime(true) - $started, 1);
        $this->info("Done. Processed={$processed} Updated={$updated} Skipped={$skipped} Elapsed={$elapsed}s");

        return self::SUCCESS;
    }
}
