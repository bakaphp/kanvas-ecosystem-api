<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
        public int $is_published = 1,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $data
     *
     * @return self
     */
    public static function fromRequest(array $data) : self
    {
        $company= auth()->user()->getCurrentCompany();
        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $company,
            app(Apps::class),
            auth()->user(),
            RegionRepository::getById($data['regions_id'], $company),
            $data['name'],
            $data['location'] ?? null,
            $data['is_default'] ?? (bool) StateEnums::NO->getValue(),
            $data['is_published'] ?? StateEnums::YES->getValue(),
        );
    }
}
