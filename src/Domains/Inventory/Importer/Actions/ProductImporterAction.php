<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
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
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status;
use Kanvas\Inventory\Status\Models\Status as ModelsStatus;
use Kanvas\Workflow\Enums\WorkflowEnum;
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
        public ?AppInterface $app = null,
        public bool $runWorkflow = true
    ) {
        $this->app = $this->app ?? app(Apps::class);
    }

    /**
     * Run all method dor a specify product.
     */
    public function execute(): ProductsModel
    {
        try {
            DB::connection('inventory')->beginTransaction();

            $status = $this->createStatus();
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
                'status_id' => $status ? $status->getId() : null,
                'is_published' => $this->importedProduct->isPublished,
                'attributes' => $this->importedProduct->attributes,
            ]);
            $createAction = new CreateProductAction($productDto, $this->user);
            $createAction->setRunWorkflow($this->runWorkflow);
            $this->product = $createAction->execute();

            if (isset($this->importedProduct->customFields) && ! empty($this->importedProduct->customFields)) {
                $this->product->setAllCustomFields($this->importedProduct->customFields);
            }

            if (! empty($this->importedProduct->files)) {
                $this->product->overWriteFiles($this->importedProduct->files);
            }

            $this->categories();

            if (! empty($this->importedProduct->productType)) {
                $this->productType();
            }
            DB::connection('inventory')->commit();
            $this->product->fireWorkflow(WorkflowEnum::SYNC_SHOPIFY->value);
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();

            throw $e;
        }

        return $this->product;
    }

    protected function createStatus(): ModelsStatus
    {
        if ($this->importedProduct->status) {
            $createStatus = new CreateStatusAction(
                new Status(
                    $this->app,
                    $this->company,
                    $this->user,
                    $this->importedProduct->status['name'],
                ),
                $this->user
            );

            return $createStatus->execute();
        } else {
            return ModelsStatus::getDefault($this->company);
        }
    }

    /**
     * productType.
     */
    protected function productType(): void
    {
        $productType = null;

        if (isset($this->importedProduct->productType)) {
            $productType = ProductsTypesModel::getByCustomField(
                'slug',
                Str::slug($this->importedProduct->productType['name']),
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
}
