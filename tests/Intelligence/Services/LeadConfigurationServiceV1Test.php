<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Tests\TestCase;

class LeadConfigurationServiceV1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = app(Apps::class);
        $app->set('search_engine', 'database');
        auth()->user()->getCurrentCompany()->set('intelligence_lead_type_mode_v2', false);
    }

    private function createLead(string $leadTypeName = ''): Lead
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        if ($leadTypeName !== '') {
            $leadType = LeadType::firstOrCreate(
                [
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                    'name' => $leadTypeName,
                ],
                ['description' => $leadTypeName . ' Lead', 'is_active' => 1]
            );

            $lead->leads_types_id = $leadType->getId();
            $lead->saveOrFail();
        }

        return $lead;
    }

    public function testIsV2EnabledReturnsFalseByDefault(): void
    {
        $this->assertFalse(new LeadConfigurationService(false)->isV2Enabled(auth()->user()->getCurrentCompany()));
    }

    public function testGetAiModeKeyReturnsGenericKeyWhenV1(): void
    {
        $lead = $this->createLead('Showroom');
        $this->assertEquals('ai_mode', new LeadConfigurationService(false)->getAiModeKey($lead));
    }

    public function testGetFollowUpModeKeyReturnsAiFollowUpWhenV1(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals(
            IntelligenceModeEnum::AI_FOLLOW_UP->value,
            new LeadConfigurationService(false)->getFollowUpModeKey($lead)
        );
    }
}
