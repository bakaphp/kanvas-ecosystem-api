<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\DataTransferObject;

use Baka\Enums\StateEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Spatie\LaravelData\Data;

/**
 * Class Warehouses.
 *
 * @property int $companies_id
 * @property int $apps_id
 * @property int $regions_id
 * @property string $name
 * @property string $location
 * @property bool $is_default
 * @property int $is_published
 */
class Warehouses extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public int $companies_id,
        public int $apps_id,
        public int $users_id,
        public int $regions_id,
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
        return new self(
            $data['companies_id'] ?? auth()->user()->getCurrentCompany()->getId(),
            $data['apps_id'] ?? app(Apps::class)->getId(),
            $data['users_id'] ?? auth()->user()->getId(),
            RegionRepository::getById($data['regions_id'], auth()->user()->getCurrentCompany())->getId(),
            $data['name'],
            $data['location'] ?? null,
            $data['is_default'] ?? (bool) StateEnums::NO->getValue(),
            $data['is_published'] ?? StateEnums::YES->getValue(),
        );
    }
}
