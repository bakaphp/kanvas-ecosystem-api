<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class Categories extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public CompanyInterface $company,
        public UserInterface $user,
        public string $name,
        public int $parent_id = 0,
        public int|string $position = 0,
        public bool $is_published = true,
        public int $weight = 0,
        public ?string $code = null,
        public ?string $slug = null,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $request
     *
     * @return self
     */
    public static function viaRequest(array $request, UserInterface $user, CompanyInterface $company): self
    {
        return new self(
            app(Apps::class),
            isset($request['companies_id']) ? Companies::getById($request['companies_id']) : $company,
            $user,
            $request['name'],
            $request['parent_id'] ?? 0,
            $request['position'] ?? 0,
            $request['is_published'] ?? (bool) StateEnums::YES->getValue(),
            $request['weight'] ?? 0,
            $request['code'] ?? null,
            $request['slug'] ?? null
        );
    }
}
