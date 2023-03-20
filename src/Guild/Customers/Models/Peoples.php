<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Models\BaseModel;
use Laravel\Scout\Searchable;

/**
 * Class Peoples.
 *
 * @property int $id
 * @property string $uuid
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property string $dob
 * @property string $google_contact_id
 * @property string $facebook_contact_id
 * @property string $linkedin_contact_id
 * @property string $twitter_contact_id
 * @property string $instagram_contact_id
 * @property string $apple_contact_id
 */
class Peoples extends BaseModel
{
    use UuidTrait;
    use Searchable;
    use NoAppRelationshipTrait;

    protected $table = 'peoples';
    protected $guarded = [];

    /**
    * Create a new factory instance for the model.
    *
    * @return \Illuminate\Database\Eloquent\Factories\Factory
    */
    protected static function newFactory()
    {
        return PeopleFactory::new();
    }

    public function address(): HasMany
    {
        return $this->hasMany(
            Address::class,
            'peoples_id',
            'id'
        );
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(
            Contacts::class,
            'peoples_id',
            'id'
        );
    }
}
