<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * The organization a person is billed through: the one they already belong to, or a new one named
 * after them that someone can rename or reassign later.
 */
final class EnsurePersonOrganizationAction
{
    public function __construct(
        private readonly People $people
    ) {
    }

    public function execute(): Organization
    {
        /** @var Organization|null $existing */
        $existing = $this->people->organizations()
            ->where('organizations.is_deleted', 0)
            ->orderBy('organizations.id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $name = Str::trimToNull($this->people->getName()) ?? 'Customer';
        $organization = $this->named($name);

        // CreateOrganizationAction treats a name match as the same party. This name is one the person
        // chose for themselves, so a match is a namesake at best and must not take them in.
        if (! $organization->wasRecentlyCreated) {
            $organization = $this->named($name . ' #' . $this->people->getId());
        }

        $organization->addPeople($this->people);

        return $organization;
    }

    private function named(string $name): Organization
    {
        return new CreateOrganizationAction(
            new OrganizationData(
                company: $this->people->company,
                user: $this->people->user,
                app: $this->people->app,
                name: $name,
            )
        )->execute();
    }
}
