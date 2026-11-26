<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Voice;

use App\GraphQL\Intelligence\Mutations\VoiceAgentCallerMutation;
use Bouncer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Actions\Voice\BuildVoiceAgentLeadContextAction;
use Kanvas\Intelligence\Agents\Actions\Voice\CaptureVoiceCallerAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceCallerLookupService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\TestCase;
use Tests\Traits\CreatesBareApps;

final class VoiceLeadContinuityTest extends TestCase
{
    use CreatesBareApps;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testCallSummaryIsReadBackOnTheNextContact(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();

        $result = new CaptureVoiceCallerAction(
            agent: $agent,
            phone: $phone,
            interest: '2021 Civic',
            direction: 'inbound',
            summary: '[voice inbound 2026-11-03 14:15 UTC, conversation] Wants the price by text.',
        )->execute();

        $this->assertSame('success', $result['status']);
        $this->assertNull($result['lead_id']);

        $context = new BuildVoiceAgentLeadContextAction(agent: $agent, phone: $phone)->execute();

        $this->assertNotNull($context);
        $this->assertStringContainsString('Wants the price by text.', $context['summary']);
        $this->assertStringContainsString('Interest: 2021 Civic', $context['context_info']);
    }

    public function testCaptureWithoutSummaryAddsNoHistory(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();

        new CaptureVoiceCallerAction(
            agent: $agent,
            phone: $phone,
            name: 'Ana Perez',
            direction: 'inbound',
        )->execute();

        $context = new BuildVoiceAgentLeadContextAction(agent: $agent, phone: $phone)->execute();

        $this->assertNotNull($context);
        $this->assertNull($context['context_info']);
    }

    public function testANumberCapturedInOneFormatIsRecognizedInAnother(): void
    {
        $agent = $this->createAgent();
        $local = (string) random_int(1000000, 9999999);

        new CaptureVoiceCallerAction(agent: $agent, phone: '+1809' . $local, direction: 'inbound')->execute();

        $formatted = '+1 (809) ' . substr($local, 0, 3) . '-' . substr($local, 3);
        $context = new BuildVoiceAgentLeadContextAction(agent: $agent, phone: $formatted)->execute();

        $this->assertNotNull($context);
        $this->assertTrue($context['is_returning']);
    }

    public function testACaptureUnderAnotherAppsKeyLandsOnTheAgentsApp(): void
    {
        $agent = $this->createAgent();
        $agentApp = app(Apps::class);
        $runtimeApp = $this->createBareApp();
        app()->scoped(Apps::class, fn () => $runtimeApp);
        Bouncer::scope()->to(RolesEnums::getScope($runtimeApp));

        try {
            $result = new CaptureVoiceCallerAction(
                agent: $agent,
                phone: $this->uniquePhone(),
                interest: '2021 Civic',
                direction: 'inbound',
                wantsAppointment: true,
            )->execute();
        } finally {
            app()->scoped(Apps::class, fn () => $agentApp);
            Bouncer::scope()->to(RolesEnums::getScope($agentApp));
        }

        $this->assertSame('success', $result['status']);
        $this->assertSame($agent->apps_id, People::find($result['people_id'])->apps_id);
        $this->assertSame($agent->apps_id, Lead::find($result['lead_id'])->apps_id);
    }

    public function testTheMutationBindsTheAgentsAppForACrossAppRuntimeKey(): void
    {
        config(['kanvas.voice_runtime.cross_app' => true]);
        $agent = $this->createAgent();
        $agentApp = app(Apps::class);
        $runtimeApp = $this->createBareApp();
        app()->scoped(Apps::class, fn () => $runtimeApp);

        try {
            $result = new VoiceAgentCallerMutation()->capture(null, [
                'agent_uuid' => $agent->uuid,
                'phone' => $this->uniquePhone(),
                'direction' => 'inbound',
            ]);
            $boundApp = app(Apps::class)->getId();
        } finally {
            app()->scoped(Apps::class, fn () => $agentApp);
            Bouncer::scope()->to(RolesEnums::getScope($agentApp));
        }

        $this->assertSame('success', $result['status']);
        $this->assertSame($agent->apps_id, $boundApp);
    }

    public function testAWithheldCallerIdMatchesNobody(): void
    {
        $agent = $this->createAgent();
        new CaptureVoiceCallerAction(agent: $agent, phone: $this->uniquePhone(), direction: 'inbound')
            ->execute();

        $this->assertNull(VoiceCallerLookupService::find($agent->app, $agent->company, ''));
        $this->assertNull(VoiceCallerLookupService::find($agent->app, $agent->company, 'anonymous'));
    }

    public function testABlankNameKeepsThePlaceholder(): void
    {
        $phone = $this->uniquePhone();

        $result = new CaptureVoiceCallerAction(
            agent: $this->createAgent(),
            phone: $phone,
            name: '   ',
            direction: 'inbound',
        )->execute();

        $this->assertSame($phone, People::find($result['people_id'])->firstname);
    }

    public function testANameSaidOnTheCallReplacesUnknown(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->captureName($agent, $phone, null);
        $people->forceFill(['firstname' => 'Unknown', 'lastname' => '', 'name' => 'Unknown'])->saveOrFail();

        $people = $this->captureName($agent, $phone, 'Rafael Lopez');

        $this->assertSame('Rafael', $people->firstname);
        $this->assertSame('Lopez', $people->lastname);
        $this->assertSame('Rafael Lopez', $people->name);
    }

    public function testTheFirstNameGivenReplacesThePhoneInTheFullName(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $this->captureName($agent, $phone, null);

        // Trimmed: opening the lead re-saves the person through UpdatePeopleAction, which
        // joins first and last name with a space even when there is no last name.
        $this->assertSame('Rafael', trim($this->captureName($agent, $phone, 'Rafael')->name));
    }

    public function testARealNameIsNeverOverwritten(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $this->captureName($agent, $phone, 'Maria');

        $people = $this->captureName($agent, $phone, 'Eduardo');

        $this->assertSame('Maria', $people->firstname);
        $this->assertSame('Maria', trim($people->name));
    }

    public function testPlaceholderNames(): void
    {
        foreach (['', '  ', '+1 (809) 555-0101', 'Unknown', 'unknown guest', 'UNKNOWN CALLER'] as $name) {
            $this->assertTrue(VoiceCallerLookupService::isPlaceholderName($name), $name);
        }
        foreach (['Rafael', 'Unknown Pleasures', 'Unknownson'] as $name) {
            $this->assertFalse(VoiceCallerLookupService::isPlaceholderName($name), $name);
        }
    }

    public function testAskingForAnAppointmentOnAnOutboundCallCreatesALead(): void
    {
        $result = new CaptureVoiceCallerAction(
            agent: $this->createAgent(),
            phone: $this->uniquePhone(),
            direction: 'outbound',
            wantsAppointment: true,
            appointmentPreference: 'Tomorrow at 3 PM',
        )->execute();

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['created_lead']);
        $this->assertNotNull($result['lead_id']);
    }

    public function testAnOutboundCallWithoutIntentCreatesNoLead(): void
    {
        $result = new CaptureVoiceCallerAction(
            agent: $this->createAgent(),
            phone: $this->uniquePhone(),
            direction: 'outbound',
        )->execute();

        $this->assertSame('success', $result['status']);
        $this->assertNull($result['lead_id']);
    }

    public function testRecentTextMessagesAreIncludedNewestOnly(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();

        $result = new CaptureVoiceCallerAction(agent: $agent, phone: $phone, direction: 'inbound')->execute();
        $people = People::find($result['people_id']);

        for ($i = 1; $i <= 8; $i++) {
            $this->attachTextMessage($people, "Text {$i}", fromAgent: $i % 2 === 0);
        }

        $context = new BuildVoiceAgentLeadContextAction(agent: $agent, phone: $phone)->execute();

        $this->assertNotNull($context['recent_messages']);
        $this->assertStringStartsWith('Recent messages:', $context['recent_messages']);
        $this->assertStringContainsString('[Agent] Text 8', $context['recent_messages']);
        $this->assertStringNotContainsString('Text 1', $context['recent_messages']);
        $this->assertStringContainsString('[Agent] Text 8', $context['summary']);
    }

    private function attachTextMessage(People $people, string $text, bool $fromAgent): void
    {
        $app = app(Apps::class);

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $people->company,
                user: auth()->user(),
                type: MessageTypeService::getOrCreate($app, 'twilio-sms'),
                message: ['content' => $text, 'from_ia' => $fromAgent, 'from_me' => $fromAgent],
                is_public: 1,
            )
        )->execute();
        $message->addEntity($people);
    }

    private function captureName(Agent $agent, string $phone, ?string $name): People
    {
        $result = new CaptureVoiceCallerAction(
            agent: $agent,
            phone: $phone,
            name: $name,
            direction: 'inbound',
        )->execute();

        return People::getByIdFromCompanyApp($result['people_id'], $agent->company, $agent->app);
    }

    private function createAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        return Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
    }

    private function uniquePhone(): string
    {
        return '+1809' . random_int(1000000, 9999999);
    }
}
