<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleRelationshipAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleRelationshipAction;
use Kanvas\Guild\Customers\DataTransferObject\PeopleRelationship as PeopleRelationshipData;
use Kanvas\Guild\Customers\Models\PeopleRelationship;

class PeopleRelationshipMutation
{
    public function create(mixed $root, array $req): PeopleRelationship
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        return new CreatePeopleRelationshipAction(
            new PeopleRelationshipData(
                app: $app,
                company: $company,
                name: $input['name'],
                description: $input['description'] ?? null,
            ),
        )->execute();
    }

    public function update(mixed $root, array $req): PeopleRelationship
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var PeopleRelationship $relationship */
        $relationship = PeopleRelationship::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return new UpdatePeopleRelationshipAction(
            $relationship,
            new PeopleRelationshipData(
                app: $app,
                company: $company,
                name: $input['name'] ?? $relationship->name,
                description: $input['description'] ?? $relationship->description,
            ),
        )->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var PeopleRelationship $relationship */
        $relationship = PeopleRelationship::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return $relationship->softDelete();
    }
}
