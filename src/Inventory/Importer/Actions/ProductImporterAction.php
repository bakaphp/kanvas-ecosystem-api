<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoryDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductsDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ProductsTypesModel;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants as VariantsModel;

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
        public UserInterface $user
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
     * execute.
     *
     * @return void
     */
    public function execute() : void
    {
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

        $this->variants();

        if (!empty($this->importedProduct->productType)) {
            $this->productType();
        }
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
            if ($attribute['source_id']) {
                $attributeModel = Attributes::getByCustomField($this->importedProduct->getSourceKey(), $attribute['source_id'], $this->company);
            }

            if ($attributeModel) {
                $attributesDto = AttributesDto::from([
                    'app' => $this->app,
                    'user' => $this->user,
                    'company' => $this->company,
                    'name' => $attribute['name'],
                ]);
                $attributeModel = (new CreateAttribute($attributesDto, $this->user))->execute();

                if ($attribute['source_id']) {
                    $attributeModel->setLinkedSource($this->importedProduct->source, $attribute['source_id']);
                }
            }
            (new AddAttributeAction($this->product, $attributeModel, $attribute['value']))->execute();
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
                    ...$variant
                ]);
                $variantModel = (new CreateVariantsAction($variantDto, $this->user))->execute();
                if (isset($variant['source_id'])) {
                    $variantModel->setLinkedSource($this->importedProduct->source, $variant['source_id']);
                }
            }
        }
    }
}
