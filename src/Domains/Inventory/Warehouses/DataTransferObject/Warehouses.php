<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Spatie\LaravelData\Data;

class Warehouses extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public Regions $region,
        public string $name,
        public ?string $location = null,
        public bool $is_default = false,
        public bool $is_published = true,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $data
     *
     * @return self
     */
    public static function viaRequest(array $request, UserInterface $user, CompanyInterface $company): self
    {
        if (! isset($request['regions_id'])) {
            throw new ValidationException('Region is required');
        }

        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $company,
            app(Apps::class),
            $user,
            RegionRepository::getById($request['regions_id'], $company),
            $request['name'],
            $request['location'] ?? null,
            $request['is_default'] ?? (bool) StateEnums::NO->getValue(),
            $request['is_published'] ?? (bool) StateEnums::YES->getValue(),
        );
    }
}
