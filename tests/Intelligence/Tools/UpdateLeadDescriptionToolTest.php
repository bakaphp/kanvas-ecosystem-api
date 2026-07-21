<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadDescriptionTool;
use Tests\TestCase;

class UpdateLeadDescriptionToolTest extends TestCase
{
    public function testReplacesDescription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'description' => 'old context',
        ]);

        $result = new UpdateLeadDescriptionTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), description: 'DNC - Do Not Contact solicitado por Beno');

        $this->assertArrayNotHasKey('error', $result);
        $lead->refresh();
        $this->assertSame('DNC - Do Not Contact solicitado por Beno', $lead->description);
    }

    public function testAppendPreservesExistingDescription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'description' => 'existing note',
        ]);

        $result = new UpdateLeadDescriptionTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), description: 'DNC solicitado por Beno', mode: 'append');

        $this->assertArrayNotHasKey('error', $result);
        $lead->refresh();
        $this->assertStringContainsString('existing note', $lead->description);
        $this->assertStringContainsString('DNC solicitado por Beno', $lead->description);
    }

    public function testUnknownLeadReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new UpdateLeadDescriptionTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: 999999999, description: 'anything');

        $this->assertArrayHasKey('error', $result);
    }
}
