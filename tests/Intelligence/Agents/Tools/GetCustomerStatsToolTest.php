<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetCustomerStatsTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class GetCustomerStatsToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function test_returns_totals_and_most_recent_customers(): void
    {
        $baseline = (int) Organization::query()
            ->fromApp($this->currentApp)
            ->fromCompany($this->currentCompany)
            ->notDeleted()
            ->count();

        // A far-future created_at guarantees this org sorts first in the recent list.
        $newest = $this->seedOrg('NewestCustomerUniq', Carbon::parse('2031-05-01'));

        $result = new GetCustomerStatsTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke(limit: 5);

        $this->assertSame($baseline + 1, $result['total_customers']);
        $this->assertArrayHasKey('total_people', $result);
        $this->assertSame((int) $newest->getId(), $result['recent_customers'][0]['organization_id']);
        $this->assertSame('NewestCustomerUniq', $result['recent_customers'][0]['name']);
    }

    private function seedOrg(string $name, Carbon $createdAt): Organization
    {
        return Organization::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
            'created_at' => $createdAt,
        ]);
    }
}
