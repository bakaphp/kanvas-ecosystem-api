<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Recommendations\Actions\LogRecommendationImpressionAction;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;

/**
 * Off the request path. Intent arrives as loose scalars, not the ProductIntent
 * DTO — a Spatie Data object on a queued job is a documented foot-gun here.
 */
class LogRecommendationImpressionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * @param list<int> $productIds
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $recommendationUuid,
        public readonly string $query,
        public readonly array $productIds,
        public readonly ?int $usersId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $engine = null,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly bool $inStockOnly = false,
    ) {
        $this->onQueue('product-discovery');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new LogRecommendationImpressionAction(
            app: $this->app,
            company: $this->company,
            recommendationUuid: $this->recommendationUuid,
            query: $this->query,
            productIds: $this->productIds,
            usersId: $this->usersId,
            sessionId: $this->sessionId,
            engine: $this->engine,
            intent: new ProductIntent(
                sentence: $this->query,
                minPrice: $this->minPrice,
                maxPrice: $this->maxPrice,
                inStockOnly: $this->inStockOnly,
            ),
        )->execute();
    }
}
