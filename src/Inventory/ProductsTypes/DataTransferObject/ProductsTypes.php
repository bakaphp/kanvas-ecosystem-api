<?php
declare(strict_types=1);
namespace Kanvas\Inventory\ProductsTypes\DataTransferObject;

class ProductsTypes {
        
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public int $companiesId,
        public string $name,
        public string $description,
        public int $weight,
    ){
    }
    
    /**
     * fromArray
     *
     * @param  array $data
     * @return ProductsTypes
     */
    public static function fromArray(array $data): ProductsTypes
    {
        return new self(
            companiesId: $data['companies_id'] ?? null,
            name: $data['name'],
            description: $data['description'],
            weight: $data['weight'],
        );
    }
}