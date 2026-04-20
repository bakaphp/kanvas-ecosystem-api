<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Tests\TestCase;

class LeadConfigurationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = app(Apps::class);
        $app->set('search_engine', 'database');
        $app->set('intelligence_lead_type_mode_v2', 0);
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
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', false);

        $this->assertFalse(LeadConfigurationService::isV2Enabled($app));
    }

    public function testIsV2EnabledReturnsTrueWhenFlagSet(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', true);

        $this->assertTrue(LeadConfigurationService::isV2Enabled($app));

        $app->set('intelligence_lead_type_mode_v2', false);
    }

    public function testGetAiModeKeyReturnsGenericKeyWhenV1(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', false);
        $lead = $this->createLead('Showroom');

        $this->assertEquals('ai_mode', LeadConfigurationService::getAiModeKey($lead));
    }

    public function testGetAiModeKeyReturnsShowroomKeyForShowroomLeadType(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', true);

        $lead = $this->createLead('Showroom');

        $this->assertEquals('showroom_ai_mode', LeadConfigurationService::getAiModeKey($lead));

        $app->set('intelligence_lead_type_mode_v2', false);
    }

    public function testGetAiModeKeyReturnsPhoneKeyForPhoneLeadType(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', true);

        $lead = $this->createLead('Phone');

        $this->assertEquals('phone_ai_mode', LeadConfigurationService::getAiModeKey($lead));

        $app->set('intelligence_lead_type_mode_v2', false);
    }

    public function testGetAiModeKeyReturnsGenericKeyForInternetLeadType(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', true);

        $lead = $this->createLead('Internet');

        $this->assertEquals('ai_mode', LeadConfigurationService::getAiModeKey($lead));

        $app->set('intelligence_lead_type_mode_v2', false);
    }

    public function testGetFollowUpModeKeyReturnsAiFollowUpWhenV1(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', false);

        $lead = $this->createLead('Internet');

        $this->assertEquals(
            IntelligenceModeEnum::AI_FOLLOW_UP->value,
            LeadConfigurationService::getFollowUpModeKey($lead)
        );
    }

    public function testGetFollowUpModeKeyReturnsInternetFollowUpKeyForInternetType(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', true);

        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_follow_up_mode', LeadConfigurationService::getFollowUpModeKey($lead));

        $app->set('intelligence_lead_type_mode_v2', false);
    }

    public function testGetFollowUpModeKeyReturnsShowroomFollowUpKeyForShowroomType(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', true);

        $lead = $this->createLead('Showroom');

        $this->assertEquals('showroom_follow_up_mode', LeadConfigurationService::getFollowUpModeKey($lead));

        $app->set('intelligence_lead_type_mode_v2', false);
    }

    public function testGetFollowUpModeKeyReturnsPhoneFollowUpKeyForPhoneType(): void
    {
        $app = app(Apps::class);
        $app->set('intelligence_lead_type_mode_v2', true);

        $lead = $this->createLead('Phone');

        $this->assertEquals('phone_follow_up_mode', LeadConfigurationService::getFollowUpModeKey($lead));

        $app->set('intelligence_lead_type_mode_v2', false);
    }

    public function testGetAiModeDefaultKeyReturnsOpenKeyWhenOpen(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_ai_mode_open_default', LeadConfigurationService::getAiModeDefaultKey($lead, true));
    }

    public function testGetAiModeDefaultKeyReturnsClosedKeyWhenClosed(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_ai_mode_closed_default', LeadConfigurationService::getAiModeDefaultKey($lead, false));
    }

    public function testGetAiModeDefaultKeyUsesCorrectPrefixPerType(): void
    {
        $cases = [
            'Internet' => 'internet_ai_mode_open_default',
            'Showroom' => 'showroom_ai_mode_open_default',
            'Phone' => 'phone_ai_mode_open_default',
        ];

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                LeadConfigurationService::getAiModeDefaultKey($lead, true),
                "{$typeName} lead should use key {$expectedKey}"
            );
        }
    }

    public function testGetFollowUpDefaultKeyReturnsActiveKeyForActiveStatus(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_con_fu_active_default', LeadConfigurationService::getFollowUpDefaultKey($lead));
    }

    public function testGetFollowUpDefaultKeyUsesCorrectPrefixPerType(): void
    {
        $cases = [
            'Internet' => 'internet_con_fu_active_default',
            'Showroom' => 'showroom_con_fu_active_default',
            'Phone' => 'phone_con_fu_active_default',
        ];

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                LeadConfigurationService::getFollowUpDefaultKey($lead),
                "{$typeName} lead should use key {$expectedKey}"
            );
        }
    }

    public function testGetFirstMessageDefaultKeyUsesCorrectPrefix(): void
    {
        $cases = [
            'Internet' => 'internet_first_fu_active_default',
            'Showroom' => 'showroom_first_fu_active_default',
            'Phone' => 'phone_first_fu_active_default',
        ];

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                LeadConfigurationService::getFirstMessageDefaultKey($lead),
                "{$typeName} lead should use key {$expectedKey}"
            );
        }
    }
}
