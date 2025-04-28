<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\DynamicSearchableTrait;
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
use Override;

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
    use DynamicSearchableTrait;
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

    #[Override]
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
     * @deprecated
     * @param bool $middleName
     */
    public function getFirstAndLastName($middleName = false): array
    {
        $name = explode(' ', $this->name);
        $firstName = $name[0];
        unset($name[0]);

        return [
            'firstName' => $this->firstname ?? trim($firstName),
            'middleName' => $this->middlename ?? null, //if there is no middle name we will return '
            'lastName' => $this->lastname ?? (isset($name[1]) ? implode(' ', $name) : ''),
        ];
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

    #[Override]
    protected static function newFactory()
    {
        return new PeopleFactory();
    }

    public function addAddress(DataTransferObjectAddress $address): Address
    {
        $typeId = $address->address_type_id ?? AddressType::getByName(AddressTypeEnum::HOME->value, $this->app)->getId();

        return Address::updateOrCreate(
            [
                'peoples_id' => $this->id,
                'address' => $address->address,
                'city' => $address->city,
                'state' => $address->state,
                'countries_id' => $address->country ? Countries::getByName($address->country)->getId() : null,
                'zip' => $address->zip,
            ],
            [
                'address_2' => $address->address_2,
                'address_type_id' => $typeId, // @todo move to search
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

    #[Override]
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

    /**
     * The Typesense schema to be created for the People model.
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'firstname',
                    'type' => 'string',
                    'sort' => true,
                ],
                [
                    'name' => 'middlename',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'lastname',
                    'type' => 'string',
                    'sort' => true,
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'dob',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'users_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'created_at',
                    'type' => 'string',
                ],
                [
                    'name' => 'updated_at',
                    'type' => 'string',
                ],
                [
                    'name' => 'files',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'organizations',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'employment_history',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'tags',
                    'type' => 'string[]',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'custom_fields',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'contacts',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'address',
                    'type' => 'object[]',
                    'optional' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,  // Enable nested fields support for complex objects
        ];
    }
}
