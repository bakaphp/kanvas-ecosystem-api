<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class Address.
 *
 * @property int $id
 * @property int $peoples_id
 * @property int $users_id
 * @property string $address
 * @property string $address_2
 * @property string $city
 * @property string $county
 * @property int $city_id
 * @property string $state
 * @property int $state_id
 * @property string $zip
 * @property string $countries_id
 * @property int $is_default
 * @property int $address_type_id
 * @property float $duration
 */
class Address extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use NoAppRelationshipTrait;

    protected $table = 'peoples_address';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(
            People::class,
            'peoples_id',
            'id'
        );
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AddressType::class, 'address_type_id', 'id');
    }
    
}
