<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Importer\Actions;

use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoryDto;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductsDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ProductsTypesModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants as VariantsModel;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;

class ProductImporterAction
{
    protected $product;

    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public string $source,
        public ProductImporter $importerDto,
        public Companies $company
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute(): void
    {
        $product = ProductsModel::getByCustomField("{$this->source}_id", $this->importerDto->source_id, $this->company);
        if ($product) {
            $this->product = $product;
        } else {
            $productDto = ProductsDto::from([
                'name' => $this->importerDto->name,
                'description' => $this->importerDto->description,
                'short_description' => $this->importerDto->short_description ?? null,
                'html_description' => $this->importerDto->html_description ?? null,
                'warranty_terms' => $this->importerDto->warranty_terms ?? null,
                'upc' => $this->importer->upc ?? null
            ]);
            $this->product = (new CreateProductAction($productDto))->execute();
            $this->product->setLinkedSource($this->source, $this->importerDto->source_id);
        }
        $this->productType();
        $this->categories();
        $this->attributes();
        $this->variants();
    }

    /**
     * productType
     *
     * @return void
     */
    protected function productType(): void
    {
        $productType = ProductsTypesModel::getByCustomField("{$this->source}_id", $this->importerDto->productType['source_id'], $this->company);
        if ($productType) {
            $this->product->update(['products_types_id' => $productType->id]);
        } else {
            $productTypeDto = ProductsTypes::from([
                'companies_id' => $this->company->id,
                'name' => $this->importerDto->productType['name'],
                'description' => $this->importerDto->productType['description'] ?? null,
                'weight' => $this->importerDto->productType['weight'],
            ]);
            $productType = (new CreateProductTypeAction($productTypeDto))->execute();
            $this->product->setLinkedSource($this->source, $this->importerDto->productType['source_id']);
            $this->product->update(['products_types_id' => $productType->id]);
        }
    }

    /**
     * categories
     *
     * @return void
     */
    public function categories(): void
    {
        foreach ($this->importerDto->categories as $category) {
            $categoryModel = Categories::getByCustomField("{$this->source}_id", $category['source_id'], $this->company);
            if ($categoryModel) {
                $this->product->categories()->syncWithoutDetaching([$categoryModel->id]);
            } else {
                $categoryDto = CategoryDto::fromArray([
                    'companies_id' => $this->company->id,
                    'parent_id' => $category['parent_id'] ?? null,
                    'name' => $category['name'],
                    'code' => $category['code'],
                    'position' => $category['position'],
                ]);
                $categoryModel = (new CreateCategory($categoryDto))->execute();
                $categoryModel->setLinkedSource($this->source, $category['source_id']);
                $this->product->categories()->attach($categoryModel->id);
            }
        }
    }

    /**
     * attributes
     *
     * @return void
     */
    public function attributes(): void
    {
        foreach ($this->importerDto->attributes as $attribute) {
            $attributeModel = Attributes::getByCustomField("{$this->source}_id", $attribute['source_id'], $this->company);
            if (!$attributeModel) {
                $attributesDto = AttributesDto::from([
                    'name' => $attribute['name'],
                ]);
                $attributeModel = (new CreateAttribute($attributesDto))->execute();
                $attributeModel->setLinkedSource($this->source, $attribute['source_id']);
            }
            (new AddAttributeAction($this->product, $attributeModel, $attribute['value']))->execute();
        }
    }

    /**
     * variants
     *
     * @return void
     */
    public function variants(): void
    {
        foreach ($this->importerDto->variants as $variant) {
            $variantModel = VariantsModel::getByCustomField("{$this->source}_id", $variant['source_id'], $this->company);
            if ($variantModel) {
                $this->product->variants()->save($variantModel);
            } else {
                $variantDto = VariantsDto::from([
                    'products_id' => $this->product->id,
                    ...$variant
                ]);
                $variantModel = (new CreateVariantsAction($variantDto))->execute();
                $variantModel->setLinkedSource($this->source, $variant['source_id']);
            }
        }
    }
}
