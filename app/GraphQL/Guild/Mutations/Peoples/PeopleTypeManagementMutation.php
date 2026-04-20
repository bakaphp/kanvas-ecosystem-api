<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleTypeAction;
use Kanvas\Guild\Customers\DataTransferObject\PeopleType;
use Kanvas\Guild\Customers\Models\PeopleType as PeopleTypeModel;

class PeopleTypeManagementMutation
{
    public function create(mixed $root, array $req): PeopleTypeModel
    {
        $data = $req['input'];
        $data['apps'] = app(Apps::class);
        $data['companies'] = auth()->user()->getCurrentCompany();
        $data['user'] = auth()->user();

        return new CreatePeopleTypeAction(
            PeopleType::from($data),
        )->execute();
    }

    public function update(mixed $root, array $req): PeopleTypeModel
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $req['input'];

        /** @var PeopleTypeModel $peopleType */
        $peopleType = PeopleTypeModel::getByIdFromCompanyApp((int) $req['id'], $company, $app);
        $peopleType->update($input);

        return $peopleType;
    }

    public function delete(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var PeopleTypeModel $peopleType */
        $peopleType = PeopleTypeModel::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return $peopleType->softDelete();
    }
}
