<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Voice;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Actions\Voice\BuildVoiceAgentLeadContextAction;
use Kanvas\Intelligence\Agents\Actions\Voice\CaptureVoiceCallerAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceCallerLookupService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\TestCase;

final class VoiceLeadContinuityTest extends TestCase
{
    public function testCallSummaryIsReadBackOnTheNextContact(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();

        $result = new CaptureVoiceCallerAction(
            agent: $agent,
            callerApp: app(Apps::class),
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
            callerApp: app(Apps::class),
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

        new CaptureVoiceCallerAction(
            agent: $agent,
            callerApp: app(Apps::class),
            phone: '+1809' . $local,
            direction: 'inbound',
        )->execute();

        $formatted = '+1 (809) ' . substr($local, 0, 3) . '-' . substr($local, 3);
        $context = new BuildVoiceAgentLeadContextAction(agent: $agent, phone: $formatted)->execute();

        $this->assertNotNull($context);
        $this->assertTrue($context['is_returning']);
    }

    public function testTheCallersAppIsBoundAgainAfterACapture(): void
    {
        $agent = $this->createAgent();
        $agentApp = app(Apps::class);
        $callerApp = Apps::where('id', '!=', $agent->apps_id)->firstOrFail();

        try {
            $result = new CaptureVoiceCallerAction(
                agent: $agent,
                callerApp: $callerApp,
                phone: $this->uniquePhone(),
                direction: 'inbound',
            )->execute();
            $boundAfter = app(Apps::class)->getId();
        } finally {
            app()->scoped(Apps::class, fn () => $agentApp);
            Bouncer::scope()->to(RolesEnums::getScope($agentApp));
        }

        $this->assertSame('success', $result['status']);
        $this->assertSame($callerApp->getId(), $boundAfter);
    }

    public function testAWithheldCallerIdMatchesNobody(): void
    {
        $agent = $this->createAgent();
        new CaptureVoiceCallerAction(
            agent: $agent,
            callerApp: app(Apps::class),
            phone: $this->uniquePhone(),
            direction: 'inbound',
        )->execute();

        $this->assertNull(VoiceCallerLookupService::find($agent->app, $agent->company, ''));
        $this->assertNull(VoiceCallerLookupService::find($agent->app, $agent->company, 'anonymous'));
    }

    public function testABlankNameKeepsThePlaceholder(): void
    {
        $phone = $this->uniquePhone();

        $result = new CaptureVoiceCallerAction(
            agent: $this->createAgent(),
            callerApp: app(Apps::class),
            phone: $phone,
            name: '   ',
            direction: 'inbound',
        )->execute();

        $this->assertSame($phone, People::find($result['people_id'])->firstname);
    }

    public function testAskingForAnAppointmentOnAnOutboundCallCreatesALead(): void
    {
        $result = new CaptureVoiceCallerAction(
            agent: $this->createAgent(),
            callerApp: app(Apps::class),
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
            callerApp: app(Apps::class),
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

        $result = new CaptureVoiceCallerAction(
            agent: $agent,
            callerApp: app(Apps::class),
            phone: $phone,
            direction: 'inbound',
        )->execute();
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
