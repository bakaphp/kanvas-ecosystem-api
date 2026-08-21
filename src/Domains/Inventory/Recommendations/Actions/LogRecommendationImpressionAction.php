<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Log;
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
        $normalizedQuery = mb_substr(IntentLexiconService::normalize($this->query), 0, 255);
        $existing = RecommendationImpression::query()
            ->where('recommendation_uuid', $this->recommendationUuid)
            ->first();

        // Same id, DIFFERENT query means the client is reusing one id across
        // searches — one per page load instead of one per search. Overwriting
        // would delete the earlier search, so the first report wins and the
        // misuse is logged rather than silently losing history.
        if ($existing !== null && $existing->query_normalized !== $normalizedQuery) {
            Log::warning('Recommendation request_id reused across different queries', [
                'request_id' => $this->recommendationUuid,
                'recorded_query' => $existing->query_normalized,
                'ignored_query' => $normalizedQuery,
                'apps_id' => $this->app->getId(),
            ]);

            return $existing;
        }

        // Otherwise the same search was reported twice — a cached result carries
        // its id — so it collapses to one row instead of hitting the unique index.
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
            'query_normalized' => $normalizedQuery,
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
