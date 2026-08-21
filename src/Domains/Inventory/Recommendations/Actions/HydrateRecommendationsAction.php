<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Services\ProductRecommendationPresenterService;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;

/**
 * Expands the agent's id-only structured output into the full product payload.
 *
 * The agent used to re-emit every product field verbatim into its schema, which
 * meant thousands of output tokens per reply and dominated the ~20s response
 * time. It now returns ids and a reason; the payload is rebuilt here from the
 * DB, which is also the tenant boundary — a hallucinated or cross-tenant id
 * simply fails to resolve and is dropped.
 */
class HydrateRecommendationsAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    /**
     * @param array<int, array{product_id?: mixed, variant_id?: mixed, reason?: mixed}> $recommendations
     *
     * @return array<int, array{product: array, variant: array, reason: string}>
     */
    public function execute(array $recommendations): array
    {
        $productIds = [];

        foreach ($recommendations as $recommendation) {
            if (isset($recommendation['product_id'])) {
                $productIds[] = (int) $recommendation['product_id'];
            }
        }

        if ($productIds === []) {
            return [];
        }

        $products = $this->fetchProducts($productIds);
        $presenter = new ProductRecommendationPresenterService($this->app, $this->company);

        $hydrated = [];

        foreach ($recommendations as $recommendation) {
            $product = $products[(int) ($recommendation['product_id'] ?? 0)] ?? null;

            if ($product === null) {
                continue;
            }

            $variant = $this->resolveVariant($product, (int) ($recommendation['variant_id'] ?? 0));

            if ($variant === null) {
                continue;
            }

            $hydrated[] = [
                'product' => $presenter->productAttributes($product),
                'variant' => $presenter->variant($variant),
                'reason' => (string) ($recommendation['reason'] ?? ''),
            ];
        }

        return $hydrated;
    }

    /**
     * @param array<int, int> $productIds
     *
     * @return array<int, Products> keyed by product id
     */
    private function fetchProducts(array $productIds): array
    {
        $query = Products::fromApp($this->app)
            ->notDeleted()
            ->where('is_published', 1)
            ->whereIn('id', array_unique($productIds))
            ->with(['categories', 'variants.variantChannels.productVariantWarehouse']);

        if (! (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            // Deliberately not `fromCompany()`: that scope degrades to
            // `companies_id > 0` whenever an AppKey is bound without a branch,
            // which would let ids from any company in the app hydrate. This is
            // the last boundary between a model-produced id and the response,
            // so it stays pinned to the caller's company.
            $query->where('companies_id', $this->company->getId());
        }

        return $query->get()->keyBy(fn (Products $product): int => $product->getId())->all();
    }

    /**
     * Falls back to the product's first variant when the agent names one that
     * does not belong to it — the recommendation is still valid, only the
     * variant choice was wrong.
     */
    private function resolveVariant(Products $product, int $variantId): ?Variants
    {
        $variant = $variantId > 0
            ? $product->variants->firstWhere('id', $variantId)
            : null;

        return $variant ?? $product->variants->first();
    }
}
