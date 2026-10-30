<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BillableInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\PayeeInterface;
use Baka\Enums\StateEnums;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Baka\Validations\EmailDomain;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Kanvas\Analytics\Reporting\Observers\ReportSourceObserver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Support\LocationContext;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Event\Events\Concerns\HasEventResources;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Guild\Concerns\HasNotesChannel;
use Kanvas\Guild\Concerns\IsBillable;
use Kanvas\Guild\Concerns\IsPayee;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Observers\OrganizationObserver;
use Kanvas\Intelligence\Agents\Contracts\ProvidesAgentContext;
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
#[ObservedBy([OrganizationObserver::class, ReportSourceObserver::class])]
class Organization extends BaseModel implements BillableInterface, PayeeInterface, ProvidesAgentContext
{
    use HasAdminLink;
    use IsBillable;
    use CanUseWorkflow;
    use DatabaseSearchableTrait;
    use HasEventResources;
    use HasLightHouseCache;
    use HasNotesChannel;
    use HasTagsTrait;
    use IsPayee;
    use UuidTrait;

    public const string EMAIL_DOMAINS_CUSTOM_FIELD = 'email_domains';

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

    /**
     * What an agent grounds itself on when it is dropped on a customer account. The difference between
     * "Acme Corp" and "Acme Corp, growth tier, using commerce and logistics, Spanish-speaking" — every
     * agent gets it, not just the one that asked for it.
     *
     * Deliberately absent: the newsletter watermark and the linked tenant ids. Those are feature state
     * for one workflow, not something the CFO agent should read off the same account.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function agentContextBrief(): array
    {
        return array_filter([
            'type' => 'Organization',
            'id' => $this->getId(),
            'name' => $this->name,
            'modules' => $this->tags()->pluck('slug')->all(),
            'tier' => $this->get('customer_tier'),
            'locale' => $this->get('customer_locale'),
            'total_employees' => $this->total_employees > 0 ? $this->total_employees : null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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
     * @return list<string>
     */
    public function emailDomains(): array
    {
        return array_values((array) ($this->get(self::EMAIL_DOMAINS_CUSTOM_FIELD) ?? []));
    }

    /**
     * Additive on purpose: domains reach an organization from several places (the CRM's own domain
     * field, its website, its contacts' emails), so a sync of one source must not drop the others.
     *
     * @param iterable<string|null> $values emails, URLs or bare domains
     *
     * @return list<string> the domains this organization now answers to
     */
    public function addEmailDomains(iterable $values): array
    {
        $current = $this->emailDomains();
        $domains = $current;

        foreach ($values as $value) {
            $domain = EmailDomain::companyDomain($value);
            if ($domain !== null && ! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        if ($domains !== $current) {
            $this->set(self::EMAIL_DOMAINS_CUSTOM_FIELD, $domains);
        }

        return $domains;
    }

    /**
     * More than one result is a real answer, not noise: two organizations sharing a domain (a
     * duplicate pair, a parent and its brand) can't be told apart from the email alone, so callers
     * must not pick one for the person.
     *
     * The field holds a JSON array, so the domain is matched with its quotes: `"acme.com"` can't
     * match `xacme.com`. A normalized domain is only [a-z0-9.-], so it carries no LIKE wildcard.
     *
     * @return Collection<int, Organization>
     */
    public static function findByEmailDomain(AppInterface $app, CompanyInterface $company, string $email): Collection
    {
        $domain = EmailDomain::companyDomain($email);
        if ($domain === null) {
            return collect();
        }

        $ids = AppsCustomFields::query()
            ->whereIn('model_name', static::customFieldModelNames())
            ->where('companies_id', $company->getId())
            ->where('name', self::EMAIL_DOMAINS_CUSTOM_FIELD)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('value', 'like', '%"' . $domain . '"%')
            ->pluck('entity_id')
            ->all();

        return self::query()
            ->whereIn('id', $ids)
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->limit(10)
            ->get()
            ->collect();
    }

    /**
     * A person who already belongs to an organization is left alone: the domain is a guess, the
     * existing link was a decision.
     *
     * @return Collection<int, Organization> the one organization the person was linked to, or the
     *                                       candidates of the first ambiguous domain (nothing linked)
     */
    public static function linkPersonByEmailDomain(People $people): Collection
    {
        if ($people->organizations()->exists()) {
            return collect();
        }

        $candidates = collect();

        foreach ($people->getEmails() as $email) {
            $matches = self::findByEmailDomain($people->app, $people->company, (string) $email->value);

            if ($matches->count() === 1) {
                $matches->first()->addPeople($people);

                return $matches;
            }

            if ($candidates->isEmpty()) {
                $candidates = $matches;
            }
        }

        return $candidates;
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

        $company = LocationContext::searchCompany(static::class);
        if ($company !== null) {
            $query->where('companies_id', $company->getId());
        }

        return $query;
    }
}
