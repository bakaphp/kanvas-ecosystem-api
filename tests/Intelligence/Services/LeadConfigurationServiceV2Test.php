<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Tests\TestCase;

class LeadConfigurationServiceV2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = app(Apps::class);
        $app->set('search_engine', 'database');
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

    public function testIsV2EnabledReturnsTrueWhenFlagSet(): void
    {
        $this->assertTrue(new LeadConfigurationService(true)->isV2Enabled(auth()->user()->getCurrentCompany()));
    }

    public function testGetAiModeKeyReturnsShowroomKeyForShowroomLeadType(): void
    {
        $lead = $this->createLead('Showroom');

        $this->assertEquals('showroom_ai_mode', new LeadConfigurationService(true)->getAiModeKey($lead));
    }

    public function testGetAiModeKeyReturnsPhoneKeyForPhoneLeadType(): void
    {
        $lead = $this->createLead('Phone');

        $this->assertEquals('phone_ai_mode', new LeadConfigurationService(true)->getAiModeKey($lead));
    }

    public function testGetAiModeKeyReturnsGenericKeyForInternetLeadType(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('ai_mode', new LeadConfigurationService(true)->getAiModeKey($lead));
    }

    public function testGetFollowUpModeKeyReturnsInternetFollowUpKeyForInternetType(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_follow_up_mode', new LeadConfigurationService(true)->getFollowUpModeKey($lead));
    }

    public function testGetFollowUpModeKeyReturnsShowroomFollowUpKeyForShowroomType(): void
    {
        $lead = $this->createLead('Showroom');

        $this->assertEquals('showroom_follow_up_mode', new LeadConfigurationService(true)->getFollowUpModeKey($lead));
    }

    public function testGetFollowUpModeKeyReturnsPhoneFollowUpKeyForPhoneType(): void
    {
        $lead = $this->createLead('Phone');

        $this->assertEquals('phone_follow_up_mode', new LeadConfigurationService(true)->getFollowUpModeKey($lead));
    }

    public function testGetAiModeDefaultKeyReturnsOpenKeyWhenOpen(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_ai_mode_open_default', new LeadConfigurationService(true)->getAiModeDefaultKey($lead, true));
    }

    public function testGetAiModeDefaultKeyReturnsClosedKeyWhenClosed(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_ai_mode_closed_default', new LeadConfigurationService(true)->getAiModeDefaultKey($lead, false));
    }

    public function testGetAiModeDefaultKeyUsesCorrectPrefixPerType(): void
    {
        $cases = [
            'Internet' => 'internet_ai_mode_open_default',
            'Showroom' => 'showroom_ai_mode_open_default',
            'Phone' => 'phone_ai_mode_open_default',
        ];

        $service = new LeadConfigurationService(true);

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                $service->getAiModeDefaultKey($lead, true),
                "{$typeName} lead should use key {$expectedKey}"
            );
        }
    }

    public function testGetFollowUpDefaultKeyReturnsActiveKeyForActiveStatus(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals('internet_con_fu_active_default', new LeadConfigurationService(true)->getFollowUpDefaultKey($lead));
    }

    public function testGetFollowUpDefaultKeyUsesCorrectPrefixPerType(): void
    {
        $cases = [
            'Internet' => 'internet_con_fu_active_default',
            'Showroom' => 'showroom_con_fu_active_default',
            'Phone' => 'phone_con_fu_active_default',
        ];

        $service = new LeadConfigurationService(true);

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                $service->getFollowUpDefaultKey($lead),
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

        $service = new LeadConfigurationService(true);

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                $service->getFirstMessageDefaultKey($lead),
                "{$typeName} lead should use key {$expectedKey}"
            );
        }
    }
}
