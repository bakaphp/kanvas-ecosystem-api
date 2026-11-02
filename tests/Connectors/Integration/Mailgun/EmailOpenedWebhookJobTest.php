<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Webhooks\EmailOpenedWebhookJob;
use Kanvas\Guild\Campaigns\Enums\CampaignRecipientStatusEnum;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class EmailOpenedWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => EmailOpenedWebhookJob::class],
            ['name' => 'EmailOpenedWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->user->getId())
            ->company($this->company->getId())
            ->create(['action_id' => $action->getId()]);
    }

    public function testOpenedEventRecordsANoteOnThePeopleFromTheUserVariable(): void
    {
        $people = $this->seedPeople();

        $result = $this->deliver([
            'event-data' => [
                'id' => 'evt-' . uniqid(),
                'event' => 'opened',
                'recipient' => 'prospect@example.com',
                'user-variables' => ['people_id' => (string) $people->getId()],
            ],
        ]);

        $this->assertSame($people->getId(), $result['people_id']);

        $note = $people->notes->messages()->latest('id')->first();
        $this->assertNotNull($note, 'The open must land a note in the people notes channel');
        $this->assertStringContainsString('prospect@example.com', (string) ($note->message['content'] ?? ''));
        $this->assertSame(1, $note->is_public);
    }

    public function testNonOpenedEventsAreIgnored(): void
    {
        $people = $this->seedPeople();
        $before = $people->notes->messages()->count();

        $result = $this->deliver([
            'event-data' => [
                'id' => 'evt-' . uniqid(),
                'event' => 'delivered',
                'user-variables' => ['people_id' => (string) $people->getId()],
            ],
        ]);

        $this->assertSame('Not an opened event, ignored', $result['message']);
        $this->assertSame($before, $people->notes->messages()->count());
    }

    public function testAnOpenMailgunFlagsAsABotPrefetchRecordsNothing(): void
    {
        $people = $this->seedPeople();
        $before = $people->notes->messages()->count();

        foreach (['apple', 'gmail', 'generic'] as $bot) {
            $result = $this->deliver([
                'event-data' => [
                    'id' => 'evt-' . uniqid(),
                    'event' => 'opened',
                    'recipient' => 'prospect@example.com',
                    'client-info' => ['bot' => $bot],
                    'user-variables' => ['people_id' => (string) $people->getId()],
                ],
            ]);

            $this->assertSame('Automated open (' . $bot . '), ignored', $result['message']);
        }

        $this->assertSame($before, $people->notes->messages()->count());
    }

    public function testAnOpenWithAnEmptyBotFieldIsAHumanAndIsRecorded(): void
    {
        $people = $this->seedPeople();

        $result = $this->deliver([
            'event-data' => [
                'id' => 'evt-' . uniqid(),
                'event' => 'opened',
                'recipient' => 'prospect@example.com',
                'client-info' => ['bot' => '', 'client-type' => 'browser'],
                'user-variables' => ['people_id' => (string) $people->getId()],
            ],
        ]);

        $this->assertSame($people->getId(), $result['people_id']);
    }

    public function testAnEventWithNoPeopleIdVariableIsIgnored(): void
    {
        $result = $this->deliver([
            'event-data' => [
                'id' => 'evt-' . uniqid(),
                'event' => 'opened',
                'recipient' => 'prospect@example.com',
            ],
        ]);

        $this->assertSame('Event carries no people_id or lead_id, nothing to record against', $result['message']);
    }

    public function testAPeopleIdThatNoLongerExistsFailsGracefullyInsteadOfThrowing(): void
    {
        $result = $this->deliver([
            'event-data' => [
                'id' => 'evt-' . uniqid(),
                'event' => 'opened',
                'user-variables' => ['people_id' => '999999999'],
            ],
        ]);

        $this->assertStringContainsString('no longer exists', $result['message']);
    }

    public function testTheSameEventIdDeliveredTwiceOnlyRecordsOneNote(): void
    {
        $people = $this->seedPeople();
        $eventId = 'evt-' . uniqid();
        $payload = [
            'event-data' => [
                'id' => $eventId,
                'event' => 'opened',
                'recipient' => 'prospect@example.com',
                'user-variables' => ['people_id' => (string) $people->getId()],
            ],
        ];

        $first = $this->deliver($payload);
        $second = $this->deliver($payload);

        $this->assertSame($people->getId(), $first['people_id']);
        $this->assertSame('Duplicate delivery', $second['message']);
        $this->assertSame(1, $people->notes->messages()->count());
    }

    public function testOpenedEventWithALeadIdRecordsOnTheLeadsOwnActivityNotThePeoplesNotes(): void
    {
        $lead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();
        $peopleNotesBefore = $lead->people->notes->messages()->count();

        $result = $this->deliver([
            'event-data' => [
                'id' => 'evt-' . uniqid(),
                'event' => 'opened',
                'recipient' => 'prospect@example.com',
                'user-variables' => [
                    'people_id' => (string) $lead->people->getId(),
                    'lead_id' => (string) $lead->getId(),
                ],
            ],
        ]);

        $this->assertSame($lead->getId(), $result['lead_id']);

        $leadNote = ($lead->systemNotes ?? $lead->notes)->messages()->latest('id')->first();
        $this->assertNotNull($leadNote, 'The open must land a note on the lead\'s own activity feed');
        $this->assertStringContainsString('prospect@example.com', (string) ($leadNote->message['content'] ?? ''));
        $this->assertSame(1, $leadNote->is_public);

        // Went to the Lead's feed, not the underlying People's notes channel — the two must not merge.
        $this->assertSame($peopleNotesBefore, $lead->people->notes->messages()->count());
    }

    public function testTheFirstOpenOfACampaignEmailMarksTheSlotOpenedAndCountsOnTheCampaign(): void
    {
        $people = $this->seedPeople();
        $recipient = $this->seedCampaignRecipient($people, $this->company);

        $result = $this->deliver($this->campaignOpen($people, $recipient));

        $this->assertSame($recipient->getId(), $result['campaign_recipient_id']);
        $this->assertTrue($result['campaign_first_open']);
        $this->assertSame($people->getId(), $result['people_id'], 'The people note must still be recorded');

        $recipient->refresh();
        $this->assertSame(CampaignRecipientStatusEnum::OPENED->value, $recipient->status);
        $this->assertNotNull($recipient->opened_at);
        $this->assertSame(1, $recipient->open_count);
        $this->assertSame(1, $recipient->campaign->opened_count);
    }

    public function testALaterOpenOnlyBumpsTheSlotsOpenCountNotTheCampaignsOpenedCount(): void
    {
        $people = $this->seedPeople();
        $recipient = $this->seedCampaignRecipient($people, $this->company);

        $this->deliver($this->campaignOpen($people, $recipient));
        $firstOpenedAt = $recipient->refresh()->opened_at;
        $result = $this->deliver($this->campaignOpen($people, $recipient));

        $this->assertFalse($result['campaign_first_open']);

        $recipient->refresh();
        $this->assertSame(2, $recipient->open_count);
        $this->assertEquals($firstOpenedAt, $recipient->opened_at);
        $this->assertSame(1, $recipient->campaign->opened_count);
    }

    public function testACampaignRecipientFromAnotherCompanyIsNotTouched(): void
    {
        $people = $this->seedPeople();
        $recipient = $this->seedCampaignRecipient($people, Companies::factory()->create());

        $result = $this->deliver($this->campaignOpen($people, $recipient));

        $this->assertArrayNotHasKey('campaign_recipient_id', $result);
        $this->assertStringContainsString('not found in this tenant', $result['campaign_recipient']);

        $recipient->refresh();
        $this->assertSame(CampaignRecipientStatusEnum::SENT->value, $recipient->status);
        $this->assertSame(0, $recipient->open_count);
        $this->assertSame(0, $recipient->campaign->opened_count);
    }

    private function seedCampaignRecipient(People $people, Companies $company): CampaignRecipient
    {
        $campaign = new Campaign();
        $campaign->apps_id = $this->kanvasApp->getId();
        $campaign->companies_id = $company->getId();
        $campaign->users_id = $this->user->getId();
        $campaign->channel = 'email';
        $campaign->message = 'Batch body';
        $campaign->status = CampaignStatusEnum::SENT->value;
        $campaign->total_recipients = 1;
        $campaign->sent_count = 1;
        $campaign->saveOrFail();

        $recipient = CampaignRecipient::pending($campaign, leadId: null, peopleId: $people->getId());
        $recipient->status = CampaignRecipientStatusEnum::SENT->value;
        $recipient->saveOrFail();

        return $recipient;
    }

    private function campaignOpen(People $people, CampaignRecipient $recipient): array
    {
        return [
            'event-data' => [
                'id' => 'evt-' . uniqid(),
                'event' => 'opened',
                'recipient' => 'prospect@example.com',
                'user-variables' => [
                    'people_id' => (string) $people->getId(),
                    'campaign_recipient_id' => (string) $recipient->getId(),
                ],
            ],
        ];
    }

    private function seedPeople(): People
    {
        return PeopleFactory::new()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create();
    }

    private function deliver(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();
        $result = new EmailOpenedWebhookJob($webhookRequest)->handle();

        if (! is_array($result)) {
            $this->fail('Job failed: ' . json_encode($webhookRequest->refresh()->exception['message'] ?? 'unknown'));
        }

        return $result;
    }
}
