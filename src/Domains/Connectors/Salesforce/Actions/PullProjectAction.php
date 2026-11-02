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

/**
 * Pulls a Project__c (a GAGroup real-estate deal) into a Kanvas Product. Location__c is a single
 * required lookup to Project__c (Location__c.Parent_Deal__c), the same shape as
 * products_variants.products_id — so a Project's locations are imported as that Product's
 * Variants in the same call (see ImportSalesforcePropertiesCommand), never as their own Products.
 */
class PullProjectAction
{
    private const string PRODUCT_TYPE_SLUG = 'project';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected array $payload,
        protected string $salesforceId,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $variants Pre-built Variant rows (one per
     *        Location__c), passed straight to the Product DTO so CreateProductAction creates them
     *        in the same transaction as the Product — never the auto-generated default variant it
     *        falls back to when a Product carries no variants at all.
     */
    public function execute(array $variants = []): Products
    {
        $productData = new ProductData(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            name: (string) ($this->payload['Name'] ?? 'Unknown Project'),
            productsType: $this->resolveProductType(),
            slug: 'sf-project-' . $this->salesforceId,
            sku: 'sf-project-' . $this->salesforceId,
            variants: $variants,
        );

        $product = new CreateProductAction($productData, $this->user)->execute();
        $product->set(CustomFieldEnum::SALESFORCE_PROJECT_ID->value, $this->salesforceId);

        return $product;
    }

    private function resolveProductType(): ProductsTypes
    {
        return new CreateProductTypeAction(
            new ProductsTypesData(
                company: $this->company,
                user: $this->user,
                name: 'Project',
                slug: self::PRODUCT_TYPE_SLUG,
            ),
            $this->user,
        )->execute();
    }
}
