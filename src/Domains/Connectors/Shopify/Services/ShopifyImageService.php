<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Kanvas\Connectors\Shopify\Client;
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
        if (! $entity->files->count()) {
            return $totalUploaded;
        }

        return $entity->files->reduce(function ($totalUploaded, $file) use ($entity) {
            $method = $entity instanceof Products ? 'addImage' : 'addVariantImage';
            return $totalUploaded + ($this->$method($entity, $file->url) ? 1 : 0);
        }, 0);
    }

    public function addImage(Products $product, string $imageUrl): ?array
    {
        try {
            $shopifyProduct = $this->shopifySdk->Product($product->getShopifyId($this->region));

            $fileName = pathinfo($imageUrl, PATHINFO_BASENAME);
            // Check if the image already exists
            $existingImages = $shopifyProduct->Image->get();
            foreach ($existingImages as $image) {
                if ($image['alt'] === $fileName) {
                    return null; // Image already exists, no need to upload
                }
            }

            // Add the image if it does not exist
            $response = $shopifyProduct->Image->post(['src' => $imageUrl, 'alt' => $fileName]);

            return $response;
        } catch (Exception $e) {
            //stupid , but for now we return false if the image is not found
            if (Str::contains($e->getMessage(), 'Could not download image')) {
                return [];
            }

            throw new Exception('Failed to add image to Shopify product: ' . $e->getMessage());
        }
    }

    public function addVariantImage(Variants $variant, string $imageUrl): bool
    {
        try {
            $shopifyProduct = $this->shopifySdk->Product($variant->product->getShopifyId($this->region));
            $shopifyVariant = $shopifyProduct->Variant($variant->getShopifyId($this->region));

            // Check if the image already exists
            $existingImages = $shopifyProduct->Image->get();
            $fileName = pathinfo($imageUrl, PATHINFO_BASENAME);

            foreach ($existingImages as $image) {
                if ($image['alt'] === $fileName) {
                    return false; // Image already exists, no need to upload
                }
            }

            // Add the image if it does not exist
            $image = $this->addImage($variant->product, $imageUrl);

            if ($image) {
                $shopifyVariantData = $shopifyVariant->get();
                $shopifyVariant->put(['image_id' => $image['id']]);

                if ($shopifyVariantData['image_id'] !== null) {
                    $shopifyProduct->Image($shopifyVariantData['image_id'])->delete();
                }

                return true;
            }

            return false;
        } catch (Exception $e) {
            //stupid , but for now we return false if the image is not found
            if (Str::contains($e->getMessage(), 'Could not download image')) {
                return false;
            }

            throw new Exception('Failed to add image to Shopify variant: ' . $e->getMessage());
        }
    }
}
