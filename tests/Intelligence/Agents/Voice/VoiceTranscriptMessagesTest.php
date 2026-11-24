<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Voice;

use App\GraphQL\Intelligence\Mutations\VoiceAgentCallerMutation;
use App\GraphQL\Intelligence\Queries\Agent\VoiceAgentTwilioQuery;
use Bouncer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Actions\Voice\BuildVoiceAgentLeadContextAction;
use Kanvas\Intelligence\Agents\Actions\Voice\CaptureVoiceCallerAction;
use Kanvas\Intelligence\Agents\Actions\Voice\RecordVoiceTranscriptAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use RuntimeException;
use Tests\TestCase;

final class VoiceTranscriptMessagesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    private const array TURNS = [
        ['role' => 'agent', 'text' => 'Hi, this is Sally from the dealership.'],
        ['role' => 'customer', 'text' => 'I want to see the 2021 Civic tomorrow.'],
        ['role' => 'agent', 'text' => 'Tuesday at 4 PM works. See you then.'],
    ];

    public function testEachTurnIsAVoiceMessageOnThePerson(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        $result = $this->record($agent, $phone, 'CAperson');

        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['saved']);
        $this->assertNull($result['lead_id']);

        $messages = $this->messagesOn($people);
        $this->assertCount(3, $messages);
        $this->assertSame(
            ['voice'],
            $messages->map(fn (Message $m) => $m->messageType->verb)->unique()->values()->all(),
        );
        $this->assertSame(
            array_column(self::TURNS, 'text'),
            $messages->map(fn (Message $m) => $m->message['content'])->all(),
        );
        $this->assertSame([true, false, true], $messages->map(fn (Message $m) => $m->message['from_ia'])->all());
        $this->assertSame($people->getId(), $messages->first()->people_id);
    }

    public function testTurnsAreFiledOnTheLeadToo(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone, wantsAppointment: true);
        $lead = Lead::find($this->record($agent, $phone, 'CAlead')['lead_id']);

        $this->assertNotNull($lead);
        $this->assertCount(3, $this->messagesOn($lead));
        $this->assertCount(3, $this->messagesOn($people));
    }

    public function testResendingACallSavesNothingTwice(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);

        $this->record($agent, $phone, 'CAresend');
        $again = $this->record($agent, $phone, 'CAresend');

        $this->assertSame(0, $again['saved']);
        $this->assertSame(3, $again['skipped']);
        $this->assertCount(3, $this->messagesOn($people));
    }

    public function testTheNextContactReadsTheCall(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $this->capture($agent, $phone);

        $this->record($agent, $phone, 'CAnext');
        $context = new BuildVoiceAgentLeadContextAction(agent: $agent, phone: $phone)->execute();

        $this->assertStringContainsString('[Agent] Tuesday at 4 PM works.', $context['recent_messages']);
        $this->assertStringContainsString('I want to see the 2021 Civic tomorrow.', $context['recent_messages']);
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

        $result = new RecordVoiceTranscriptAction(
            app: $agent->app,
            agent: $agent,
            phone: $phone,
            callSid: 'CAblank',
            turns: [
                ['role' => 'customer', 'text' => '   '],
                ['role' => 'system', 'text' => 'You are an agent.'],
                ['role' => 'customer', 'text' => 'Hello?'],
            ],
        )->execute();

        $this->assertSame(1, $result['saved']);
        $this->assertSame(
            ['Hello?'],
            $this->messagesOn($people)->map(fn (Message $m) => $m->message['content'])->all(),
        );
    }

    public function testRolesAreLookedUpInTheGivenAppAndTheScopeIsPutBack(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);
        $previousScope = Bouncer::scope()->get();
        // Another app's scope, as under the runtime's app key: filing on the person's channel looks
        // up the agent's app Admin role, which isn't found unless the action scopes itself.
        $otherScope = 'app_0_company_0';
        Bouncer::scope()->to($otherScope);

        try {
            $result = $this->record($agent, $phone, 'CAscope');
            $scopeAfter = Bouncer::scope()->get();
        } finally {
            Bouncer::scope()->to($previousScope);
        }

        $this->assertSame('success', $result['status']);
        $this->assertSame($otherScope, $scopeAfter);
        $this->assertCount(3, $this->messagesOn($people));
    }

    public function testTheScopeIsPutBackWhenSavingFails(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $this->capture($agent, $phone);
        $previousScope = Bouncer::scope()->get();
        $otherScope = 'app_0_company_0';
        $creatingMessage = 'eloquent.creating: ' . Message::class;
        Event::listen($creatingMessage, fn () => throw new RuntimeException('The message store is down.'));
        Bouncer::scope()->to($otherScope);

        try {
            $result = $this->record($agent, $phone, 'CAfails');
            $scopeAfter = Bouncer::scope()->get();
        } finally {
            Bouncer::scope()->to($previousScope);
            Event::forget($creatingMessage);
        }

        $this->assertSame('error', $result['status']);
        $this->assertSame($otherScope, $scopeAfter);
    }

    public function testAnAppThatIsNotTheAgentsSavesNothing(): void
    {
        $agent = $this->createAgent();
        $phone = $this->uniquePhone();
        $people = $this->capture($agent, $phone);
        $otherApp = Apps::where('id', '!=', $agent->apps_id)->firstOrFail();

        $result = new RecordVoiceTranscriptAction(
            app: $otherApp,
            agent: $agent,
            phone: $phone,
            callSid: 'CAotherapp',
            turns: self::TURNS,
        )->execute();

        $this->assertSame('error', $result['status']);
        $this->assertCount(0, $this->messagesOn($people));
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
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['saved']);
    }

    private function record(Agent $agent, string $phone, string $callSid): array
    {
        return new RecordVoiceTranscriptAction(
            app: $agent->app,
            agent: $agent,
            phone: $phone,
            callSid: $callSid . $phone,
            turns: self::TURNS,
            direction: 'inbound',
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
