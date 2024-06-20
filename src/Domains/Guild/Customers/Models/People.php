<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Social\Interactions\Traits\SocialInteractionsTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Laravel\Scout\Searchable;

/**
 * Class People.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property string $firstname
 * @property string|null $middlename = null
 * @property string $lastname
 * @property string|null $dob = null
 * @property string|null $google_contact_id
 * @property string|null $facebook_contact_id
 * @property string|null $linkedin_contact_id
 * @property string|null $twitter_contact_id
 * @property string|null $instagram_contact_id
 * @property string|null $apple_contact_id
 */
class People extends BaseModel
{
    use UuidTrait;
    use Searchable;
    use HasTagsTrait;
    use CanUseWorkflow;
    use SocialInteractionsTrait;

    protected $table = 'peoples';
    protected $guarded = [];

    protected $casts = [
        'dob' => 'datetime:Y-m-d',
    ];

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
            Contact::class,
            'peoples_id',
            'id'
        );
    }

    public function emails(): HasMany
    {
        return $this->hasMany(
            Contact::class,
            'peoples_id',
            'id'
        )->where(
            'contacts_types_id',
            ContactType::getByName('Email')->getId()
        );
    }

    // Define the relationship with the Organization model
    public function organizations()
    {
        return $this->belongsToMany(
            Organization::class,
            'organizations_peoples',
            'peoples_id',
            'organizations_id'
        );
    }

    public function employmentHistory(): HasMany
    {
        return $this->hasMany(
            PeopleEmploymentHistory::class,
            'peoples_id',
            'id'
        );
    }

    public function phones(): HasMany
    {
        return $this->hasMany(
            Contact::class,
            'peoples_id',
            'id'
        )->where(
            'contacts_types_id',
            ContactType::getByName('Phone')->getId()
        );
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function getEmails(): Collection
    {
        return $this->contacts()
                ->where(
                    'contacts_types_id',
                    ContactType::getByName('Email')->getId()
                )
                ->get();
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function getPhones(): Collection
    {
        return $this->contacts()
                ->where(
                    'contacts_types_id',
                    ContactType::getByName('Phone')->getId()
                )
                ->get();
    }

    /**
     * @todo move to laravel attributes.
     */
    public function getName(): string
    {
        $name = trim($this->firstname . ' ' . $this->middlename . ' ' . $this->lastname);

        return preg_replace('/\s+/', ' ', $name);
    }

    protected static function newFactory()
    {
        return new PeopleFactory();
    }
}
