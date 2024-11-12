<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use PHPShopify\ShopifySDK;

class ShopifyVariantMetafieldService
{
    protected ShopifySDK $shopifySdk;
    protected array $types = [
        'string' => 'multi_line_text_field',
        'json' => 'json',
        'integer' => 'number_integer',
        'double' => 'number_decimal',
    ];

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected Variants $variant
    ) {
        $this->shopifySdk = Client::getInstance($app, $company, $region);
    }

    public function setMetaField(): int
    {
        $attributes = $this->variant->attributes;
        $shopifyProductVariantId = $this->variant->getShopifyId($this->region);
        $shopifyProduct = $this->shopifySdk->Product($this->variant->product->getShopifyId($this->region));
        $shopifyMetaFields = $this->variant->get(CustomFieldEnum::SHOPIFY_META_FIELD_ID->value) ?? [];
        $i = 0;
        foreach ($attributes as $attribute) {
            if (! $attribute->is_filtrable) {
                $this->deleteMetaFieldIfExists($shopifyMetaFields, $attribute, $shopifyProduct, $shopifyProductVariantId);

                continue;
            }
            $type = $this->determineType($attribute->value);
            $attributeValue = $type === 'json' ? json_encode($attribute->value) : $attribute->value;

            $mutationGraphql = [
                'namespace' => 'attributes',
                'key' => $attribute->name,
                'value' => $attributeValue,
                'type' => $this->types[$type],
                'variant_id' => $shopifyProductVariantId,
            ];
            Log::debug('Metafield', $mutationGraphql);
            $metaField = $shopifyProduct->Variant($shopifyProductVariantId)->Metafield->post($mutationGraphql);
            Log::debug('Metafield Response', $metaField);
            $shopifyMetaFields[$this->region->id][$attribute->id] = $metaField['id'];
            $i++;
        }

        $this->variant->set(CustomFieldEnum::SHOPIFY_META_FIELD_ID->value, $shopifyMetaFields);

        return $i;
    }

    protected function deleteMetaFieldIfExists(array &$shopifyMetaFields, $attribute, $shopifyProduct, $shopifyProductVariantId): void
    {
        $metaFieldId = $shopifyMetaFields[$this->region->id][$attribute->id] ?? null;

        if ($metaFieldId) {
            try {
                $shopifyProduct->Variant($shopifyProductVariantId)->Metafield($metaFieldId)->delete();
            } catch (Exception $e) {
                // Handle exception if needed
            }
            unset($shopifyMetaFields[$this->region->id][$attribute->id]);
        }
    }

    protected function determineType(mixed $value): string
    {
        $type = gettype($value);

        return match ($type) {
            'array' => 'json',
            'string' => Str::isJson($value) ? 'json' : 'string',
            default => $type,
        };
    }

    public function getMetaField(): array
    {
        $shopifyProductVariantId = $this->variant->getShopifyId($this->region);
        $shopifyProduct = $this->shopifySdk->Product($this->variant->product->getShopifyId($this->region));

        return $shopifyProduct->Variant($shopifyProductVariantId)->Metafield->get();
    }
}
