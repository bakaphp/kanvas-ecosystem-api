<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Kanvas\Models\BaseModel;
use Override;

/**
 * Class CompaniesAddress.
 *
 * @property int $id
 * @property int $companies_id
 * @property string $fullname
 * @property string $phone
 * @property string $address
 * @property string $address_2
 * @property string $city
 * @property string $county
 * @property string $state
 * @property string $zip
 * @property int $countries_id
 * @property int $is_default
 */
class CompaniesAddress extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use NoAppRelationshipTrait;

    protected $table = 'companies_address';
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    #[Override]
    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Companies::class,
            'companies_id',
            'id'
        );
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(States::class, 'state_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(Cities::class, 'city_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Countries::class, 'countries_id');
    }
}
