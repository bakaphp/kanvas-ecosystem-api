<?php

declare(strict_types=1);

namespace Kanvas\Currencies\DataTransferObject;

use Kanvas\Currencies\Models\Currencies;
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
     * @param string $country
     * @param string $currency
     * @param string $code
     * @param string $symbol
     * @param string $created_at
     * @param string $updated_at
     * @param int $is_deleted
     */
    public function __construct(
        public int $id,
        public string $country,
        public string $currency,
        public string $code,
        public string $symbol,
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
    public static function fromModel(Currencies $currency) : self
    {
        return new self(
            id: $currency->id,
            country: $currency->country,
            currency: $currency->currency,
            code: $currency->code,
            symbol: $currency->symbol,
            created_at: $currency->created_at->format('Y-m-d H:i:s'),
            updated_at: $currency->updated_at ? $state->updated_at->format('Y-m-d H:i:s') : null,
            is_deleted: $currency->is_deleted
        );
    }
}
