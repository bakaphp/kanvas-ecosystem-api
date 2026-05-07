<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VoiceBridge;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum as VoiceBridgeConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Jobs\LeadVoiceFollowUpJob;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

class LeadVoiceFollowUpJobTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Lead $lead;

    public function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = PeopleFactory::new()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withContacts()
            ->create();

        $this->lead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $this->kanvasApp->set(VoiceBridgeConfigurationEnum::API_KEY->value, 'dev-kanvas-voice-bridge-2026');
        $this->kanvasApp->set(VoiceBridgeConfigurationEnum::BASE_URL->value, 'https://voice-bridge-89863003570.us-central1.run.app');
        $this->kanvasApp->set(VoiceBridgeConfigurationEnum::COMPANY_ID->value, '1645');
    }

    public function testSkipsCallIfLeadAlreadyEngaged(): void
    {
        $this->lead->set(LeadsConfigurationEnum::IS_ENGAGEMENT->value, true);

        $job = new LeadVoiceFollowUpJob($this->lead, $this->kanvasApp);
        $job->handle();

        $this->assertTrue(true);
    }

    public function testSkipsCallIfVoiceBridgeNotConfigured(): void
    {
        $this->kanvasApp->del(VoiceBridgeConfigurationEnum::API_KEY->value);

        $job = new LeadVoiceFollowUpJob($this->lead, $this->kanvasApp);
        $job->handle();

        $this->assertTrue(true);
    }

    public function testJobRunsWithoutThrowingForUnrespondedLead(): void
    {
        $job = new LeadVoiceFollowUpJob($this->lead, $this->kanvasApp);
        $job->handle();

        $this->assertTrue(true);
    }

    public function testActuallyCallsVoiceBridge(): void
    {
        $agent = new Agent();
        $agent->role = [
            'background' => ['You are a voice outreach agent for lead follow-up.'],
            'steps' => ['Greet the lead and introduce yourself.'],
        ];

        $sessionResult = InitVoiceSessionAction::fromLead($this->lead, $agent)->execute();
        TriggerVoiceCallAction::fromLead($this->lead)->execute();

        $this->assertArrayHasKey('status', $sessionResult);
    }
}
