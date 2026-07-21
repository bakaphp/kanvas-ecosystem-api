<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReassignLeadOwnerTool;
use Tests\TestCase;

class ReassignLeadOwnerToolTest extends TestCase
{
    public function testReassignsOwnerToCompanyUserByEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'leads_owner_id' => 0,
        ]);

        $result = new ReassignLeadOwnerTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), new_owner: $user->email);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($user->getId(), $result['new_owner']['id']);

        $lead->refresh();
        $this->assertSame($user->getId(), (int) $lead->leads_owner_id);
    }

    public function testUnknownOwnerReturnsErrorAndLeavesLeadUntouched(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'leads_owner_id' => 7,
        ]);

        $result = new ReassignLeadOwnerTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), new_owner: 'nobodymatches' . uniqid());

        $this->assertArrayHasKey('error', $result);

        $lead->refresh();
        $this->assertSame(7, (int) $lead->leads_owner_id);
    }

    public function testUnknownLeadReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new ReassignLeadOwnerTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: 999999999, new_owner: $user->email);

        $this->assertArrayHasKey('error', $result);
    }
}
