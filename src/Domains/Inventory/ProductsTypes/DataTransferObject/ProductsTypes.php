<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\DataTransferObject;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
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
        public bool $isPublished = true,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $data
     *
     * @return ProductsTypes
     */
    public static function viaRequest(array $request, UserInterface $user, CompanyInterface $company): self
    {
        return new self(
            isset($request['companies_id']) ? Companies::getById($request['companies_id']) : $company,
            $user,
            $request['name'],
            $request['description'] ?? null,
            (int) $request['weight'],
            $request['is_published'] ?? (bool) StateEnums::YES->getValue(),
        );
    }
}
