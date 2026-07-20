<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Position;

use App\GraphQL\HumanResources\Concerns\ResolvesActingContext;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Positions\Actions\CreatePositionAction;
use Kanvas\HumanResources\Positions\Actions\UpdatePositionAction;
use Kanvas\HumanResources\Positions\DataTransferObject\Position as PositionData;
use Kanvas\HumanResources\Positions\Models\Position;

class PositionMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): Position
    {
        [$user, $app, $company] = $this->actingContext();
        $input = $request['input'];

        return new CreatePositionAction(
            new PositionData(
                app: $app,
                company: $company,
                user: $user,
                title: $input['title'],
                department: $this->resolveDepartment($input, $company, $app),
                level: $input['level'] ?? null,
                description: $input['description'] ?? null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Position
    {
        [$user, $app, $company] = $this->actingContext();
        $input = $request['input'];

        /** @var Position $position */
        $position = Position::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $department = isset($input['department_id'])
            ? $this->resolveDepartment($input, $company, $app)
            : $position->department;

        return new UpdatePositionAction(
            $position,
            new PositionData(
                app: $app,
                company: $company,
                user: $user,
                title: $input['title'] ?? $position->title,
                department: $department,
                level: $input['level'] ?? $position->level,
                description: $input['description'] ?? $position->description,
                isActive: $input['is_active'] ?? $position->is_active,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        [, $app, $company] = $this->actingContext();

        $position = Position::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return $position->softDelete();
    }

    private function resolveDepartment(array $input, CompanyInterface $company, Apps $app): ?Department
    {
        if (! isset($input['department_id'])) {
            return null;
        }

        /** @var Department $department */
        $department = Department::getByIdFromCompanyApp((int) $input['department_id'], $company, $app);

        return $department;
    }
}
