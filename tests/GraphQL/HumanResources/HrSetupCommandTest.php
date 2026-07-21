<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Leave\Models\LeaveType;
use Tests\TestCase;

class HrSetupCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'hr', 'intelligence'];

    private function runSetup(): void
    {
        $user = auth()->user();

        $this->artisan('kanvas-hr:setup', [
            'app_id' => app(Apps::class)->getId(),
            'user_id' => $user->getId(),
            'company_id' => $user->getCurrentCompany()->getId(),
        ])->assertSuccessful();
    }

    private function seededCount(): int
    {
        $user = auth()->user();

        return LeaveType::query()
            ->fromApp(app(Apps::class))
            ->fromCompany($user->getCurrentCompany())
            ->notDeleted()
            ->whereIn('name', ['Vacation', 'Sick Leave', 'Personal', 'Unpaid Leave'])
            ->count();
    }

    public function testSetupSeedsDefaultLeaveTypes(): void
    {
        $this->runSetup();

        $this->assertEquals(4, $this->seededCount());
    }

    public function testSetupIsIdempotent(): void
    {
        $this->runSetup();
        $this->runSetup();

        $this->assertEquals(4, $this->seededCount());
    }
}
