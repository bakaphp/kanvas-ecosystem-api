<?php
declare(strict_types=1);
namespace Inventory\Regions\DataTransferObject;

/**
 * Class Region.
 * @property int $companies_id
 * @property int $apps_id
 * @property int $currency_id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $short_slug
 * @property ?string settings = null
 * @property int $is_default
 */
class Region
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public int $companies_id,
        public int $apps_id,
        public int $currency_id,
        public string $name,
        public string $slug,
        public string $short_slug,
        public ?string $settings = null,
        public int $is_default,
    ) {
    }

    /**
     * fromArray
     *
     * @param  array $data
     * @return self
     */
    public static function fromArray(array $data):self
    {
        return new self(
            $data['companies_id'],
            $data['apps_id'],
            $data['currency_id'],
            $data['name'],
            $data['slug'],
            $data['short_slug'],
            $data['settings'] ?? null,
            $data['is_default'],
        );
    }
}
