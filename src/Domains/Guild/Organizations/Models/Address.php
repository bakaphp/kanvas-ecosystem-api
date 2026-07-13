<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;

/**
 * Structured address on an Organization. Shares the `address_types` lookup with People.
 *
 * Carries no apps_id/companies_id — the parent Organization is the tenant boundary, same as peoples_address.
 *
 * @property int $id
 * @property int $organizations_id
 * @property int|null $address_type_id
 * @property string|null $address
 * @property string|null $address_2
 * @property string|null $city
 * @property string|null $county
 * @property string|null $state
 * @property string|null $zip
 * @property int|null $countries_id
 * @property int|null $city_id
 * @property int|null $state_id
 * @property float|null $latitude
 * @property float|null $longitude
 * @property bool $is_default
 * @property bool $is_deleted
 */
class Address extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use SoftDeletesTrait;

    public const string DELETED_AT = 'is_deleted';

    protected $table = 'organizations_address';
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_deleted' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function trashed(): bool
    {
        return (bool) $this->{$this->getDeletedAtColumn()};
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organizations_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AddressType::class, 'address_type_id', 'id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Countries::class, 'countries_id', 'id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(States::class, 'state_id', 'id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(Cities::class, 'city_id', 'id');
    }

    /**
     * Exposed to GraphQL instead of the `type` relation: the name `AddressType` is already taken there by an
     * unrelated Ecosystem value object, and reusing it silently resolves to the wrong type.
     */
    public function addressTypeName(): ?string
    {
        /** @var AddressType|null $type */
        $type = $this->getRelationValue('type');

        return $type?->name;
    }

    public function countryCode(): ?string
    {
        /** @var Countries|null $country */
        $country = $this->getRelationValue('country');
        $code = $country?->code;

        return $code !== null && $code !== ''
            ? strtoupper($code)
            : null;
    }

    /**
     * External billing APIs (Mercury, Stripe) validate an address all-or-nothing — a partial one is rejected
     * outright, so callers check this before sending rather than guessing which field was missing.
     */
    public function isComplete(): bool
    {
        foreach (['address', 'city', 'state', 'zip'] as $field) {
            if (trim((string) ($this->{$field} ?? '')) === '') {
                return false;
            }
        }

        return $this->countryCode() !== null;
    }
}
