<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Voice;

use App\GraphQL\Intelligence\Mutations\VoiceAgentCallerMutation;
use App\GraphQL\Intelligence\Queries\Agent\VoiceAgentTwilioQuery;
use Bouncer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadConversationTranscriptService;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Actions\Voice\BuildVoiceAgentLeadContextAction;
use Kanvas\Intelligence\Agents\Actions\Voice\CaptureVoiceCallerAction;
use Kanvas\Intelligence\Agents\Actions\Voice\RecordVoiceTranscriptAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Tests\TestCase;
use Tests\Traits\CreatesBareApps;

final class VoiceTranscriptMessagesTest extends TestCase
{
    use CreatesBareApps;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    private const array TURNS = [
        ['role' => 'agent', 'text' => 'Hi, this is Sally from the dealership.'],
        ['role' => 'customer', 'text' => 'I want to see the 2021 Civic tomorrow.'],
        ['role' => 'agent', 'text' => 'Tuesday at 4 PM works. See you then.'],
    ];

    private const string SUMMARY = '[voice inbound 2026-11-24 19:27 UTC, conversation] Rafael wants to see '
        . 'the 2021 Civic; booked for Tuesday at 4 PM.';

    public function testACallIsOneVoiceMessageOnThePerson(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        $result = $this->record($agent, $phone, 'CAperson');

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['saved']);
        $this->assertNull($result['lead_id']);

        $messages = $this->messagesOn($people);
        $this->assertCount(1, $messages);
        $message = $messages->first();
        $this->assertSame('voice', $message->messageType->verb);
        $this->assertSame(self::SUMMARY, $message->message['content']);
        $this->assertSame(self::TURNS, $message->message['transcript']);
        $this->assertFalse($message->message['from_ia']);
        $this->assertSame($people->getId(), $message->people_id);
    }

    public function testTheCallIsFiledOnTheLeadToo(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone, wantsAppointment: true);
        $lead = Lead::find($this->record($agent, $phone, 'CAlead')['lead_id']);

        $this->assertNotNull($lead);
        $this->assertCount(1, $this->messagesOn($lead));
        $this->assertCount(1, $this->messagesOn($people));
    }

    public function testResendingACallSavesNothingTwice(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        $this->record($agent, $phone, 'CAresend');
        $again = $this->record($agent, $phone, 'CAresend');

        $this->assertSame(0, $again['saved']);
        $this->assertCount(1, $this->messagesOn($people));
    }

    public function testTheNextContactReadsTheSummary(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        $this->record($agent, $phone, 'CAnext');
        $context = new BuildVoiceAgentLeadContextAction(agent: $agent, phone: $phone)->execute();
        // The call first, then the customer texts: the summary still opens the SMS agent's history.
        $this->attachText($people, 'Can we move it to 5?');
        $lines = LeadConversationTranscriptService::lines($people->refresh());

        $this->assertStringContainsString('booked for Tuesday at 4 PM', $context['recent_messages']);
        $this->assertStringContainsString('booked for Tuesday at 4 PM', $lines[0]);
    }

    public function testWithoutASummaryTheTranscriptIsTheContent(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        $this->record($agent, $phone, 'CAnosummary', summary: null);

        $this->assertSame(
            "Agent: Hi, this is Sally from the dealership.\n"
            . "Customer: I want to see the 2021 Civic tomorrow.\n"
            . 'Agent: Tuesday at 4 PM works. See you then.',
            $this->messagesOn($people)->first()->message['content'],
        );
    }

    public function testANumberWithNoPersonIsSkipped(): void
    {
        $result = $this->record($this->createAgent(), $this->uniquePhone(), 'CAnobody');

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_contact', $result['reason']);
    }

    public function testBlankAndUnknownTurnsAreDropped(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        new RecordVoiceTranscriptAction(
            agent: $agent,
            phone: $phone,
            callSid: 'CAblank' . $phone,
            turns: [
                ['role' => 'customer', 'text' => '   '],
                ['role' => 'system', 'text' => 'You are an agent.'],
                ['role' => 'customer', 'text' => 'Hello?'],
            ],
        )->execute();

        $message = $this->messagesOn($people)->first();
        $this->assertSame('Customer: Hello?', $message->message['content']);
        $this->assertSame([['role' => 'customer', 'text' => 'Hello?']], $message->message['transcript']);
    }

    public function testACallWithNothingSaidIsSkipped(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        $result = new RecordVoiceTranscriptAction(
            agent: $agent,
            phone: $phone,
            callSid: 'CAsilent' . $phone,
            turns: [['role' => 'customer', 'text' => '  ']],
        )->execute();

        $this->assertSame('nothing_said', $result['reason']);
        $this->assertCount(0, $this->messagesOn($people));
    }

    public function testTheMutationFilesACrossAppAgentsCallInTheAgentsApp(): void
    {
        config(['kanvas.voice_runtime.api_token' => 'runtime-token', 'kanvas.voice_runtime.cross_app' => true]);
        request()->headers->set(VoiceAgentTwilioQuery::TOKEN_HEADER, 'runtime-token');
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);
        $agentApp = app(Apps::class);
        $runtimeApp = $this->createBareApp();
        // The runtime's key belongs to another app, whose scope has no Admin role for the agent's app.
        app()->scoped(Apps::class, fn () => $runtimeApp);
        Bouncer::scope()->to('app_0_company_0');

        try {
            $result = new VoiceAgentCallerMutation()->recordTranscript(null, [
                'agent_uuid' => $agent->uuid,
                'phone' => $phone,
                'call_sid' => 'CAcrossapp' . $phone,
                'turns' => self::TURNS,
            ]);
        } finally {
            app()->scoped(Apps::class, fn () => $agentApp);
            Bouncer::scope()->to(RolesEnums::getScope($agentApp));
        }

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $this->messagesOn($people));
    }

    public function testTheMutationRefusesAnAppKeyWithoutTheRuntimeToken(): void
    {
        config(['kanvas.voice_runtime.api_token' => 'runtime-token']);
        request()->headers->set(VoiceAgentTwilioQuery::TOKEN_HEADER, 'wrong');

        $this->expectException(AuthorizationException::class);

        new VoiceAgentCallerMutation()->recordTranscript(null, [
            'agent_uuid' => $this->createAgent()->uuid,
            'phone' => $this->uniquePhone(),
            'call_sid' => 'CAtoken',
            'turns' => self::TURNS,
        ]);
    }

    public function testTheMutationRecordsForTheRuntime(): void
    {
        config(['kanvas.voice_runtime.api_token' => 'runtime-token']);
        request()->headers->set(VoiceAgentTwilioQuery::TOKEN_HEADER, 'runtime-token');
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $this->capture($agent, $phone);

        $result = new VoiceAgentCallerMutation()->recordTranscript(null, [
            'agent_uuid' => $agent->uuid,
            'phone' => $phone,
            'call_sid' => 'CAruntime',
            'direction' => 'inbound',
            'turns' => self::TURNS,
            'summary' => self::SUMMARY,
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['saved']);
    }

    private function record(
        Agent $agent,
        string $phone,
        string $callSid,
        ?string $summary = self::SUMMARY,
    ): array {
        return new RecordVoiceTranscriptAction(
            agent: $agent,
            phone: $phone,
            callSid: $callSid . $phone,
            turns: self::TURNS,
            direction: 'inbound',
            summary: $summary,
        )->execute();
    }

    private function capture(Agent $agent, string $phone, bool $wantsAppointment = false): People
    {
        $result = new CaptureVoiceCallerAction(
            agent: $agent,
            phone: $phone,
            direction: 'inbound',
            wantsAppointment: $wantsAppointment,
        )->execute();
        $this->assertSame('success', $result['status']);

        return People::find($result['people_id']);
    }

    private function attachText(People $people, string $text): void
    {
        $app = app(Apps::class);
        new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $people->company,
                user: auth()->user(),
                type: MessageTypeService::getOrCreate($app, 'twilio-sms'),
                message: ['content' => $text, 'from_ia' => false, 'from_me' => false],
                is_public: 1,
            )
        )->execute()->addEntity($people);
    }

    /**
     * @return Collection<int, Message>
     */
    private function messagesOn(People|Lead $entity): Collection
    {
        $ids = AppModuleMessage::query()->forEntity($entity)->pluck('message_id');

        return Message::query()->whereIn('id', $ids)->orderBy('id')->get()->toBase();
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
