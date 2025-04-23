<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\ConfigEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use PHPShopify\ShopifySDK;

class ShopifyImageService
{
    protected ShopifySDK $shopifySdk;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
    ) {
        $this->shopifySdk = Client::getInstance($app, $company, $region);
    }

    public function processEntityImage(Products|Variants $entity): int
    {
        $totalUploaded = 0;
        if (!$entity->files->count()) {
            return $totalUploaded;
        }

        return $entity->files->sortBy('id')->reduce(function ($totalUploaded, $file, $index) use ($entity) {
            $method = $entity instanceof Products ? 'addImage' : 'addVariantImage';
            $position = $index + 1;

            return $totalUploaded + ($this->$method($entity, $file->url, $position) ? 1 : 0);
        }, 0);
    }

    public function addImage(
        Products $product,
        string $imageUrl,
        int $position = 1,
        ?string $partNumber = null
    ): ?array {
        try {
            $shopifyProduct = $this->shopifySdk->Product($product->getShopifyId($this->region, $partNumber));

            $fileName = pathinfo($imageUrl, PATHINFO_BASENAME);
            $alt = $product->name;
            // Check if the image already exists
            $existingImages = $shopifyProduct->Image->get();
            foreach ($existingImages as $image) {
                if ($image['alt'] == $fileName || $image['alt'] === $alt || $image['src'] === $imageUrl) {
                    return null; // Image already exists, no need to upload
                }
            }

            // Add the image if it does not exist
            $response = $shopifyProduct->Image->post(['src' => $imageUrl, 'alt' => $alt]);

            return $response;
        } catch (Exception $e) {
            //stupid , but for now we return false if the image is not found
            if (Str::contains($e->getMessage(), 'Could not download image')) {
                return [];
            }

            throw new Exception('Failed to add image to Shopify product: '.$e->getMessage());
        }
    }

    public function addVariantImage(Variants $variant, string $imageUrl, int $position = 1): bool
    {
        try {
            $variantLimit = $this->app->get(ConfigEnum::VARIANT_LIMIT->value, 99);
            $partNumber = ShopifyInventoryService::getProductPartForVariant($variant->product, $variant, $variantLimit);

            $shopifyProduct = $this->shopifySdk->Product($variant->product->getShopifyId($this->region, $partNumber));
            $shopifyVariant = $shopifyProduct->Variant($variant->getShopifyId($this->region));

            // Check if the image already exists
            $existingImages = $shopifyProduct->Image->get();
            $fileName = pathinfo($imageUrl, PATHINFO_BASENAME);
            $alt = $variant->product->name.' - '.$variant->name;
            $existingImageId = null;

            foreach ($existingImages as $image) {
                if ($image['alt'] == $fileName || $image['alt'] === $alt || $image['src'] === $imageUrl) {
                    $existingImageId = $image['id'];

                    break;
                }
            }

            // If image doesn't exist, upload it first
            if ($existingImageId === null) {
                $imageResponse = $shopifyProduct->Image->post([
                    'src'      => $imageUrl,
                    'alt'      => $alt,
                    'position' => $position,
                ]);

                if (!isset($imageResponse['id'])) {
                    return false;
                }

                $existingImageId = $imageResponse['id'];
            }

            // Associate the image with the variant
            if ($existingImageId) {
                $shopifyVariant->put(['image_id' => $existingImageId]);

                return true;
            }

            return false;
        } catch (Exception $e) {
            //stupid , but for now we return false if the image is not found
            if (Str::contains($e->getMessage(), 'Could not download image')) {
                return false;
            }

            throw new Exception('Failed to add image to Shopify variant: '.$e->getMessage());
        }
    }
}
