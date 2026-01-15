<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DriveCentric;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Services\UserService;
use Tests\Connectors\Traits\HasDriveCentricConfiguration;
use Tests\TestCase;

final class UserServiceTest extends TestCase
{
    use HasDriveCentricConfiguration;

    public function testListUsers(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $userService = new UserService($app, $company);
        $users = $userService->listUsers(start: '2022-01-01', offset: 0);

        $this->assertIsArray($users);
    }

    public function testSearchUsers(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $userService = new UserService($app, $company);
        $users = $userService->searchUsers([
            'email' => 'test@example.com',
        ]);

        $this->assertIsArray($users);
    }

    public function testGetActiveUsers(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);
        /*
                $userService = new UserService($app, $company);
                $activeUsers = $userService->getActiveUsers();

                $this->assertIsArray($activeUsers); */
    }
}
