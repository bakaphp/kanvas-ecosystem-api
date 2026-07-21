<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListStaleLeadsTool;
use Tests\TestCase;

class ListStaleLeadsToolTest extends TestCase
{
    public function testListsOnlyStaleOpenLeads(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $stale = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Stale open lead ' . uniqid(),
            'status' => 0,
        ]);
        $this->backdate($stale, 30);

        $fresh = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Fresh lead ' . uniqid(),
            'status' => 0,
        ]);

        $closed = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Closed stale lead ' . uniqid(),
            'status' => 2,
        ]);
        $this->backdate($closed, 30);

        $result = new ListStaleLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(idle_days: 7, limit: 100);

        $ids = array_column($result['leads'], 'lead_id');

        $this->assertContains($stale->getId(), $ids, 'stale open lead should be listed');
        $this->assertNotContains($fresh->getId(), $ids, 'recently updated lead should be excluded');
        $this->assertNotContains($closed->getId(), $ids, 'closed lead should be excluded');

        $row = collect($result['leads'])->firstWhere('lead_id', $stale->getId());
        $this->assertSame(7, $result['idle_days_threshold']);
        $this->assertGreaterThanOrEqual(7, $row['days_since_last_update']);
        $this->assertArrayHasKey('follow_up_count', $row);
        $this->assertFalse($row['is_handed_off']);
    }

    public function testOwnerFilterNarrowsResults(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $owned = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'status' => 0,
            'leads_owner_id' => $user->getId(),
        ]);
        $this->backdate($owned, 30);

        $unowned = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'status' => 0,
            'leads_owner_id' => 0,
        ]);
        $this->backdate($unowned, 30);

        $result = new ListStaleLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(idle_days: 7, owner: $user->email, limit: 100);

        $ids = array_column($result['leads'], 'lead_id');

        $this->assertContains($owned->getId(), $ids);
        $this->assertNotContains($unowned->getId(), $ids);
    }

    private function backdate(Lead $lead, int $days): void
    {
        // Direct builder update so Eloquent's timestamp touch doesn't reset updated_at to now.
        Lead::query()->where('id', $lead->getId())->update([
            'updated_at' => Carbon::now()->subDays($days),
        ]);
    }
}
