<?php

declare(strict_types=1);

namespace Kanvas\Locations\States\DataTransferObject;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Locations\States\Models\States;

/**
 * ResponseData class
 */
class SingleResponseData extends DataTransferObject
{
    /**
     * Construct function
     *
     * @param int $id
     * @param int $countries_id
     * @param string $name
     * @param string $code
     * @param string $created_at
     * @param string $updated_at
     * @param int $is_deleted
     */
    public function __construct(
        public int $id,
        public int $countries_id,
        public string $name,
        public string $code,
        public string $created_at,
        public ?string $updated_at,
        public int $is_deleted,
    ) {
    }

    /**
     * Create new instance of DTO from request
     *
     * @param App $app
     *
     * @return self
     */
    public static function fromModel(States $state): self
    {
        return new self(
            id: $state->id,
            countries_id: $state->countries_id,
            name: $state->name,
            code: $state->code,
            created_at: $state->created_at->format('Y-m-d H:i:s'),
            updated_at: $state->updated_at ? $state->updated_at->format('Y-m-d H:i:s') : null,
            is_deleted: $state->is_deleted
        );
    }
}
