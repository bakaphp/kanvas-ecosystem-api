<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Warehouses\DataTransferObject;

/**
 * Class Warehouses
 * @property int $companies_id
 * @property int $apps_id
 * @property int $regions_id
 * @property string $name
 * @property string $location
 * @property bool $is_default
 * @property int $is_published
 */
class Warehouses
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public int $companies_id,
        public int $apps_id,
        public int $regions_id,
        public string $name,
        public ?string $location = null,
        public bool $is_default,
        public int $is_published,
    ) {
    }

    /**
     * fromArray
     *
     * @param  array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['companies_id'],
            $data['apps_id'],
            $data['regions_id'],
            $data['name'],
            $data['location'] ?? null,
            $data['is_default'],
            $data['is_published'],
        );
    }
}
