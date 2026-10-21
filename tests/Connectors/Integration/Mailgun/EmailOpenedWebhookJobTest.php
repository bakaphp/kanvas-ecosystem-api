<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Webhooks\EmailOpenedWebhookJob;
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

        // Went to the Lead's feed, not the underlying People's notes channel — the two must not merge.
        $this->assertSame($peopleNotesBefore, $lead->people->notes->messages()->count());
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
