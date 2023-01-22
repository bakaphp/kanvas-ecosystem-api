<?php

declare(strict_types=1);

namespace Kanvas\Locations\Countries\DataTransferObject;

use Kanvas\Locations\Countries\Models\Countries;
use Spatie\DataTransferObject\DataTransferObject;

/**
 * ResponseData class.
 */
class SingleResponseData extends DataTransferObject
{
    /**
     * Construct function.
     *
     * @param int $id
     * @param string $name
     * @param string $code
     * @param string $flag
     * @param string $created_at
     * @param string $updated_at
     * @param int $is_deleted
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public ?string $flag,
        public string $created_at,
        public ?string $updated_at,
        public int $is_deleted
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param App $app
     *
     * @return self
     */
    public static function fromModel(Countries $country): self
    {
        return new self(
            id: $country->id,
            name: $country->name,
            code: $country->code,
            flag: $country->flag,
            created_at: $country->created_at->format('Y-m-d H:i:s'),
            updated_at: $country->updated_at ? $state->updated_at->format('Y-m-d H:i:s') : null,
            is_deleted: $country->is_deleted
        );
    }
}
