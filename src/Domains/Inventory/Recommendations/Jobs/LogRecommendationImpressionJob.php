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

/**
 * Off the request path. Intent arrives as ProductIntent::summary(), a plain
 * array, not the DTO — a Spatie Data object on a queued job is a documented foot-gun here.
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
     * @param array<string, mixed> $intent ProductIntent::summary()
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
        public readonly array $intent = [],
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
            intent: $this->intent === [] ? null : $this->intent,
        )->execute();
    }
}
