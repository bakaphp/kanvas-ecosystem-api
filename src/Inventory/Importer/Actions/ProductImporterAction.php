<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Actions;

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
use Kanvas\Inventory\Variants\Actions\AddVariantToChannel;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants as VariantsModel;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Throwable;

class ProductImporterAction
{
    protected ?ProductsModel $product = null;
    protected Apps $app;

    /**
     * __construct.
     *
     */
    public function __construct(
        public ProductImporter $importedProduct,
        public Companies $company,
        public UserInterface $user,
        public Regions $region
    ) {
        if ($this->importedProduct->isFromThirdParty()) {
            $this->product = ProductsModel::getByCustomField(
                $this->importedProduct->getSourceKey(),
                $this->importedProduct->source_id,
                $this->company
            );
        }

        $this->app = app(Apps::class);
    }

    /**
     * Run all method dor a specify product.
     *
     * @throws Exception
     *
     * @return bool
     */
    public function execute() : bool
    {
        try {
            DB::connection('inventory')->beginTransaction();

            if ($this->product === null) {
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
                    'is_published' => $this->importedProduct->isPublished,
                ]);
                $this->product = (new CreateProductAction($productDto, $this->user))->execute();
            }

            if ($this->importedProduct->isFromThirdParty()) {
                $this->product->setLinkedSource(
                    $this->importedProduct->source,
                    $this->importedProduct->source_id
                );
                $this->product->set('source', $this->importedProduct->source);
            }

            $this->categories();

            if (!empty($this->importedProduct->attributes)) {
                $this->attributes();
            }

            $this->productWarehouse();

            $this->variants();

            if (!empty($this->importedProduct->productType)) {
                $this->productType();
            }
            DB::connection('inventory')->commit();
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();
            throw $e;
        }

        return true;
    }

    /**
     * productType.
     *
     * @return void
     */
    protected function productType() : void
    {
        $productType = null;

        if (isset($this->importedProduct->productType['source_id'])) {
            $productType = ProductsTypesModel::getByCustomField($this->importedProduct->getSourceKey(), $this->importedProduct->productType['source_id'], $this->company);
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

            if (isset($this->importedProduct->productType['source_id'])) {
                $productType->setLinkedSource($this->importedProduct->source, $this->importedProduct->productType['source_id']);
            }

            $this->product->update(['products_types_id' => $productType->id]);
        }
    }

    /**
     * categories.
     *
     * @return void
     */
    public function categories() : void
    {
        foreach ($this->importedProduct->categories as $category) {
            $categoryModel = null;

            if (isset($category['source_id'])) {
                $categoryModel = Categories::getByCustomField($this->importedProduct->getSourceKey(), $category['source_id'], $this->company);
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
                if (isset($category['source_id'])) {
                    $categoryModel->setLinkedSource($this->importedProduct->source, $category['source_id']);
                }
                $this->product->categories()->attach($categoryModel->getId());
            }
        }
    }

    /**
     * attributes.
     *
     * @return void
     */
    public function attributes() : void
    {
        foreach ($this->importedProduct->attributes as $attribute) {
            $attributeModel = null;
            if (isset($attribute['source_id'])) {
                $attributeModel = Attributes::getByCustomField($this->importedProduct->getSourceKey(), $attribute['source_id'], $this->company);
            }

            if (!$attributeModel) {
                $attributesDto = AttributesDto::from([
                    'app' => $this->app,
                    'user' => $this->user,
                    'company' => $this->company,
                    'name' => $attribute['name'],
                ]);
                $attributeModel = (new CreateAttribute($attributesDto, $this->user))->execute();

                if (isset($attribute['source_id'])) {
                    $attributeModel->setLinkedSource($this->importedProduct->source, $attribute['source_id']);
                }
            }
            (new AddAttributeAction($this->product, $attributeModel, $attribute['value']))->execute();
        }
    }

    public function productWarehouse() : void
    {
        foreach ($this->importedProduct->warehouses as $warehouseLocation) {
            $warehouseData = Warehouses::from([
                'company' => $this->company,
                'user' => $this->user,
                'app' => $this->app,
                'region' => $this->region,
                'regions_id' => $this->region->getId(),
                'name' => $warehouseLocation['warehouse']
            ]);

            $warehouse = (new CreateWarehouseAction($warehouseData, $this->user))->execute();

            $this->product->warehouses()->syncWithoutDetaching([$warehouse->getId()]);
        }
    }

    /**
     * variants.
     *
     * @return void
     */
    public function variants() : void
    {
        foreach ($this->importedProduct->variants as $variant) {
            $variantModel = null;

            if (isset($variant['source_id'])) {
                $variantModel = VariantsModel::getByCustomField($this->importedProduct->getSourceKey(), $variant['source_id'], $this->company);
            }

            if ($variantModel) {
                $this->product->variants()->save($variantModel);
            } else {
                $variantDto = VariantsDto::from([
                    'product' => $this->product,
                    'products_id' => $this->product->getId(),
                    ...$variant
                ]);
                $variantModel = (new CreateVariantsAction($variantDto, $this->user))->execute();
                if (isset($variant['source_id'])) {
                    $variantModel->setLinkedSource($this->importedProduct->source, $variant['source_id']);
                }
            }

            $this->variantsAttributes($variantModel, $variant);

            $this->addVariantsToLocation($variantModel);
        }
    }

    public function variantsAttributes(VariantsModel $variantModel, array $variantData) : void
    {
        if (isset($variantData['attributes']) && !empty($variantData['attributes'])) {
            foreach ($variantData['attributes'] as $attribute) {
                $attributeModel = null;
                if (isset($attribute['source_id'])) {
                    $attributeModel = Attributes::getByCustomField($this->importedProduct->getSourceKey(), $attribute['source_id'], $this->company);
                }

                if (!$attributeModel) {
                    $attributesDto = AttributesDto::from([
                        'app' => $this->app,
                        'user' => $this->user,
                        'company' => $this->company,
                        'name' => $attribute['name'],
                    ]);
                    $attributeModel = (new CreateAttribute($attributesDto, $this->user))->execute();

                    if (isset($attribute['source_id'])) {
                        $attributeModel->setLinkedSource($this->importedProduct->source, $attribute['source_id']);
                    }
                }
                (new ActionsAddAttributeAction($variantModel, $attributeModel, $attribute['value']))->execute();
            }
        }
    }

    /**
     * Add variant to warehouse and channels.
     *
     * @param VariantsModel $variantModel
     *
     * @return void
     */
    public function addVariantsToLocation(VariantsModel $variantModel) : void
    {
        //add to warehouse
        foreach ($this->importedProduct->warehouses as $warehouseLocation) {
            $warehouseData = Warehouses::from([
                'company' => $this->company,
                'user' => $this->user,
                'app' => $this->app,
                'region' => $this->region,
                'regions_id' => $this->region->getId(),
                'name' => $warehouseLocation['warehouse']
            ]);

            $warehouse = (new CreateWarehouseAction($warehouseData, $this->user))->execute();

            $channelData = Channels::from([
                'app' => $this->app,
                'user' => $this->user,
                'company' => $this->company,
                'name' => $warehouseLocation['channel'],
            ]);

            $channel = (new CreateChannel($channelData, $this->user))->execute();

            $variantChannel = VariantChannel::from([
                'price' => $this->importedProduct->price,
                'discounted_price' => $this->importedProduct->discountPrice,
                'is_published' => $this->importedProduct->isPublished,
            ]);

            (new AddToWarehouseAction(
                $variantModel,
                $warehouse,
                VariantsWarehouses::from([
                    'quantity' => $this->importedProduct->quantity,
                    'price' => $this->importedProduct->price,
                    'sku' => $variantModel->sku
                ]),
            ))->execute();

            (new AddVariantToChannel(
                $variantModel,
                $channel,
                $warehouse,
                $variantChannel
            ))->execute();
        }
    }
}
