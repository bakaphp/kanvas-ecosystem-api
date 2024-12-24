<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\Address as DataTransferObjectAddress;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Locations\Models\Countries;
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
    use Notifiable;
    use HasLightHouseCache;

    protected $table = 'peoples';
    protected $guarded = [];

    protected $casts = [
        'dob' => 'datetime:Y-m-d',
    ];

    public function getGraphTypeName(): string
    {
        return 'People';
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
            Contact::class,
            'peoples_id',
            'id'
        );
    }

    public function leads(): HasMany
    {
        return $this->hasMany(
            Lead::class,
            'people_id',
            'id'
        )->orderBy('created_at', 'desc');
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
                    ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId()
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
                    ContactType::getByName(ContactTypeEnum::PHONE->getName())->getId()
                )
                ->get();
    }

    public function getCellPhones(): Collection
    {
        return $this->contacts()
                ->where(
                    'contacts_types_id',
                    ContactType::getByName(ContactTypeEnum::CELLPHONE->getName())->getId()
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
        $type = $address->type ?? AddressType::getByName(AddressTypeEnum::HOME->value);

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
                'address_type_id' => $type->getId(), //@todo move this to the search
            ]
        );
    }

    public function addEmail(string $email): Contact
    {
        return Contact::updateOrCreate(
            [
                'peoples_id' => $this->id,
                'value' => $email,
                'contacts_types_id' => ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId(),
            ]
        );
    }

    public function addPhone(string $phone): Contact
    {
        return Contact::updateOrCreate(
            [
                'peoples_id' => $this->id,
                'value' => $phone,
                'contacts_types_id' => ContactType::getByName(ContactTypeEnum::PHONE->getName())->getId(),
            ]
        );
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_deleted == 0;
    }

    public function searchableAs(): string
    {
        //$people = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $people = ! $this->searchableDeleteRecord() ? $this : $this->find($this->id);
        $app = $people->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_people_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'peoples');
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
