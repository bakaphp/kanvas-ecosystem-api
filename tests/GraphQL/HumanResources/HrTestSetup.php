<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Positions\Actions\CreatePositionAction;
use Kanvas\HumanResources\Positions\DataTransferObject\Position as PositionData;
use Kanvas\HumanResources\Positions\Models\Position;
use Kanvas\Users\Models\Users;

/**
 * Prerequisite factories for HR tests — every Employee now requires a User, People, Position and hire date.
 */
trait HrTestSetup
{
    protected function hrApp(): Apps
    {
        return app(Apps::class);
    }

    protected function hrCompany(): CompanyInterface
    {
        return auth()->user()->getCurrentCompany();
    }

    protected function makePosition(?string $title = null): Position
    {
        return new CreatePositionAction(
            new PositionData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                user: auth()->user(),
                title: $title ?? ('Engineer ' . fake()->unique()->word()),
            ),
        )->execute();
    }

    protected function makeUser(): Users
    {
        return new RegisterUsersAction(
            RegisterInput::from([
                'email' => fake()->unique()->safeEmail(),
                'password' => fake()->password(8),
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ]),
        )->execute();
    }

    protected function makePeople(Users $user): People
    {
        return People::factory()->create([
            'apps_id' => $this->hrApp()->getId(),
            'companies_id' => $this->hrCompany()->getId(),
            'users_id' => $user->getId(),
        ]);
    }

    protected function makeEmployeeInput(array $overrides = []): array
    {
        $user = $this->makeUser();
        $people = $this->makePeople($user);
        $position = $this->makePosition();

        return array_merge([
            'users_id' => (string) $user->getId(),
            'people_id' => (string) $people->getId(),
            'position_id' => (string) $position->getId(),
            'hired_at' => '2026-01-15',
            'employee_number' => 'E-' . fake()->unique()->numerify('#####'),
        ], $overrides);
    }
}
