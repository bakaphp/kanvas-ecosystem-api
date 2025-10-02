<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Tools\LeadIntentTool;
use Tests\TestCase;

class LeadIntentToolTest extends TestCase
{
    public function testLeadIntentTool(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Contact Us',
            'description' => 'Test Description',
            'is_active' => 1,
        ]);
        $company->set('adf_sources', [
            [
                'Source' => 'Contact Us',
                'Sub_Source' => 'Website',
                'Backend' => 'General Inquiry',
                'Default_Completion_Status' => 'New',
            ],
            [
                'Source' => 'Test Source',
                'Sub_Source' => 'Test Subsource',
                'Backend' => 'Test Backend',
                'Default_Completion_Status' => 'Test Status',
            ],
        ]);
        $lead->set('sub_source', 'Website');
        $lead->leads_types_id = $leadType->id;
        $lead->saveOrFail();
        $intent = new LeadIntentTool($lead)->execute();
        $this->assertEquals('General Inquiry', $intent['lead_intent']);
        $this->assertEquals('New', $intent['intent_completion_status']);
    }
}
