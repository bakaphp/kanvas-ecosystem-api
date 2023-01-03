<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Importer\Actions;

use Kanvas\Inventory\Importer\DataTransferObjects\Importer;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductType;
use Kanvas\Inventory\Products\Actions\CreateProductAction;

class ImporterAction
{
    public function __construct(
        public string $source,
        public Product $productDto
    ) {
    }

    public function execute()
    {
        $this->productType();
    }

    /**
     * productType
     *
     * @return void
     */
    protected function productType()
    {
        $productType = ProductsTypesRepository::getBySourceKey($this->source, $this->productDto->products_types_id);
        if ($productType) {
            $this->productDto->products_types_id = $productType->id;
        } else {
            $productType = ProductsTypes::fromArray([
                'companies_id' => auth()->user()->default_company,
                'name' => $this->productDto->productType['name'],
                'weight' => $this->productDto->productType['weight']
            ]);
            $productType = (new CreateProductType($productType))->execute();
            $productType->set("{{$this->source}}_id", $this->productDto->productType['id']);
            $this->productDto->products_types_id = $productType->id;
        }
    }
}
