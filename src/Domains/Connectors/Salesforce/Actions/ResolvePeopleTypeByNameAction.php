<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Guild\Customers\Actions\CreatePeopleTypeAction;
use Kanvas\Guild\Customers\DataTransferObject\PeopleType as PeopleTypeData;
use Kanvas\Guild\Customers\Models\PeopleType;

/**
 * Resolves a Salesforce role/relationship picklist value (Location__c's Location_Contact_Type__c,
 * Project__c's Relationship__c) to a Kanvas PeopleType, creating it on first sight. Shared so both
 * pull actions always reuse the same PeopleType row for the same role text instead of drifting on
 * casing/whitespace across imports — `CreatePeopleTypeAction` itself always inserts, it never dedupes.
 *
 * people_types' only unique constraint is on (slug, apps_id, companies_id), and SlugTrait
 * auto-disambiguates a slug collision with a suffix rather than failing — so two calls that both
 * miss the lookup before either commits (a bulk import processing many contacts that share a role)
 * don't collide on that constraint, they each happily insert their own row under the same `name`.
 * Same shape as `Connectors\Odoo\Actions\PullOrganizationAction`'s lock: serialize on the name
 * instead of relying on a DB constraint that doesn't actually cover it.
 */
class ResolvePeopleTypeByNameAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(string $name): PeopleType
    {
        $name = trim($name);
        $lockKey = 'people_type_resolve:' . $this->app->getId() . ':' . $this->company->getId() . ':' . $name;

        return Cache::lock($lockKey, 10)->block(5, function () use ($name) {
            return PeopleType::where('name', $name)
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->first()
                ?? new CreatePeopleTypeAction(
                    new PeopleTypeData(
                        apps: $this->app,
                        companies: $this->company,
                        user: $this->user,
                        name: $name,
                    ),
                )->execute();
        });
    }
}
