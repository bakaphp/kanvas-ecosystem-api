<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Contracts\BillableInterface;
use Baka\Contracts\PayeeInterface;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Event\Events\Traits\EventResourceTrait;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Observers\OrganizationObserver;
use Kanvas\Guild\Traits\BillableTrait;
use Kanvas\Guild\Traits\HasNotesChannelTrait;
use Kanvas\Guild\Traits\PayeeTrait;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

/**
 * Class Organization.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property ?string $email = null
 * @property ?string $phone = null
 * @property ?string $address = null
 * @property int $total_employees
 * @property int|null $merged_into_organization_id
 */
#[ObservedBy([OrganizationObserver::class])]
class Organization extends BaseModel implements BillableInterface, PayeeInterface
{
    use HasAdminLink;
    use BillableTrait;
    use CanUseWorkflow;
    use DatabaseSearchableTrait;
    use EventResourceTrait;
    use HasLightHouseCache;
    use HasNotesChannelTrait;
    use HasTagsTrait;
    use PayeeTrait;
    use UuidTrait;

    protected $table = 'organizations';
    protected $guarded = [];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Organization';
    }

    #[Override]
    public function adminLinkSection(): AdminLinkSectionEnum
    {
        return AdminLinkSectionEnum::ORGANIZATION;
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'organization_id');
    }

    public function organizationType(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'organization_type_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'organizations_id', 'id')
            ->where('is_deleted', false);
    }

    /**
     * The Kanvas Users who may approve this organization's AP/AR items.
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(OrganizationApprover::class, 'organizations_id', 'id')
            ->where('is_deleted', false);
    }

    /**
     * Falls back to the default, then to any address: a company that entered exactly one address means it,
     * and requiring them to tag it "Billing" before an invoice renders is bureaucracy, not correctness.
     */
    public function billingAddress(): ?Address
    {
        /** @var Collection<int, Address> $addresses */
        $addresses = $this->addresses()->with('type')->get()->collect();

        $billing = $addresses->first(
            fn (Address $a): bool => $a->type?->name === AddressTypeEnum::BILLING->value,
        );

        return $billing
            ?? $addresses->firstWhere('is_default', true)
            ?? $addresses->first();
    }

    public function peoples(): HasManyThrough
    {
        return $this->hasManyThrough(
            People::class,
            OrganizationPeople::class,
            'organizations_id',
            'id',
            'id',
            'peoples_id'
        );
    }

    public function relationships(): HasManyThrough
    {
        return $this->hasManyThrough(
            OrganizationRelated::class,
            OrganizationRelationshipType::class,
            'organizations_id',
            'id',
            'id',
            'organizations_relations_type_id'
        );
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_organization_id', 'id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'customer_organization_id', 'id');
    }

    public function salesReceipts(): HasMany
    {
        return $this->hasMany(SalesReceipt::class, 'customer_organization_id', 'id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'vendor_organization_id', 'id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'vendor_organization_id', 'id');
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function addPeople(People $people): OrganizationPeople
    {
        return OrganizationPeople::firstOrCreate([
            'organizations_id' => $this->getId(),
            'peoples_id' => $people->getId(),
        ], [
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_organization_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'organization_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'total_employees' => $this->total_employees,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function getPhoto(): ?FilesystemEntities
    {
        $app = app(Apps::class);
        $defaultAvatarId = $app->get(AppSettingsEnums::DEFAULT_COMPANY_AVATAR->getValue());

        return $this->getFileByName('photo') ?: ($defaultAvatarId ? FilesystemEntitiesRepository::getFileFromEntityById($defaultAvatarId) : null);
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
