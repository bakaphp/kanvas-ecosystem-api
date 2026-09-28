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
 * Expands the agent's id-only output into the full payload. The agent used to
 * re-emit every field, which dominated its ~20s reply. The DB read is also the
 * tenant boundary: a hallucinated or cross-tenant id just fails to resolve.
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
            // Not fromCompany(): it degrades to `companies_id > 0` under an AppKey binding.
            $query->where('companies_id', $this->company->getId());
        }

        return $query->get()->keyBy(fn (Products $product): int => $product->getId())->all();
    }

    /** A wrong variant id is still a valid recommendation — fall back, don't drop. */
    private function resolveVariant(Products $product, int $variantId): ?Variants
    {
        $variant = $variantId > 0
            ? $product->variants->firstWhere('id', $variantId)
            : null;

        return $variant ?? $product->variants->first();
    }
}
