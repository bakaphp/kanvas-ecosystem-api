<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoryDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductsDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ProductsTypesModel;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction as ActionsAddAttributeAction;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants as VariantsModel;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses as ModelsVariantsWarehouses;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Throwable;

class ProductImporterAction
{
    protected ?ProductsModel $product = null;

    /**
     * __construct.
     */
    public function __construct(
        public ProductImporter $importedProduct,
        public Companies $company,
        public UserInterface $user,
        public Regions $region,
        public ?AppInterface $app = null
    ) {
        $this->app = $this->app ?? app(Apps::class);
    }

    /**
     * Run all method dor a specify product.
     *
     */
    public function execute(): ProductsModel
    {
        try {
            DB::connection('inventory')->beginTransaction();

            $productDto = ProductsDto::from([
                'app' => $this->app,
                'company' => $this->company,
                'user' => $this->user,
                'name' => $this->importedProduct->name,
                'slug' => $this->importedProduct->slug,
                'description' => $this->importedProduct->description,
                'short_description' => $this->importedProduct->shortDescription,
                'html_description' => $this->importedProduct->htmlDescription,
                'warranty_terms' => $this->importedProduct->warrantyTerms,
                'upc' => $this->importedProduct->upc,
                'variants' => $this->importedProduct->variants,
                'is_published' => $this->importedProduct->isPublished,
                'attributes' => $this->importedProduct->attributes,
            ]);
            $this->product = (new CreateProductAction($productDto, $this->user))->execute();

            if (isset($this->importedProduct->customFields) && ! empty($this->importedProduct->customFields)) {
                $this->product->setAllCustomFields($this->importedProduct->customFields);
            }

            if (! empty($this->importedProduct->files)) {
                $this->product->overWriteFiles($this->importedProduct->files);
            }

            $this->categories();

            $this->productWarehouse();

            //$this->variants();
            $this->variantsLocation($this->product);

            if (! empty($this->importedProduct->productType)) {
                $this->productType();
            }
            DB::connection('inventory')->commit();
            $this->product->fireWorkflow('sync-shopify');
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();

            throw $e;
        }

        return $this->product;
    }

    /**
     * productType.
     */
    protected function productType(): void
    {
        $productType = null;

        if (isset($this->importedProduct->productType['source_id'])) {
            $productType = ProductsTypesModel::getByCustomField(
                $this->importedProduct->getSourceKey(),
                $this->importedProduct->productType['source_id'],
                $this->company
            );
        }
        if ($productType) {
            $this->product->update(['products_types_id' => $productType->id]);
        } else {
            $productTypeDto = ProductsTypes::from([
                'company' => $this->company,
                'user' => $this->user,
                'name' => $this->importedProduct->productType['name'],
                'description' => $this->importedProduct->productType['description'] ?? null,
                'weight' => $this->importedProduct->productType['weight'],
            ]);

            $productType = (new CreateProductTypeAction($productTypeDto, $this->user))->execute();

            if (isset($this->importedProduct->productType['source_id']) && $this->importedProduct->isFromThirdParty()) {
                $productType->setLinkedSource(
                    $this->importedProduct->source,
                    $this->importedProduct->productType['source_id']
                );
            }

            $this->product->update(['products_types_id' => $productType->id]);
        }
    }

    /**
     * categories.
     */
    public function categories(): void
    {
        foreach ($this->importedProduct->categories as $category) {
            $categoryModel = null;

            if (isset($category['source_id'])) {
                $categoryModel = Categories::getByCustomField(
                    $this->importedProduct->getSourceKey(),
                    $category['source_id'],
                    $this->company
                );
            }

            if ($categoryModel) {
                $this->product->categories()->syncWithoutDetaching([$categoryModel->getId()]);
            } else {
                $categoryDto = CategoryDto::from([
                    'app' => $this->app,
                    'user' => $this->user,
                    'company' => $this->company,
                    'parent_id' => $category['parent_id'] ?? 0,
                    'name' => $category['name'],
                    'code' => $category['code'],
                    'position' => $category['position'],
                ]);
                $categoryModel = (new CreateCategory($categoryDto, $this->user))->execute();
                if (isset($category['source_id']) && $this->importedProduct->isFromThirdParty()) {
                    $categoryModel->setLinkedSource($this->importedProduct->source, $category['source_id']);
                }
                $this->product->categories()->syncWithoutDetaching($categoryModel->getId());
            }
        }
    }

    /**
     * attributes.
     */
    public function attributes(): void
    {
        foreach ($this->importedProduct->attributes as $attribute) {
            $attributeModel = null;
            if (isset($attribute['source_id'])) {
                $attributeModel = Attributes::getByCustomField(
                    $this->importedProduct->getSourceKey(),
                    $attribute['source_id'],
                    $this->company
                );
            }

            if (! $attributeModel && ! empty($attribute['name']) && ! empty($attribute['value'])) {
                $attributesDto = AttributesDto::from([
                    'app' => $this->app,
                    'user' => $this->user,
                    'company' => $this->company,
                    'name' => $attribute['name'],
                    'value' => $attribute['value'],
                ]);
                $attributeModel = (new CreateAttribute($attributesDto, $this->user))->execute();

                if (isset($attribute['source_id']) && $this->importedProduct->isFromThirdParty()) {
                    $attributeModel->setLinkedSource($this->importedProduct->source, $attribute['source_id']);
                }
            }

            if ($attributeModel instanceof Attributes && ! empty($attribute['value'])) {
                (new AddAttributeAction($this->product, $attributeModel, $attribute['value']))->execute();
            }
        }
    }

    public function productWarehouse(): void
    {
        foreach ($this->importedProduct->warehouses as $warehouseLocation) {
            $warehouseData = Warehouses::from([
                'company' => $this->company,
                'user' => $this->user,
                'app' => $this->app,
                'region' => $this->region,
                'regions_id' => $this->region->getId(),
                'name' => $warehouseLocation['warehouse'],
            ]);

            $warehouse = (new CreateWarehouseAction($warehouseData, $this->user))->execute();

            $this->product->warehouses()->syncWithoutDetaching([$warehouse->getId()]);
        }
    }

    /**
     * variants.
     */
    public function variants(): void
    {
        foreach ($this->importedProduct->variants as $variant) {
            $variantModel = null;

            if (isset($variant['source_id'])) {
                $variantModel = VariantsModel::getByCustomField(
                    $this->importedProduct->getSourceKey(),
                    $variant['source_id'],
                    $this->company
                );
            }

            if ($variantModel) {
                $this->product->variants()->save($variantModel);
            } else {
                $variantDto = VariantsDto::from([
                    'product' => $this->product,
                    'products_id' => $this->product->getId(),
                    'warehouse_id' => (int) $variant['warehouse']['id'],
                    ...$variant,
                ]);

                $variantModel = (new CreateVariantsAction($variantDto, $this->user))->execute();
                if (isset($variant['source_id']) && $this->importedProduct->isFromThirdParty()) {
                    $variantModel->setLinkedSource($this->importedProduct->source, $variant['source_id']);
                }
            }

            /*   if (! empty($variant['files'])) {
                  foreach ($variant['files'] as $file) {
                      $variantModel->addFileFromUrl($file['url'], $file['name']);
                  }
              }

              $this->variantsAttributes($variantModel, $variant); */

            $this->addVariantsToLocation($variantModel);
        }
    }

    public function variantsLocation(ProductsModel $product): void
    {
        if ($product->variants()->count() > 0) {
            foreach ($product->variants as $variant) {
                $this->addVariantsToLocation($variant);
            }
        }
    }

    public function variantsAttributes(VariantsModel $variantModel, array $variantData): void
    {
        if (isset($variantData['attributes']) && ! empty($variantData['attributes'])) {
            foreach ($variantData['attributes'] as $attribute) {
                $attributeModel = null;
                if (isset($attribute['source_id'])) {
                    $attributeModel = Attributes::getByCustomField(
                        $this->importedProduct->getSourceKey(),
                        $attribute['source_id'],
                        $this->company
                    );
                }

                if (! $attributeModel && ! empty($attribute['name']) && ! empty($attribute['value'])) {
                    $attributesDto = AttributesDto::from([
                        'app' => $this->app,
                        'user' => $this->user,
                        'company' => $this->company,
                        'name' => $attribute['name'],
                        'value' => $attribute['value'],
                    ]);
                    $attributeModel = (new CreateAttribute($attributesDto, $this->user))->execute();

                    if (isset($attribute['source_id']) && $this->importedProduct->isFromThirdParty()) {
                        $attributeModel->setLinkedSource($this->importedProduct->source, $attribute['source_id']);
                    }
                }

                if ($attributeModel instanceof Attributes && ! empty($attribute['value'])) {
                    (new ActionsAddAttributeAction($variantModel, $attributeModel, $attribute['value']))->execute();
                }
            }
        }
    }

    /**
     * Add variant to warehouse and channels.
     */
    public function addVariantsToLocation(VariantsModel $variantModel): void
    {
        //add to warehouse
        foreach ($this->importedProduct->warehouses as $warehouseLocation) {
            $warehouseData = Warehouses::from([
                'company' => $this->company,
                'user' => $this->user,
                'app' => $this->app,
                'region' => $this->region,
                'regions_id' => $this->region->getId(),
                'name' => $warehouseLocation['warehouse'],
            ]);

            $warehouse = (new CreateWarehouseAction($warehouseData, $this->user))->execute();

            $channelData = Channels::from([
                'app' => $this->app,
                'user' => $this->user,
                'company' => $this->company,
                'name' => $warehouseLocation['channel'],
            ]);

            $channel = (new CreateChannel($channelData, $this->user))->execute();

            $matchingVariantInfo = array_filter($this->importedProduct->variants, function ($variant) use ($variantModel) {
                return $variant['sku'] === $variantModel->sku;
            });

            if (! empty($matchingVariantInfo)) {
                // Since array_filter preserves keys, use array_values to reset them
                $variantData = current($matchingVariantInfo);

                if (! empty($variantData['warehouse'])) {
                    $variantData = [
                        'quantity' => $variantData['warehouse']['quantity'] ?? ($variantData['quantity'] ?? 1),
                        'price' => $variantData['warehouse']['price'] ?? $variantData['price'],
                        'discountPrice' => $variantData['warehouse']['discountPrice'] ?? ($variantData['discountPrice'] ?? 0),
                    ];
                }
            } else {
                $variantData = [
                    'quantity' => $this->importedProduct->quantity,
                    'price' => $this->importedProduct->price,
                    'discountPrice' => $this->importedProduct->discountPrice,
                ];
            }

            $variantChannel = VariantChannel::from([
                'price' => (float) $variantData['price'],
                'discounted_price' => (float) $variantData['discountPrice'],
                'is_published' => $this->importedProduct->isPublished,
            ]);

            $variantWarehouses = ModelsVariantsWarehouses::where('products_variants_id', $variantModel->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->first();

            if (! $variantWarehouses) {
                $variantWarehouses = (new AddToWarehouseAction(
                    $variantModel,
                    $warehouse,
                    VariantsWarehouses::from([
                        'variant' => $variantModel,
                        'warehouse' => $warehouse,
                        'quantity' => $variantData['quantity'] ?? 1,
                        'price' => $variantData['price'],
                        'sku' => $variantModel->sku,
                    ]),
                ))->execute();
            } else {
                VariantService::updateWarehouseVariant($variantModel, $warehouse, [
                    'quantity' => $variantData['quantity'] ?? 1,
                    'price' => $variantData['price'],
                    'sku' => $variantModel->sku,
                ]);
            }

            (new AddVariantToChannelAction(
                $variantWarehouses,
                $channel,
                $variantChannel
            ))->execute();
        }
    }
}
