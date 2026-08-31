<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Guild\Customers\DataTransferObject\Address as DataTransferObjectAddress;
use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Locations\Models\Countries;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Social\Interactions\Traits\LikableTrait;
use Kanvas\Social\Interactions\Traits\SocialInteractionsTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
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
 * @property string|null $firstname
 * @property string|null $middlename = null
 * @property string|null $lastname
 * @property string|null $license_number
 * @property string|null $license_expiration_date
 * @property string|null $license_state
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
    use HasAdminLink;
    use CanUseWorkflow;
    use CascadeSoftDeletes;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use HasLightHouseCache;
    use HasTagsTrait;
    use LikableTrait;
    use Notifiable;
    use SocialInteractionsTrait;
    use SoftDeletesTrait;
    use UuidTrait;

    public const string DELETED_AT = 'is_deleted';

    protected $table = 'peoples';
    protected $guarded = [];

    protected $cascadeDeletes = ['contacts', 'address'];

    protected $casts = [
        'dob' => 'datetime:Y-m-d',
        'license_expiration_date' => 'datetime:Y-m-d',
        'is_deleted' => 'boolean',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function trashed()
    {
        return (bool) $this->{$this->getDeletedAtColumn()};
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'People';
    }

    #[Override]
    public function adminLinkSection(): AdminLinkSectionEnum
    {
        return AdminLinkSectionEnum::PEOPLE;
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
        )->notDeleted()
            ->orderBy('created_at', 'desc');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(
            Order::class,
            'peoples_id',
            'id'
        )->orderBy('created_at', 'desc');
    }

    public function peopleType(): BelongsTo
    {
        return $this->belongsTo(PeopleType::class, 'people_types_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'people_id', 'id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'contact_people_id', 'id');
    }

    /**
     * Structured address shape `{address, address_2, city, state, zip, country}` for the BILLING-typed
     * row in `peoples_address`. Returns null when no BILLING address is on file. Consumed by
     * `Organization::getBillingAddressArray()` and snapshot freezers at invoice/bill issue time.
     *
     * @return array<string, mixed>|null
     */
    public function getBillingAddressArray(): ?array
    {
        $typeId = AddressType::query()
            ->where('name', AddressTypeEnum::BILLING->value)
            ->value('id');

        /** @var Address|null $row */
        $row = $this->address()
            ->when($typeId !== null, fn ($q) => $q->where('address_type_id', $typeId))
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'address' => (string) ($row->address ?? '') ?: null,
            'address_2' => (string) ($row->address_2 ?? '') ?: null,
            'city' => (string) ($row->city ?? '') ?: null,
            'state' => (string) ($row->state ?? '') ?: null,
            'zip' => (string) ($row->zip ?? '') ?: null,
            'country' => $row->countries_id !== null ? (string) $row->countries_id : null,
        ];
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
    public function organizations(): BelongsToMany
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

    public function getAllPhones(): Collection
    {
        $cellphoneTypeId = ContactType::getByName(ContactTypeEnum::CELLPHONE->getName())->getId();
        $phoneTypeId = ContactType::getByName(ContactTypeEnum::PHONE->getName())->getId();

        return $this->contacts()
                ->whereIn('contacts_types_id', [$phoneTypeId, $cellphoneTypeId])
                ->orderByRaw("FIELD(contacts_types_id, {$cellphoneTypeId}, {$phoneTypeId})")
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

        $stored = Address::updateOrCreate(
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
                'is_default' => (int) $address->is_default,
            ]
        );

        // Keeps "a person with addresses has exactly one current address" true structurally, so
        // a guest checkout or an ID scan that only ever calls addAddress() still resolves.
        $this->ensureDefaultAddress();

        return $stored->refresh();
    }

    public function addDefaultAddress(DataTransferObjectAddress $address): Address
    {
        $address = $this->addAddress($address);
        $address->is_default = 1;
        $address->saveOrFail();

        // A person has exactly one current address; leaving stale defaults behind makes
        // readers resolve the oldest row (usually a previous home) as the current one.
        $this->address()
            ->where('id', '!=', $address->getKey())
            ->where('is_default', 1)
            ->update(['is_default' => 0]);

        return $address;
    }

    public function getDefaultAddress(): ?Address
    {
        /** @var Address|null $address */
        $address = $this->address()->where('is_default', 1)->orderByDesc('id')->first()
            ?? $this->address()->orderByDesc('id')->first();

        return $address;
    }

    /**
     * `is_default` is opt-in on both the DTO and the column, so a person whose addresses all
     * arrived through `addAddress()` would otherwise carry no current address at all. Persists
     * the fallback `getDefaultAddress()` already resolves to.
     */
    public function ensureDefaultAddress(): ?Address
    {
        $address = $this->getDefaultAddress();

        if ($address !== null && ! $address->is_default) {
            $address->is_default = 1;
            $address->saveOrFail();
        }

        return $address;
    }

    public function addEmail(string $email, int $isOptOut = 0, int $weight = 0): Contact
    {
        return Contact::updateOrCreate(
            [
                'peoples_id' => $this->id,
                'value' => $email,
                'contacts_types_id' => ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId(),
            ],
            [
                'is_opt_out' => $isOptOut,
                'weight' => $weight,
            ]
        );
    }

    public function addPhone(string $phone, int $isOptOut = 0, int $weight = 0): Contact
    {
        return Contact::updateOrCreate(
            [
                'peoples_id' => $this->id,
                'value' => $phone,
                'contacts_types_id' => ContactType::getByName(ContactTypeEnum::PHONE->getName())->getId(),
            ],
            [
                'is_opt_out' => $isOptOut,
                'weight' => $weight,
            ]
        );
    }

    public function addCellPhone(string $phone, int $isOptOut = 0, int $weight = 0): Contact
    {
        return Contact::updateOrCreate(
            [
                'peoples_id' => $this->id,
                'value' => $phone,
                'contacts_types_id' => ContactType::getByName(ContactTypeEnum::CELLPHONE->getName())->getId(),
            ],
            [
                'is_opt_out' => $isOptOut,
                'weight' => $weight,
            ]
        );
    }

    public function optOutPhoneContacts(): int
    {
        $phoneTypes = [
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::WORK_PHONE->value,
        ];

        return $this->contacts()
            ->whereIn('contacts_types_id', $phoneTypes)
            ->update(['is_opt_out' => 1]);
    }

    public function setPhoneOptOut(string $phoneNumber, bool $optOut = true): int
    {
        $normalizedPhone = Contact::normalizeValue(
            $phoneNumber,
            ContactTypeEnum::CELLPHONE->value,
        );

        return $this->contacts()
            ->whereIn('contacts_types_id', Contact::PHONE_TYPES)
            ->get()
            ->filter(
                fn (Contact $contact): bool => Contact::normalizeValue(
                    $contact->value,
                    $contact->contacts_types_id,
                ) === $normalizedPhone,
            )
            ->each(function (Contact $contact) use ($optOut): void {
                $contact->is_opt_out = (int) $optOut;
                $contact->saveOrFail();
            })
            ->count();
    }

    public static function findByEmailOrCreate(
        string $email,
        Companies $company,
        Users $user,
        ?string $name = null,
        ?Apps $app = null
    ): self {
        $app = $app ?? app(Apps::class);

        // Try to find existing person by email
        $person = self::whereHas('contacts', function ($query) use ($email) {
            $query->where('value', $email)
                  ->where('contacts_types_id', ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId());
        })->where('apps_id', $app->getId())
          ->first();

        if (! $person) {
            // Create new person if not found
            $person = new self();
            $person->apps_id = $app->getId();
            $person->companies_id = $company->getId();
            $person->users_id = $user->getId();
            $person->name = $name ?? explode('@', $email)[0];

            // Extract name parts if provided
            if ($name) {
                $nameParts = explode(' ', trim($name));
                $person->firstname = $nameParts[0] ?? '';
                $person->lastname = count($nameParts) > 1 ? end($nameParts) : '';
                if (count($nameParts) > 2) {
                    $person->middlename = implode(' ', array_slice($nameParts, 1, -1));
                }
            }

            $person->save();

            // Add email contact
            $person->addEmail($email);
        }

        return $person;
    }

    public static function findByPhoneOrCreate(
        string $phone,
        Companies $company,
        Users $user,
        ?string $name = null,
        ?Apps $app = null
    ): self {
        $app = $app ?? app(Apps::class);

        $phoneContactTypeIds = [
            ContactType::getByName(ContactTypeEnum::CELLPHONE->getName())->getId(),
            ContactType::getByName(ContactTypeEnum::PHONE->getName())->getId(),
            //ContactType::getByName(ContactTypeEnum::WORK_PHONE->getName())->getId(),
        ];

        $person = self::whereHas('contacts', function ($query) use ($phone, $phoneContactTypeIds) {
            $query->whereRaw("REGEXP_REPLACE(value, '[^0-9]', '') = REGEXP_REPLACE(?, '[^0-9]', '')", [$phone])
                  ->whereIn('contacts_types_id', $phoneContactTypeIds);
        })->where('apps_id', $app->getId())
          ->first();

        if (! $person) {
            // Create new person if not found
            $person = new self();
            $person->apps_id = $app->getId();
            $person->companies_id = $company->getId();
            $person->users_id = $user->getId();
            $person->name = $name ?? 'Unknown';

            // Extract name parts if provided
            if ($name) {
                $nameParts = explode(' ', trim($name));
                $person->firstname = $nameParts[0] ?? '';
                $person->lastname = count($nameParts) > 1 ? end($nameParts) : '';
                if (count($nameParts) > 2) {
                    $person->middlename = implode(' ', array_slice($nameParts, 1, -1));
                }
            }

            $person->save();

            // Add phone contact
            $person->addPhone($phone);
        }

        return $person;
    }

    /**
     * Get person by email.
     */
    public static function getByEmail(string $email, ?Apps $app = null): ?self
    {
        $app = $app ?? app(Apps::class);

        return self::whereHas('contacts', function ($query) use ($email) {
            $query->where('value', $email)
                  ->where('contacts_types_id', ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId());
        })->where('apps_id', $app->getId())
          ->where('is_deleted', 0)
          ->first();
    }

    /**
     * Get person by phone matching (strips non-numeric characters for comparison).
     */
    public static function getByPhoneMatchingValue(string $phone, Companies $company, Apps $app): ?self
    {
        return self::whereHas('contacts', function ($query) use ($phone) {
            $query->whereRaw("REGEXP_REPLACE(value, '[^0-9]', '') = REGEXP_REPLACE(?, '[^0-9]', '')", [$phone])
                  ->whereIn('contacts_types_id', [
                      ContactType::getByName(ContactTypeEnum::PHONE->getName())->getId(),
                      ContactType::getByName(ContactTypeEnum::CELLPHONE->getName())->getId(),
                  ]);
        })->where('apps_id', $app->getId())
          ->where('companies_id', $company?->getId())
          ->where('is_deleted', 0)
          ->first();
    }

    public static function getByMatchingValue(string $value, Companies $company, Apps $app): ?self
    {
        return self::whereHas('contacts', function ($query) use ($value) {
            $query->where('value', $value);
        })
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * Get all people by phone matching (strips non-numeric characters for comparison).
     */
    public static function getAllByPhoneMatchingValue(string $phone, Companies $company, Apps $app): Collection
    {
        return self::whereHas(
            'contacts',
            fn ($query) => $query->whereRaw("REGEXP_REPLACE(value, '[^0-9]', '') = REGEXP_REPLACE(?, '[^0-9]', '')", [$phone])
                ->whereIn('contacts_types_id', [
                    ContactType::getByName(ContactTypeEnum::PHONE->getName())->getId(),
                    ContactType::getByName(ContactTypeEnum::CELLPHONE->getName())->getId(),
                ])
        )->where('apps_id', $app->getId())
          ->where('companies_id', $company?->getId())
          ->where('is_deleted', 0)
          ->get();
    }

    public static function getAllByMatchingValue(string $value, Companies $company, Apps $app): Collection
    {
        return self::whereHas(
            'contacts',
            fn ($query) => $query->where('value', $value)
        )
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->get();
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
            'id' => (string) $this->id,
            'name' => (string) $this->name,
            'firstname' => (string) $this->firstname,
            'middlename' => (string) $this->middlename,
            'lastname' => (string) $this->lastname,
            'companies_id' => $this->companies_id,
            'email' => $this->getEmails()->first()?->value,
            'phone' => $this->getAllPhones()->first()?->value,
            'dob' => $this->dob,
            'apps_id' => $this->apps_id,
            'users_id' => $this->users_id,
            'created_at' => $this->created_at->getTimestamp(),
            'updated_at' => $this->updated_at->getTimestamp(),
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
            'custom_fields' => []/* $this->customFields()->get()->map(function ($customField) {
                return [
                    $customField->name => $customField->value,
                ];
            }) */,
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
                    'type' => 'string',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'email',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'phone',
                    'type' => 'string',
                    'optional' => true,
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
                    'type' => 'int64',
                    'sort' => true,
                ],
                [
                    'name' => 'updated_at',
                    'type' => 'int64',
                    'optional' => true,
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

    public static function search($query = '', $callback = null)
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $app->fireWorkflow(
            event: WorkflowEnum::SEARCH->value,
            params: [
                'search' => trim($query),
                'search_type' => 'people',
                'user' => $user instanceof UserInterface ? $user : null,
                'company' => $user instanceof UserInterface ? $user->getCurrentCompany() : null,
            ]
        );

        $query = self::traitSearch($query, $callback)->where('apps_id', $app->getId());

        /*         if ($user instanceof UserInterface && ! $user->isAppOwner()) {
                    $query->where('companies_id', $user->getCurrentCompany()->getId());
                } */

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options([
                'query_by' => 'name,firstname,lastname,email,phone',
            ]);
        }

        return $query;
    }

    public function getDriverLicense(): ?DriverLicense
    {
        $legacyScan = $this->get('get_docs_drivers_license');
        $legacy = is_array($legacyScan) ? DriverLicense::fromScan($legacyScan) : null;

        $number = $this->license_number
            ?: ($this->get('drivers_license_number') ?: $legacy?->number);

        if (empty($number)) {
            return null;
        }

        $state = DriverLicense::normalizeState($this->license_state) ?? $legacy?->state;
        if ($state === null) {
            $state = DriverLicense::normalizeState($this->getDefaultAddress()?->state);
        }

        return new DriverLicense(
            number: (string) $number,
            state: $state,
            expirationDate: $this->license_expiration_date
                ? Carbon::parse($this->license_expiration_date)
                : $legacy?->expirationDate,
            dob: $this->dob ? Carbon::parse($this->dob) : $legacy?->dob,
            firstname: $this->firstname,
            middlename: $this->middlename,
            lastname: $this->lastname,
            address: $legacy?->address,
        );
    }

    /**
     * @return array{driversLicenseNumber: string, driversLicenseState: string|null}|null
     */
    public function getDriverLicenseData(): ?array
    {
        return $this->getDriverLicense()?->toDriveCentricArray();
    }

    public function getPhoto(): ?FilesystemEntities
    {
        $app = app(Apps::class);
        $defaultAvatarId = $app->get(AppSettingsEnums::DEFAULT_USER_AVATAR->getValue());

        return $this->getFileByName('photo') ?: ($defaultAvatarId ? FilesystemEntitiesRepository::getFileFromEntityById($defaultAvatarId) : null);
    }
}
