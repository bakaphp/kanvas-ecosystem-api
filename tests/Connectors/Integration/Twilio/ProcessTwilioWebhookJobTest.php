<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Jobs\FlushMessageBurstJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageBurstService;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class ProcessTwilioWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Twilio integration tests are skipped in CI');
        }

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, 'test_sid');
        $app->set(ConfigurationEnum::TWILIO_AUTH_TOKEN->value, 'test_token');

        LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Warm',
            ],
            [
                'description' => 'Warm Lead Type',
                'is_active' => true,
                'uuid' => Str::uuid(),
            ]
        );

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessTwilioWebhookJob::class],
            ['name' => 'ProcessTwilioWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testProcessIncomingSmsCreatesLeadAndMessage(): void
    {
        $payload = $this->buildTwilioPayload();

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('message_id', $result[0]);
        $this->assertArrayHasKey('channel_id', $result[0]);
        $this->assertFalse($result[0]['is_from_me']);

        // The job attaches the lead after creating the message; without people_id the inbound
        // reply is invisible to the Engage usage report.
        $message = Message::query()->where('id', $result[0]['message_id'])->first();
        $this->assertSame('contact', $message->sender_type);
        $this->assertNotNull($message->people_id);
    }

    public function testProcessStopMarksPhoneContactsAsOptedOut(): void
    {
        $phone = '+1' . fake()->numerify('##########');
        $payload = $this->buildTwilioPayload([
            'From' => $phone,
            'Body' => 'STOP',
            'OptOutType' => 'STOP',
        ]);

        $result = $this->dispatchWebhookJob($payload);

        $lead = Lead::fromApp(app(Apps::class))
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->where('title', 'like', '%Twilio Opp%')
            ->latest('id')
            ->firstOrFail();

        $this->assertNotEmpty($lead->people->getAllPhones());
        $this->assertTrue(
            $lead->people->getAllPhones()->every(
                fn ($contact) => $contact->is_opt_out === 1
            )
        );
        $this->assertSame('STOP', $result[0]['consent_type']);
        $this->assertTrue($result[0]['automated_response_suppressed']);
        Queue::assertNotPushed(FlushMessageBurstJob::class);
    }

    public function testProcessStartOptsInOnlyTheInboundPhoneAndSuppressesAutomatedResponse(): void
    {
        $phone = '+1' . fake()->numerify('##########');

        $stopResult = $this->dispatchWebhookJob($this->buildTwilioPayload([
            'From' => $phone,
            'Body' => 'STOP',
            'OptOutType' => 'STOP',
        ]));

        $startResult = $this->dispatchWebhookJob($this->buildTwilioPayload([
            'From' => $phone,
            'Body' => 'START',
            'OptOutType' => 'START',
        ]));

        $lead = Lead::fromApp(app(Apps::class))
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->where('title', 'like', '%Twilio Opp%')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('STOP', $stopResult[0]['consent_type']);
        $this->assertTrue($stopResult[0]['automated_response_suppressed']);

        // START is reported but must NOT suppress the reply: someone asking to hear from us again
        // and getting silence is the opposite of honoring it.
        $this->assertSame('START', $startResult[0]['consent_type']);
        $this->assertFalse($startResult[0]['automated_response_suppressed']);
        $this->assertTrue(
            $lead->people->getAllPhones()
                ->filter(fn ($contact) => $contact->getCleanPhone() === ltrim($phone, '+'))
                ->every(fn ($contact) => $contact->is_opt_out === 0)
        );
    }

    public function testProcessHelpSuppressesTheAgentTurnBecauseTwilioAnswersItItself(): void
    {
        $phone = '+1' . fake()->numerify('##########');

        $result = $this->dispatchWebhookJob($this->buildTwilioPayload([
            'From' => $phone,
            'Body' => 'HELP',
            'OptOutType' => 'HELP',
        ]));

        // Twilio replies to HELP with the carrier advisory on its own. An agent turn on top is a
        // second message the customer did not ask for.
        $this->assertSame('HELP', $result[0]['consent_type']);
        $this->assertTrue($result[0]['automated_response_suppressed']);
        Queue::assertNotPushed(FlushMessageBurstJob::class);
    }

    public function testProcessAffirmativeYesIsNotTreatedAsAConsentEvent(): void
    {
        $phone = '+1' . fake()->numerify('##########');

        $result = $this->dispatchWebhookJob($this->buildTwilioPayload([
            'From' => $phone,
            'Body' => 'yes',
        ]));

        // "YES" is in Twilio's START set, but from someone who was never opted out it is just an
        // affirmative — "yes, book me in". The agent must still answer.
        $this->assertNull($result[0]['consent_type']);
        $this->assertFalse($result[0]['automated_response_suppressed']);
        Queue::assertPushed(FlushMessageBurstJob::class);
    }

    public function testProcessIncomingSmsWithMediaAttachesToLead(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response('fake-image-content', 200),
        ]);

        $payload = $this->buildTwilioPayload([
            'NumMedia' => '1',
            'MediaUrl0' => 'https://api.twilio.com/2010-04-01/Accounts/test/Messages/SM123/Media/ME123',
            'MediaContentType0' => 'image/jpeg',
        ]);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $messageId = $result[0]['message_id'];
        $chatJid = $result[0]['chat_jid'];

        $lead = Lead::fromApp(app(Apps::class))
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->where('title', 'like', '%Twilio Opp%')
            ->latest('id')
            ->first();

        $this->assertNotNull($lead, 'Lead should have been created');

        $leadFiles = $lead->getFiles();
        $this->assertNotEmpty($leadFiles, 'Lead should have files attached for the digital jacket');
    }

    /**
     * A customer whose auto-responder fires two texts back to back must get one answer, not one per
     * text. The window has to outlast the gap between them or each announces its own turn.
     */
    public function testTwoTextsBackToBackCollapseIntoOneTurn(): void
    {
        $phone = '+1' . fake()->numerify('##########');

        $first = $this->dispatchWebhookJob($this->buildTwilioPayload([
            'From' => $phone,
            'Body' => 'I am observing the Sabbath and will reply after sundown.',
        ]));

        $second = $this->dispatchWebhookJob($this->buildTwilioPayload([
            'From' => $phone,
            'Body' => '(I am not receiving notifications right now.)',
        ]));

        $headId = $first[0]['message_id'];
        $tail = Message::query()->findOrFail($second[0]['message_id']);

        $this->assertSame($headId, $tail->parent_id, 'The second text must join the first turn');
        $this->assertNotNull(
            Cache::get(FlushMessageBurstJob::cacheKey($headId)),
            'The burst stays armed on the head until it goes quiet'
        );
        $this->assertSame(
            "I am observing the Sabbath and will reply after sundown.\n\n(I am not receiving notifications right now.)",
            MessageBurstService::promptFor(MessageBurstService::messagesFor($headId)),
            'The agent sees the whole flurry, not just its first line'
        );
    }

    /**
     * Outbound has no flurry to collapse, and rules listening for the company's own messages expect
     * them without a burst window's delay in front.
     */
    public function testOurOwnOutboundSmsIsAnnouncedImmediatelyAndNeverArmsABurst(): void
    {
        $ownNumber = '+1' . fake()->numerify('##########');

        $result = $this->dispatchWebhookJob($this->buildTwilioPayload([
            'From' => $ownNumber,
            'To' => $ownNumber,
            'Body' => 'Following up from the dealership',
        ]));

        $this->assertTrue($result[0]['is_from_me']);
        Queue::assertNotPushed(FlushMessageBurstJob::class);
    }

    /**
     * Two People share one phone, each with their own lead and conversation channel. The text
     * belongs to whichever conversation was spoken in last — not the People holding an active
     * lead, not the first People row — and that has to hold in both directions as the
     * conversations trade turns. The lead that spoke last is deliberately a lost one: the old
     * "whoever has an active lead" rule would pick the other person every time.
     */
    public function testInboundSmsFollowsTheLeadWhoseChannelSpokeLast(): void
    {
        $phone = '+1' . fake()->numerify('##########');
        $job = $this->makeJob($this->buildTwilioPayload(['From' => $phone]));

        $activePeople = $this->createPeopleWithPhone($phone, 'Active');
        $lostPeople = $this->createPeopleWithPhone($phone, 'Lost');
        $activeLead = $job->createLeadFromPeople($activePeople);
        $lostLead = $job->createLeadFromPeople($lostPeople);
        $lostLead->leads_status_id = $this->lostStatus()->getId();
        $lostLead->saveOrFail();

        $activeChannel = $this->createLeadChannel($activeLead);
        $lostChannel = $this->createLeadChannel($lostLead);
        $activeChannel->addMessage(Message::factory()->create());
        $lostChannel->addMessage(Message::factory()->create());

        $peoples = People::query()->whereKey([$activePeople->getId(), $lostPeople->getId()])->get();
        $this->assertTrue($lostLead->is(
            LeadsRepository::getLeadWithMostRecentChannel($peoples, $this->receiver->app, $this->receiver->company)
        ));

        $result = $this->dispatchWebhookJob($this->buildTwilioPayload(['From' => $phone]));

        $smsChannel = Channel::query()->findOrFail($result[0]['channel_id']);
        $this->assertSame((string) $lostLead->getId(), $smsChannel->entity_id);
        $this->assertSame(
            $lostPeople->getId(),
            Message::query()->findOrFail($result[0]['message_id'])->people_id
        );

        // The other conversation speaks again, so the next text belongs to it. The SMS channel now
        // points at the lost lead and carries the last text, so the active one needs a fresher turn.
        $activeChannel->addMessage(Message::factory()->create());

        $this->assertTrue($activeLead->is(
            LeadsRepository::getLeadWithMostRecentChannel($peoples, $this->receiver->app, $this->receiver->company)
        ));

        $result = $this->dispatchWebhookJob($this->buildTwilioPayload(['From' => $phone]));

        $smsChannel = Channel::query()->findOrFail($result[0]['channel_id']);
        $this->assertSame((string) $activeLead->getId(), $smsChannel->entity_id);
        $this->assertSame(
            $activePeople->getId(),
            Message::query()->findOrFail($result[0]['message_id'])->people_id
        );
    }

    private function lostStatus(): LeadStatus
    {
        return LeadStatus::firstOrCreate(
            [
                'apps_id' => $this->receiver->app->getId(),
                'companies_id' => $this->receiver->company->getId(),
                'name' => 'Lost',
            ],
            ['is_default' => 0]
        );
    }

    private function createPeopleWithPhone(string $phone, string $firstname): People
    {
        return new CreatePeopleAction(new PeopleDto(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->receiver->user,
            firstname: $firstname,
            contacts: Contact::collect([
                [
                    'value' => Str::of($phone)->ltrim('+')->toString(),
                    'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                    'weight' => 100,
                ],
            ], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Shared Phone',
        ))->execute();
    }

    private function createLeadChannel(Lead $lead): Channel
    {
        return Channel::create([
            'name' => 'conversation-' . $lead->getId(),
            'slug' => 'conversation-' . $lead->getId(),
            'users_id' => $this->receiver->users_id,
            'apps_id' => $this->receiver->app->getId(),
            'companies_id' => $this->receiver->company->getId(),
            'entity_namespace' => Lead::class,
            'entity_id' => (string) $lead->getId(),
        ]);
    }

    private function buildTwilioPayload(array $overrides = []): array
    {
        $phone = '+1' . fake()->numerify('##########');

        return array_merge([
            'SmsMessageSid' => 'SM' . Str::random(32),
            'NumMedia' => '0',
            'SmsSid' => 'SM' . Str::random(32),
            'SmsStatus' => 'received',
            'Body' => 'Hello, I am interested in your services',
            'To' => '+18001234567',
            'From' => $phone,
            'AccountSid' => 'AC' . Str::random(32),
            'NumSegments' => '1',
            'ApiVersion' => '2010-04-01',
        ], $overrides);
    }

    private function dispatchWebhookJob(array $payload): array
    {
        $job = $this->makeJob($payload);

        Queue::fake();

        return $job->handle();
    }

    private function makeJob(array $payload): ProcessTwilioWebhookJob
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        return new ProcessTwilioWebhookJob(
            new ProcessWebhookAttemptAction($this->receiver, $request)->execute()
        );
    }
}
