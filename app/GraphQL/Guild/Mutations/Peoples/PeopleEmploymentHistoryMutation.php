<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleEmploymentHistoryAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleEmploymentHistoryAction;
use Kanvas\Guild\Customers\DataTransferObject\PeopleEmploymentHistory as PeopleEmploymentHistoryData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;

class PeopleEmploymentHistoryMutation
{
    public function create(mixed $root, array $req): PeopleEmploymentHistory
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        if (empty($input['peoples_id'])) {
            throw ValidationException::withMessages([
                'peoples_id' => 'The peoples_id field is required to create an employment history.',
            ]);
        }

        $people = People::getByIdFromCompanyApp((int) $input['peoples_id'], $company, $app);
        $organization = Organization::getByIdFromCompanyApp((int) $input['organizations_id'], $company, $app);

        return new CreatePeopleEmploymentHistoryAction(
            new PeopleEmploymentHistoryData(
                app: $app,
                people: $people,
                organization: $organization,
                position: $input['position'],
                status: (int) $input['status'],
                start_date: $this->normalizeDate($input['start_date'] ?? null),
                end_date: $this->normalizeDate($input['end_date'] ?? null),
                income: isset($input['income']) ? (float) $input['income'] : null,
                income_type: $input['income_type'] ?? null,
            )
        )->execute();
    }

    public function update(mixed $root, array $req): PeopleEmploymentHistory
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $req['input'];

        $employmentHistory = $this->getScopedEmploymentHistory((int) $req['id']);
        $organization = Organization::getByIdFromCompanyApp((int) $input['organizations_id'], $company, $app);

        return new UpdatePeopleEmploymentHistoryAction(
            $employmentHistory,
            new PeopleEmploymentHistoryData(
                app: $app,
                people: $employmentHistory->people,
                organization: $organization,
                position: $input['position'],
                status: (int) $input['status'],
                start_date: $this->normalizeDate($input['start_date'] ?? null),
                end_date: $this->normalizeDate($input['end_date'] ?? null),
                income: isset($input['income']) ? (float) $input['income'] : null,
                income_type: $input['income_type'] ?? null,
            )
        )->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        return (bool) $this->getScopedEmploymentHistory((int) $req['id'])->delete();
    }

    protected function getScopedEmploymentHistory(int $id): PeopleEmploymentHistory
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $employmentHistory = PeopleEmploymentHistory::getById($id);
        $people = $employmentHistory->people;

        if ($people->companies_id !== $user->getCurrentCompany()->getId() || $people->apps_id !== $app->getId()) {
            throw new AuthorizationException('You do not have permission to manage this employment history');
        }

        return $employmentHistory;
    }

    private function normalizeDate(mixed $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return (string) $date;
    }
}
