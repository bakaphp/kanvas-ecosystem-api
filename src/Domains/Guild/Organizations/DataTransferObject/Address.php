<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\DataTransferObject;

use Baka\Traits\ScalarCoercionTrait;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\Data;

class Address extends Data
{
    use ScalarCoercionTrait;

    public function __construct(
        public readonly Organization $organization,
        public readonly AddressTypeEnum $type = AddressTypeEnum::BILLING,
        public readonly ?string $address = null,
        public readonly ?string $address_2 = null,
        public readonly ?string $city = null,
        public readonly ?string $county = null,
        public readonly ?string $state = null,
        public readonly ?string $zip = null,
        public readonly ?int $countries_id = null,
        public readonly ?int $city_id = null,
        public readonly ?int $state_id = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly bool $is_default = false,
        public readonly ?UserInterface $user = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromMultiple(
        Organization $organization,
        array $input,
        ?UserInterface $user = null,
    ): self {
        return new self(
            organization: $organization,
            type: isset($input['type'])
                ? AddressTypeEnum::from((string) $input['type'])
                : AddressTypeEnum::BILLING,
            address: self::nullableString($input['address'] ?? null),
            address_2: self::nullableString($input['address_2'] ?? null),
            city: self::nullableString($input['city'] ?? null),
            county: self::nullableString($input['county'] ?? null),
            state: self::nullableString($input['state'] ?? null),
            zip: self::nullableString($input['zip'] ?? null),
            // GraphQL sends `country_id`; the column is `countries_id`.
            countries_id: self::nullableInt($input['country_id'] ?? $input['countries_id'] ?? null),
            city_id: self::nullableInt($input['city_id'] ?? null),
            state_id: self::nullableInt($input['state_id'] ?? null),
            latitude: isset($input['latitude']) ? (float) $input['latitude'] : null,
            longitude: isset($input['longitude']) ? (float) $input['longitude'] : null,
            is_default: (bool) ($input['is_default'] ?? false),
            user: $user,
        );
    }
}
