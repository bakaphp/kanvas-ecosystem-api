<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Kanvas\Guild\Customers\DataTransferObject\Address as DataTransferObjectAddress;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Social\Interactions\Traits\SocialInteractionsTrait;
use Kanvas\Locations\Models\Countries;
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
    use Notifiable;

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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(
            PeopleSubscription::class,
            'peoples_id',
            'id'
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
                    ContactType::getById(ContactTypeEnum::EMAIL->value)->getId()
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
                    ContactType::getById(ContactTypeEnum::PHONE->value)->getId()
                )
                ->get();
    }

    public function getCellPhones(): Collection
    {
        return $this->contacts()
                ->where(
                    'contacts_types_id',
                    ContactType::getById(ContactTypeEnum::CELLPHONE->value)->getId()
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

    public function addAddress(DataTransferObjectAddress $address): Address
    {
        return Address::updateOrCreate(
            [
                'peoples_id' => $this->id,
                'address' => $address->address,
                'city' => $address->city,
                'state' => $address->state,
                'countries_id' => $address->country ? Countries::getByName($address->country)->getId() : null,
                'zip' => $address->zipcode,
            ],
            [
                'address_2' => $address->address_2,
            ]
        );
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_deleted == 0;
    }

    public function toSearchableArray(): array
    {
        $people = [
            'objectID' => $this->uuid,
            'id' => $this->id,
            'name' => $this->name,
            'firstname' => $this->firstname,
            'middlename' => $this->middlename,
            'lastname' => $this->lastname,
            'companies_id' => $this->companies_id,
            'dob' => $this->dob,
            'apps_id' => $this->apps_id,
            'users_id' => $this->users_id,
            'files' => $this->getFiles()->take(5)->map(function ($files) { //for now limit
                return [
                    'uuid' => $files->uuid,
                    'name' => $files->name,
                    'url' => $files->url,
                    'size' => $files->size,
                    'field_name' => $files->field_name,
                    'attributes' => $files->attributes,
                ];
            }),
            'organizations' => $this->organizations()->get()->map(function ($organization) {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'tier' => 1,
                ];
            }),
            'employment_history' => $this->employmentHistory()->get()->map(function ($employmentHistory) {
                return [
                    'position' => $employmentHistory->position,
                    'start_date' => $employmentHistory->start_date,
                    'end_date' => $employmentHistory->end_date,
                    'organization' => $employmentHistory->organization,
                ];
            }),
            'tags' => $this->tags->map(function ($tag) {
                return $tag->name;
            }),
            'custom_fields' => $this->customFields()->get()->map(function ($customField) {
                return [
                    $customField->name => $customField->value,
                ];
            }),
            'contacts' => $this->contacts()->get()->map(function ($contact) {
                return [
                    'type' => $contact->type->name,
                    'value' => $contact->value,
                ];
            }),
            'address' => $this->address()->get()->map(function ($address) {
                return [
                    'address' => $address->address,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'country' => $address?->country?->name,
                    'zip' => $address->zip,
                ];
            }),
        ];

        return $people;
    }
}
