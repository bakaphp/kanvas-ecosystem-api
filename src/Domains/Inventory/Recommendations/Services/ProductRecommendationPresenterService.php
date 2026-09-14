<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Throwable;

/**
 * The one `{ product, variants[] }` shape every path renders through, so the
 * payload cannot drift between Typesense, SQL and the agent.
 */
class ProductRecommendationPresenterService
{
    private const int MAX_FILES = 10;

    private bool $channelResolved = false;
    private ?int $defaultChannelId = null;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    /** No variants means nothing to render downstream. */
    public function product(Products $product): ?array
    {
        $variants = $product->variants
            ->map(fn (Variants $variant) => $this->variant($variant))
            ->values();

        if ($variants->isEmpty()) {
            return null;
        }

        return [
            'product' => $this->productAttributes($product),
            'variants' => $variants->all(),
        ];
    }

    public function productAttributes(Products $product): array
    {
        return [
            'id' => $product->getId(),
            'slug' => $product->slug,
            'name' => $product->name,
            'files' => $this->files($product),
            'categories' => $product->categories
                ->map(fn (Categories $category) => [
                    'id' => $category->getId(),
                    'name' => $category->name,
                ])
                ->all(),
        ];
    }

    public function variant(Variants $variant): array
    {
        return [
            'id' => $variant->getId(),
            'slug' => $variant->slug,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'description' => $variant->description,
            'attributes' => array_map(
                fn (array $attribute) => [
                    'id' => $attribute['id'] ?? null,
                    'name' => $attribute['name'] ?? null,
                    'value' => $this->stringifyValue($attribute['value'] ?? null),
                ],
                $variant->visibleAttributes(),
            ),
            'channel' => $this->channel($variant),
            'files' => $this->files($variant),
        ];
    }

    /** Out-of-stock and unpriced stay in, flagged unavailable, rather than vanishing. */
    public function channel(Variants $variant): array
    {
        $quantity = $variant->getTotalQuantity();
        $defaultChannelId = $this->resolveDefaultChannelId();

        if ($defaultChannelId === null) {
            return $this->emptyChannel($quantity);
        }

        // Eager-loaded by callers, so this stays O(1) instead of a query per variant.
        $channelInfo = $variant->variantChannels->firstWhere('channels_id', $defaultChannelId);

        if (! $channelInfo) {
            return $this->emptyChannel($quantity);
        }

        $price = (float) $channelInfo->price;
        $discounted = (float) $channelInfo->discounted_price;

        return [
            'price' => $price,
            'discounted_price' => $discounted,
            'is_on_sale' => $discounted > 0 && $discounted < $price,
            'is_available' => $price > 0 && $quantity > 0,
            'quantity' => $quantity,
        ];
    }

    public function files(Products|Variants $entity): array
    {
        $files = [];

        foreach ($entity->getFiles()->take(self::MAX_FILES) as $file) {
            $files[] = [
                'id' => $file->getId(),
                'url' => $file->url,
                'name' => $file->name,
            ];
        }

        return $files;
    }

    public function resolveDefaultChannelId(): ?int
    {
        if ($this->channelResolved) {
            return $this->defaultChannelId;
        }

        $this->channelResolved = true;

        try {
            $this->defaultChannelId = Channels::getDefault($this->company, $this->app)->getId();
        } catch (Throwable) {
            $this->defaultChannelId = null;
        }

        return $this->defaultChannelId;
    }

    private function emptyChannel(int $quantity = 0): array
    {
        return [
            'price' => null,
            'discounted_price' => null,
            'is_on_sale' => false,
            'is_available' => false,
            'quantity' => $quantity,
        ];
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
