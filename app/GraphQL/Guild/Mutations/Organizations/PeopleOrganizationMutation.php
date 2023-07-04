<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;

class PeopleOrganizationMutation
{
    public function add(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $data = $req['input'];

        $total = 0;

        $organization = Organization::getByIdFromCompany((int) $data['organization_id'], $user->getCurrentCompany());
        foreach ($data['peoples_id'] as $peopleId) {
            $people = People::getByIdFromCompany($peopleId, $user->getCurrentCompany());

            $organization->addPeople($people);
            $total++;
        }

        return $total > 0;
    }

    public function remove(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $data = $req['input'];

        $total = 0;
        $organization = Organization::getByIdFromCompany((int) $data['organization_id'], $user->getCurrentCompany());

        foreach ($data['peoples_id'] as $peopleId) {
            $people = People::getByIdFromCompany($peopleId, $user->getCurrentCompany());

            OrganizationPeople::where('organizations_id', $organization->getId())
               ->where('peoples_id', $people->getId())
               ->delete();

            $total++;
        }

        return $total > 0;
    }
}
