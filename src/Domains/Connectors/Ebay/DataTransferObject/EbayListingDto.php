<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ebay\DataTransferObject;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

final class EbayListingDto
{
    /**
     * @param list<string> $images
     * @param array<string, mixed> $dimensions
     */
    public function __construct(
        public string $sku,
        public string $title,
        public string $description,
        public float $price,
        public string $condition,
        public ?float $weight,
        public array $dimensions,
        public array $images,
        public string $categoryId,
        public string $fulfillmentPolicyId,
        public ?string $paymentPolicyId,
        public ?string $returnPolicyId,
        public int $quantity,
        public string $currency,
        public ?string $merchantLocationKey,
    ) {
    }

    /** @param array<string, mixed> $params */
    public static function fromEntity(Products|Variants $entity, array $params = []): self
    {
        $product = $entity instanceof Variants ? $entity->product : $entity;
        $title = (string) ($params['title'] ?? $entity->name ?? $product->name ?? '');
        $description = (string) ($params['description'] ?? $entity->description ?? $product->description ?? '');
        $price = $params['price'] ?? self::entityPrice($entity);
        $categoryId = (string) ($params['category_id'] ?? '');
        $fulfillmentPolicyId = (string) ($params['fulfillment_policy_id'] ?? '');

        if ($title === '' || ! is_numeric($price) || (float) $price <= 0) {
            throw new ValidationException('eBay listing requires a title and a positive price');
        }

        if ($categoryId === '' || $fulfillmentPolicyId === '') {
            throw new ValidationException('eBay category_id and fulfillment_policy_id are required');
        }

        $sku = (string) ($params['sku'] ?? ($entity instanceof Variants ? $entity->sku : null) ?? 'kanvas-' . $entity->getId());
        $images = $params['images'] ?? self::entityImages($entity, $product);
        $images = is_array($images) ? $images : [];
        $dimensions = $params['dimensions'] ?? [];
        $weight = $params['weight'] ?? $entity->weight ?? $product->weight ?? null;

        return new self(
            sku: $sku,
            title: mb_substr($title, 0, 80),
            description: $description,
            price: (float) $price,
            condition: (string) ($params['condition'] ?? 'NEW'),
            weight: is_numeric($weight) ? (float) $weight : null,
            dimensions: is_array($dimensions) ? $dimensions : [],
            images: array_values(array_filter($images, 'is_string')),
            categoryId: $categoryId,
            fulfillmentPolicyId: $fulfillmentPolicyId,
            paymentPolicyId: isset($params['payment_policy_id']) ? (string) $params['payment_policy_id'] : null,
            returnPolicyId: isset($params['return_policy_id']) ? (string) $params['return_policy_id'] : null,
            quantity: max(1, (int) ($params['quantity'] ?? 1)),
            currency: (string) ($params['currency'] ?? 'USD'),
            merchantLocationKey: isset($params['merchant_location_key']) ? (string) $params['merchant_location_key'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function inventoryItem(): array
    {
        $item = [
            'product' => [
                'title' => $this->title,
                'description' => $this->description,
                'imageUrls' => $this->images,
            ],
            'condition' => $this->condition,
            'availability' => [
                'shipToLocationAvailability' => ['quantity' => $this->quantity],
            ],
        ];

        if ($this->weight !== null) {
            $item['packageWeightAndSize']['weight'] = ['value' => $this->weight, 'unit' => 'POUND'];
        }

        foreach (['length', 'width', 'height'] as $dimension) {
            if (isset($this->dimensions[$dimension]) && is_numeric($this->dimensions[$dimension])) {
                $item['packageWeightAndSize']['dimensions'][$dimension] = (float) $this->dimensions[$dimension];
            }
        }

        if (isset($item['packageWeightAndSize']['dimensions'])) {
            $item['packageWeightAndSize']['dimensionUnit'] = 'INCH';
        }

        return $item;
    }

    /** @return array<string, mixed> */
    public function offer(): array
    {
        $policies = ['fulfillmentPolicyId' => $this->fulfillmentPolicyId];
        if ($this->paymentPolicyId !== null) {
            $policies['paymentPolicyId'] = $this->paymentPolicyId;
        }
        if ($this->returnPolicyId !== null) {
            $policies['returnPolicyId'] = $this->returnPolicyId;
        }

        $offer = [
            'sku' => $this->sku,
            'marketplaceId' => 'EBAY_US',
            'format' => 'FIXED_PRICE',
            'availableQuantity' => $this->quantity,
            'categoryId' => $this->categoryId,
            'listingDescription' => $this->description,
            'listingPolicies' => $policies,
            'pricingSummary' => [
                'price' => ['value' => number_format($this->price, 2, '.', ''), 'currency' => $this->currency],
            ],
        ];

        if ($this->merchantLocationKey !== null) {
            $offer['merchantLocationKey'] = $this->merchantLocationKey;
        }

        return $offer;
    }

    private static function entityPrice(Products|Variants $entity): ?float
    {
        if ($entity instanceof Variants) {
            return $entity->defaultChannelPrice();
        }

        return $entity->lowestChannelPrice();
    }

    /** @return list<string> */
    private static function entityImages(Products|Variants $entity, Products $product): array
    {
        foreach ([$entity, $product] as $model) {
            $image = $model->getAttribute('image_url') ?? $model->getAttribute('image');
            if (is_string($image) && $image !== '') {
                return [$image];
            }

            $images = $model->getAttribute('images');
            if (is_array($images)) {
                return array_values(array_filter($images, 'is_string'));
            }

            if (method_exists($model, 'getFiles')) {
                return $model->getFiles()
                    ->map(static fn ($file): mixed => $file->url)
                    ->filter(static fn (mixed $url): bool => is_string($url) && $url !== '')
                    ->values()
                    ->all();
            }
        }

        return [];
    }
}
