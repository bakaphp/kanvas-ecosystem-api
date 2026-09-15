<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
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

    private function createLead(string $leadTypeName = '', array $config = []): Lead
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

            if ($config !== []) {
                $leadType->config = $config;
                $leadType->saveOrFail();
            }

            $lead->leads_types_id = $leadType->getId();
            $lead->saveOrFail();
            $lead->refresh();
        }

        return $lead;
    }

    public function testGetAiModeKeyReturnsGenericAiModeForEveryLeadType(): void
    {
        $service = new LeadConfigurationService();

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
        $service = new LeadConfigurationService();

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
        $lead = $this->createLead('Internet', ['internet_ai_mode_open_default' => 'FULL_ON']);

        $this->assertEquals('internet_ai_mode_open_default', new LeadConfigurationService()->getAiModeDefaultKey($lead, true));
    }

    public function testGetAiModeDefaultKeyReturnsClosedKeyWhenClosed(): void
    {
        $lead = $this->createLead('Internet', ['internet_ai_mode_closed_default' => 'SUPPORT']);

        $this->assertEquals('internet_ai_mode_closed_default', new LeadConfigurationService()->getAiModeDefaultKey($lead, false));
    }

    public function testGetAiModeDefaultKeyResolvesPrefixFromConfigKeys(): void
    {
        $cases = [
            'internet' => 'internet_ai_mode_open_default',
            'showroom' => 'showroom_ai_mode_open_default',
            'phone' => 'phone_ai_mode_open_default',
            'custom-channel' => 'custom-channel_ai_mode_open_default',
        ];

        $service = new LeadConfigurationService();

        foreach ($cases as $prefix => $expectedKey) {
            $lead = $this->createLead('Type-' . $prefix, [$expectedKey => 'FULL_ON']);

            $this->assertEquals(
                $expectedKey,
                $service->getAiModeDefaultKey($lead, true),
                "Lead with config key {$expectedKey} should resolve to {$expectedKey}"
            );
        }
    }

    public function testGetAiModeDefaultKeyFallsBackToBareSuffixWhenConfigHasNoMatch(): void
    {
        $lead = $this->createLead('Internet');

        $this->assertEquals(
            'ai_mode_open_default',
            new LeadConfigurationService()->getAiModeDefaultKey($lead, true)
        );
    }

    public function testLeadResolvesAutomaticAiModeFromWorkingHoursDefault(): void
    {
        $lead = $this->createLead('Internet', [
            'internet_ai_mode_open_default' => IntelligenceModeEnum::FULL_ON->value,
            'internet_ai_mode_closed_default' => IntelligenceModeEnum::SUPPORT->value,
        ]);
        $lead->set('ai_mode', IntelligenceModeEnum::IDLE->value);

        $this->assertSame(IntelligenceModeEnum::FULL_ON, $lead->resolveAiMode(true));
        $this->assertSame(IntelligenceModeEnum::SUPPORT, $lead->resolveAiMode(false));
    }

    public function testLeadManualAiModeOverridesWorkingHoursDefaults(): void
    {
        $lead = $this->createLead('Internet', [
            'internet_ai_mode_open_default' => IntelligenceModeEnum::FULL_ON->value,
            'internet_ai_mode_closed_default' => IntelligenceModeEnum::SUPPORT->value,
        ]);
        $lead->set('ai_mode', IntelligenceModeEnum::IDLE->value);
        $lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);

        $this->assertSame(IntelligenceModeEnum::IDLE, $lead->resolveAiMode(true));
        $this->assertSame(IntelligenceModeEnum::IDLE, $lead->resolveAiMode(false));
        $this->assertTrue($lead->isAiMuted());
        $this->assertFalse($lead->isAiSupport());
    }

    public function testLeadAiModeFallsBackToStoredModeWhenDefaultIsMissing(): void
    {
        $lead = $this->createLead('NoDefaults');
        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);

        $this->assertSame(IntelligenceModeEnum::SUPPORT, $lead->resolveAiMode(true));
        $this->assertTrue($lead->isAiSupport());
    }

    public function testGetFollowUpDefaultKeyReturnsActiveKeyForActiveStatus(): void
    {
        $lead = $this->createLead('Internet', ['internet_con_fu_active_default' => 1]);

        $this->assertEquals('internet_con_fu_active_default', new LeadConfigurationService()->getFollowUpDefaultKey($lead));
    }

    public function testGetFollowUpDefaultKeyResolvesPrefixFromConfigKeys(): void
    {
        $cases = [
            'internet' => 'internet_con_fu_active_default',
            'showroom' => 'showroom_con_fu_active_default',
            'phone' => 'phone_con_fu_active_default',
        ];

        $service = new LeadConfigurationService();

        foreach ($cases as $prefix => $expectedKey) {
            $lead = $this->createLead('Type-' . $prefix, [$expectedKey => 1]);

            $this->assertEquals(
                $expectedKey,
                $service->getFollowUpDefaultKey($lead),
                "Lead with config key {$expectedKey} should resolve to {$expectedKey}"
            );
        }
    }

    public function testGetFirstMessageDefaultKeyResolvesPrefixFromConfigKeys(): void
    {
        $cases = [
            'internet' => 'internet_first_fu_active_default',
            'showroom' => 'showroom_first_fu_active_default',
            'phone' => 'phone_first_fu_active_default',
        ];

        $service = new LeadConfigurationService();

        foreach ($cases as $prefix => $expectedKey) {
            $lead = $this->createLead('Type-' . $prefix, [$expectedKey => true]);

            $this->assertEquals(
                $expectedKey,
                $service->getFirstMessageDefaultKey($lead),
                "Lead with config key {$expectedKey} should resolve to {$expectedKey}"
            );
        }
    }

    public function testGetFollowUpActiveDefaultKeyResolvesPrefixFromConfigKeys(): void
    {
        $cases = [
            'internet' => 'internet_con_fu_active_default',
            'showroom' => 'showroom_con_fu_active_default',
            'phone' => 'phone_con_fu_active_default',
        ];

        $service = new LeadConfigurationService();

        foreach ($cases as $prefix => $expectedKey) {
            $lead = $this->createLead('Type-' . $prefix, [$expectedKey => 1]);

            $this->assertEquals(
                $expectedKey,
                $service->getFollowUpActiveDefaultKey($lead),
                "Lead with config key {$expectedKey} should resolve to {$expectedKey}"
            );
        }
    }

    public function testGetFollowUpClosedNotSoldDefaultKeyResolvesPrefixFromConfigKeys(): void
    {
        $cases = [
            'internet' => 'internet_con_fu_cns_default',
            'showroom' => 'showroom_con_fu_cns_default',
            'phone' => 'phone_con_fu_cns_default',
        ];

        $service = new LeadConfigurationService();

        foreach ($cases as $prefix => $expectedKey) {
            $lead = $this->createLead('Type-' . $prefix, [$expectedKey => 0]);

            $this->assertEquals(
                $expectedKey,
                $service->getFollowUpClosedNotSoldDefaultKey($lead),
                "Lead with config key {$expectedKey} should resolve to {$expectedKey}"
            );
        }
    }

    public function testGetFollowUpClosedSoldDefaultKeyResolvesPrefixFromConfigKeys(): void
    {
        $cases = [
            'internet' => 'internet_con_fu_closed-sold_default',
            'showroom' => 'showroom_con_fu_closed-sold_default',
            'phone' => 'phone_con_fu_closed-sold_default',
        ];

        $service = new LeadConfigurationService();

        foreach ($cases as $prefix => $expectedKey) {
            $lead = $this->createLead('Type-' . $prefix, [$expectedKey => 0]);

            $this->assertEquals(
                $expectedKey,
                $service->getFollowUpClosedSoldDefaultKey($lead),
                "Lead with config key {$expectedKey} should resolve to {$expectedKey}"
            );
        }
    }

    public function testGetAllDefaultKeysReturnsKeysAndResolvedValues(): void
    {
        $lead = $this->createLead('Internet', [
            'internet_ai_mode_open_default' => 'full_on',
            'internet_con_fu_active_default' => 'on',
            'internet_first_fu_active_default' => true,
        ]);

        $result = new LeadConfigurationService()->getAllDefaultKeys($lead, true);

        $this->assertEquals('internet_ai_mode_open_default', $result['ai_mode']['key']);
        $this->assertEquals('full_on', $result['ai_mode']['value']);
        $this->assertEquals('internet_con_fu_active_default', $result['follow_up']['key']);
        $this->assertEquals('on', $result['follow_up']['value']);
        $this->assertEquals('internet_first_fu_active_default', $result['first_message']['key']);
        $this->assertTrue($result['first_message']['value']);
    }

    public function testGetAllDefaultKeysUsesClosedAiModeWhenIsOpenIsFalse(): void
    {
        $lead = $this->createLead('Internet', ['internet_ai_mode_closed_default' => 'SUPPORT']);

        $result = new LeadConfigurationService()->getAllDefaultKeys($lead, false);

        $this->assertEquals('internet_ai_mode_closed_default', $result['ai_mode']['key']);
    }

    public function testGetAllDefaultKeysForcesActiveFollowUpRegardlessOfStatus(): void
    {
        $lead = $this->createLead('Internet', ['internet_con_fu_active_default' => 1]);

        $result = new LeadConfigurationService()->getAllDefaultKeys($lead);

        $this->assertEquals('internet_con_fu_active_default', $result['follow_up']['key']);
    }
}
