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

    public function testGetAiModeKeyReturnsGenericAiModeForEveryLeadType(): void
    {
        $service = new LeadConfigurationService(true);

        foreach (['Internet', 'Showroom', 'Phone'] as $typeName) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                'ai_mode',
                $service->getAiModeKey($lead),
                "Lead key must be the generic 'ai_mode' regardless of lead type ({$typeName})"
            );
        }
    }

    public function testGetFollowUpModeKeyReturnsGenericAiFollowUpForEveryLeadType(): void
    {
        $service = new LeadConfigurationService(true);

        foreach (['Internet', 'Showroom', 'Phone'] as $typeName) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                'ai_follow_up',
                $service->getFollowUpModeKey($lead),
                "Lead key must be the generic 'ai_follow_up' regardless of lead type ({$typeName})"
            );
        }
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

    public function testGetFollowUpActiveDefaultKeyUsesCorrectPrefix(): void
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
                $service->getFollowUpActiveDefaultKey($lead),
                "{$typeName} lead should always use active key {$expectedKey}"
            );
        }
    }

    public function testGetFollowUpClosedNotSoldDefaultKeyUsesCorrectPrefix(): void
    {
        $cases = [
            'Internet' => 'internet_con_fu_cns_default',
            'Showroom' => 'showroom_con_fu_cns_default',
            'Phone' => 'phone_con_fu_cns_default',
        ];

        $service = new LeadConfigurationService(true);

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                $service->getFollowUpClosedNotSoldDefaultKey($lead),
                "{$typeName} lead should use closed-not-sold key {$expectedKey}"
            );
        }
    }

    public function testGetFollowUpClosedSoldDefaultKeyUsesCorrectPrefix(): void
    {
        $cases = [
            'Internet' => 'internet_con_fu_closed-sold_default',
            'Showroom' => 'showroom_con_fu_closed-sold_default',
            'Phone' => 'phone_con_fu_closed-sold_default',
        ];

        $service = new LeadConfigurationService(true);

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            $this->assertEquals(
                $expectedKey,
                $service->getFollowUpClosedSoldDefaultKey($lead),
                "{$typeName} lead should use closed-sold key {$expectedKey}"
            );
        }
    }

    public function testGetAllDefaultKeysReturnsKeysAndResolvedValues(): void
    {
        $lead = $this->createLead('Internet');
        $leadType = $lead->type()->first();
        $leadType->config = [
            'internet_ai_mode_open_default' => 'full_on',
            'internet_con_fu_active_default' => 'on',
            'internet_first_fu_active_default' => true,
        ];
        $leadType->saveOrFail();
        $lead->refresh();

        $result = new LeadConfigurationService(true)->getAllDefaultKeys($lead, true);

        $this->assertEquals('internet_ai_mode_open_default', $result['ai_mode']['key']);
        $this->assertEquals('full_on', $result['ai_mode']['value']);
        $this->assertEquals('internet_con_fu_active_default', $result['follow_up']['key']);
        $this->assertEquals('on', $result['follow_up']['value']);
        $this->assertEquals('internet_first_fu_active_default', $result['first_message']['key']);
        $this->assertTrue($result['first_message']['value']);
    }

    public function testGetAllDefaultKeysUsesClosedAiModeWhenIsOpenIsFalse(): void
    {
        $lead = $this->createLead('Internet');

        $result = new LeadConfigurationService(true)->getAllDefaultKeys($lead, false);

        $this->assertEquals('internet_ai_mode_closed_default', $result['ai_mode']['key']);
    }

    public function testGetAllDefaultKeysForcesActiveFollowUpRegardlessOfStatus(): void
    {
        $lead = $this->createLead('Internet');

        $result = new LeadConfigurationService(true)->getAllDefaultKeys($lead);

        $this->assertEquals('internet_con_fu_active_default', $result['follow_up']['key']);
    }
}
