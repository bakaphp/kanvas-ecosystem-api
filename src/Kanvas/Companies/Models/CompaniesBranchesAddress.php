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

/**
 * CompaniesBranchesAddress Model.
 *
 * @property int $companies_branches_id
 * @property string $address
 * @property string $address_2
 * @property string $city
 * @property string $state
 * @property string $country
 * @property string $zip
 * @property int $name
 * @property int $countries_id
 * @property int $states_id
 * @property int $cities_id
 * @property int $is_default
 */

class CompaniesBranchesAddress extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'companies_branches_address';

    protected $guarded = [];

    public function companiesBranches(): BelongsTo
    {
        return $this->belongsTo(
            CompaniesBranches::class,
            'companies_branches_id',
            'id'
        );
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(
            Countries::class,
            'countries_id',
            'id'
        );
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(
            States::class,
            'states_id',
            'id'
        );
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(
            Cities::class,
            'cities_id',
            'id'
        );
    }
}
