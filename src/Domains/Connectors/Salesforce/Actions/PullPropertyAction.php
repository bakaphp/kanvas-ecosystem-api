<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductData;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesData;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class PullPropertyAction
{
    private const string PRODUCT_TYPE_SLUG = 'property';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected array $payload,
        protected string $salesforceId,
    ) {
    }

    public function execute(): Products
    {
        $productType = $this->resolveProductType();

        $productData = new ProductData(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            name: (string) ($this->payload['Property_Name__c'] ?? $this->payload['Name'] ?? 'Unknown Property'),
            description: (string) ($this->payload['Brand__c'] ?? ''),
            productsType: $productType,
            slug: 'sf-location-' . $this->salesforceId,
            sku: 'sf-location-' . $this->salesforceId,
            attributes: $this->mapAttributes(),
        );

        $product = new CreateProductAction($productData, $this->user)->execute();
        $product->set(CustomFieldEnum::SALESFORCE_LOCATION_ID->value, $this->salesforceId);

        return $product;
    }

    private function resolveProductType(): ProductsTypes
    {
        return new CreateProductTypeAction(
            new ProductsTypesData(
                company: $this->company,
                user: $this->user,
                name: 'Property',
                slug: self::PRODUCT_TYPE_SLUG,
            ),
            $this->user,
        )->execute();
    }

    private function mapAttributes(): array
    {
        $map = [
            'Deal Status' => 'Deal_Status__c',
            'Marketing Status' => 'Marketing_Status__c',
            'Store Number' => 'Store__c',
            'Building Type' => 'Location_Type__c',
            'Building Size' => 'Gross_SF__c',
            'Acreage' => 'Property_Acreage__c',
            'Year Built' => 'Year_Built__c',
            'Zoning' => 'Zoning__c',
            'Offering' => 'Ask_Deal_Type__c',
            'Street' => 'Street__c',
            'City' => 'City__c',
            'State Province' => 'State_Province__c',
            'Zip Code' => 'Zip_Code__c',
            'Latitude' => 'Latitude__c',
            'Longitude' => 'Longitude__c',
        ];

        $attributes = [];
        foreach ($map as $attributeName => $salesforceField) {
            $value = $this->payload[$salesforceField] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $attributes[] = ['name' => $attributeName, 'value' => $value];
        }

        return $attributes;
    }
}
