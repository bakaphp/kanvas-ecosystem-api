<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Importer\Actions;

use Kanvas\Inventory\Importer\DataTransferObjects\Importer;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductType;
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

class ImporterAction
{
    protected $product;

    public function __construct(
        public string $source,
        public Importer $importerDto,
        public Companies $company 
    ) {
    }

    public function execute()
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
            $this->product->set("{$this->source}_id", $this->importerDto->source_id);
        }
    }

    /**
     * productType
     *
     * @return void
     */
    protected function productType()
    {
        $productType = ProductsTypesRepository::getBySourceKey("{$this->source}_id", $this->importerDto->products_types_id);
        if ($productType) {
            $this->importerDto->products_types_id = $productType->id;
        } else {
            $productType = ProductsTypes::fromArray([
                'companies_id' => auth()->user()->default_company,
                'name' => $this->importerDto->productType['name'],
                'weight' => $this->importerDto->productType['weight']
            ]);
            $productType = (new CreateProductType($productType))->execute();
            $productType->set("{{$this->source}}_id", $this->importerDto->productType['source_id']);
            $this->importerDto->products_types_id = $productType->id;
        }
    }

    /**
     * categories
     *
     * @return void
     */
    public function categories()
    {
        foreach ($this->importerDto->categories as $key => $category) {
            $categoryInv = Categories::getSourceKey("{{$this->source}}_id", $category['source_id']);
            if ($categoryInv) {
                $this->importerDto->categories[$key] = $categoryInv->toArray();
            }
            $category = CategoryDto::from($category);
            $category = (new CreateCategory(CategoryDto::from($category)))->execute();
            $category->set("{{$this->source}}_id", $category['source_id']);
            $this->importerDto->categories[$key] = $category;
        }
    }

    /**
     * warehouses
     *
     * @return void
     */
    public function warehouses()
    {
        foreach ($this->importerDto->warehouses as $key => $warehouses) {
            $warehouseInv = Warehouses::getSourceKey($this->source);
            if ($warehouseInv) {
                $this->importerDto->categories[$key] = $warehouseInv->ToArray();
            }
        }
    }
}
