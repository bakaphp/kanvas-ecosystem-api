<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Models\RecommendationImpression;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;

class LogRecommendationImpressionAction
{
    /**
     * @param list<int> $productIds in the order they were shown; rank position
     *                              is half the signal, so order is preserved
     */
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly string $recommendationUuid,
        private readonly string $query,
        private readonly array $productIds,
        private readonly ?int $usersId = null,
        private readonly ?string $sessionId = null,
        private readonly ?string $engine = null,
        private readonly ?ProductIntent $intent = null,
    ) {
    }

    public function execute(): RecommendationImpression
    {
        // updateOrCreate, not create: the id comes from the client and rides its
        // cache, so the same search can legitimately be reported twice. One row
        // per search is the point — a duplicate must not blow up the queued job.
        return RecommendationImpression::updateOrCreate(
            ['recommendation_uuid' => $this->recommendationUuid],
            [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->usersId,
            'session_id' => $this->sessionId,
            'query_raw' => $this->query,
            // Normalized alongside the raw text so the popular / no-hit report
            // groups "Menos de $50" and "menos de 50" as one query.
            'query_normalized' => mb_substr(IntentLexiconService::normalize($this->query), 0, 255),
            'intent' => $this->intent === null ? null : [
                'min_price' => $this->intent->minPrice,
                'max_price' => $this->intent->maxPrice,
                'in_stock_only' => $this->intent->inStockOnly,
            ],
            'product_ids' => $this->productIds,
            'results_count' => count($this->productIds),
                'engine' => $this->engine,
            ],
        );
    }
}
