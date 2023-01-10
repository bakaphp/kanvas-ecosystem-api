<?php
declare(strict_types=1);
namespace Kanvas\Inventory\ProductsTypes\DataTransferObject;

use Spatie\LaravelData\Data;

class ProductsTypes extends Data
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public ?int $companiesId = null,
        public string $name,
        public ?string $description = null,
        public int $weight,
    ) {
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
            description: $data['description'] ?? null,
            weight: $data['weight'],
        );
    }
}
