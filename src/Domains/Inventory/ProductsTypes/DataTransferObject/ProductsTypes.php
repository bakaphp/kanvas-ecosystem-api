<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class ProductsTypes extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public Companies $company,
        public UserInterface $user,
        public string $name,
        public ?string $description = null,
        public int $weight = 0,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $data
     *
     * @return ProductsTypes
     */
    public static function viaRequest(array $request): ProductsTypes
    {
        return new self(
            isset($request['companies_id']) ? Companies::getById($request['companies_id']) : auth()->user()->getCurrentCompany(),
            auth()->user(),
            $request['name'],
            $request['description'] ?? null,
            (int) $request['weight'],
        );
    }
}
