<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompanyUpdateEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompletedEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementStatusChangedEvent;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsChecklistFixtures;
use TypeError;

/**
 * Event::fake() must name the three engagement events explicitly: a bare fake also swallows
 * `eloquent.created: Engagement`, so the observer never runs and every assertion here inverts
 * silently. Faking the two siblings keeps EngagementStatusChangedEvent's notification fan-out,
 * which runs in its constructor, out of the test.
 */
final class EngagementCompletedBroadcastTest extends TestCase
{
    use BuildsChecklistFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'crm', 'social'];

    private Lead $lead;

    /**
     * `actions.slug` is globally unique, so a fixed slug collides with whatever the shared test DB
     * already holds. Every test gets its own.
     */
    private string $actionSlug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actionSlug = 'credit-app-' . Str::lower(Str::random(8));

        // Before the factory, not after: LeadFactory:110 emits a nervous-system ledger event, and
        // an unfaked queue runs AppendToLedgerJob inline — an extra DB connection per lead, which
        // exhausts MySQL under paratest.
        Queue::fake();

        $this->lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();

        Event::fake([
            EngagementCompletedEvent::class,
            EngagementStatusChangedEvent::class,
            EngagementCompanyUpdateEvent::class,
        ]);
    }

    public function testBroadcastChannelEventNameAndPayload(): void
    {
        $event = new EngagementCompletedEvent(
            leadId: 42,
            leadUuid: 'b5f0c1de-1111-4222-8333-444455556666',
            engagementId: 7,
            action: 'credit-app-3',
            companyActionId: 9,
            messageId: 11,
            completedAt: '2026-09-18T10:00:00+00:00'
        );

        $this->assertSame(
            'engagement-completed-lead-b5f0c1de-1111-4222-8333-444455556666',
            $event->broadcastOn()->name
        );
        $this->assertSame('engagement.completed', $event->broadcastAs());
        $this->assertSame('broadcasts', $event->broadcastQueue);
        $this->assertSame(
            [
                'leadId' => 42,
                'leadUuid' => 'b5f0c1de-1111-4222-8333-444455556666',
                'engagementId' => 7,
                'action' => 'credit-app-3',
                'companyActionId' => 9,
                'messageId' => 11,
                'completedAt' => '2026-09-18T10:00:00+00:00',
            ],
            $event->broadcastWith()
        );
    }

    public function testFiresOnceWhenCreatedWithSubmittedMessage(): void
    {
        $engagement = $this->submittedEngagement($this->actionSlug);

        Event::assertDispatchedTimes(EngagementCompletedEvent::class, 1);
        Event::assertDispatched(
            EngagementCompletedEvent::class,
            fn (EngagementCompletedEvent $event): bool => $event->engagementId === $engagement->getId()
                && $event->leadUuid === $this->lead->uuid
                && $event->leadId === $this->lead->getId()
                && $event->messageId === $engagement->message_id
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('nonSubmittedPayloads')]
    public function testDoesNotFireForNonSubmittedStatuses(array $payload): void
    {
        $payload['verb'] = $this->actionSlug;
        $this->engagementWithPayload($this->actionSlug, $payload);

        Event::assertNotDispatched(EngagementCompletedEvent::class);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function nonSubmittedPayloads(): array
    {
        return [
            'sent' => [['status' => 'sent']],
            'opened' => [['status' => 'opened']],
            'downloaded' => [['status' => 'downloaded']],
            'no status key' => [[]],
        ];
    }

    /**
     * Our guard skips a lead-less engagement, but EngagementStatusChangedEvent then fatals on it:
     * its `protected Lead $lead` is assigned unguarded in the constructor
     * (EngagementStatusChangedEvent.php:32), which Dispatchable runs even under Event::fake().
     * That is a pre-existing bug, out of scope here — this test pins that OUR event stays silent
     * and will start failing the day the sibling is fixed, which is when it should be revisited.
     */
    public function testDoesNotFireWhenEngagementHasNoLead(): void
    {
        $wiring = $this->makeChecklistWiring($this->lead, $this->actionSlug);
        $message = $this->makeChecklistMessage($this->lead, ['verb' => $this->actionSlug, 'status' => 'submitted']);

        $engagement = new Engagement();
        $engagement->companies_id = $this->lead->company->getId();
        $engagement->apps_id = app(Apps::class)->getId();
        $engagement->users_id = auth()->user()->getId();
        $engagement->leads_id = 0;
        $engagement->people_id = 0;
        $engagement->companies_actions_id = $wiring['companyAction']->getId();
        $engagement->message_id = $message->getId();
        $engagement->slug = $this->actionSlug;
        $engagement->entity_uuid = Str::uuid()->toString();
        $engagement->pipelines_stages_id = 0;

        try {
            $engagement->saveOrFail();
        } catch (TypeError $e) {
            $this->assertStringContainsString('EngagementStatusChangedEvent', $e->getMessage());
        }

        Event::assertNotDispatched(EngagementCompletedEvent::class);
    }

    public function testFiresOnStageUpdateWhenMessageIsAlreadySubmitted(): void
    {
        $engagement = $this->engagementWithPayload($this->actionSlug, ['verb' => $this->actionSlug, 'status' => 'sent']);

        Event::assertNotDispatched(EngagementCompletedEvent::class);

        $message = Message::getById($engagement->message_id);
        $message->message = ['verb' => $this->actionSlug, 'status' => 'submitted'];
        $message->saveOrFail();

        // The engagement caches the message it loaded during created(); without dropping the
        // relation the observer would re-read the stale 'sent' payload.
        $engagement = $engagement->fresh();
        $engagement->pipelines_stages_id = 999;
        $engagement->saveOrFail();

        Event::assertDispatchedTimes(EngagementCompletedEvent::class, 1);
    }

    /**
     * The observer's gate only watches message_id and pipelines_stages_id, so rewriting the payload
     * in place is invisible to it. Pinned because a client must not expect an event here.
     */
    public function testDoesNotFireWhenOnlyTheMessagePayloadIsRewritten(): void
    {
        $engagement = $this->engagementWithPayload($this->actionSlug, ['verb' => $this->actionSlug, 'status' => 'sent']);

        $message = Message::getById($engagement->message_id);
        $message->message = ['verb' => $this->actionSlug, 'status' => 'submitted'];
        $message->saveOrFail();

        Event::assertNotDispatched(EngagementCompletedEvent::class);
    }

    public function testPayloadActionIsTheEngagementSlugNotTheCompanyActionName(): void
    {
        $wiring = $this->makeChecklistWiring($this->lead, $this->actionSlug);
        $wiring['companyAction']->name = 'Credit Application';
        $wiring['companyAction']->saveOrFail();

        $message = $this->makeChecklistMessage($this->lead, ['verb' => $this->actionSlug, 'status' => 'submitted']);
        $this->makeChecklistEngagement($this->lead, $wiring['companyAction'], $this->actionSlug . '-3', $message->getId());

        Event::assertDispatched(
            EngagementCompletedEvent::class,
            fn (EngagementCompletedEvent $event): bool => $event->action === $this->actionSlug . '-3'
        );
    }

    public function testCompletedAtIsAnIsoStringMatchingTheEngagementTimestamp(): void
    {
        $engagement = $this->submittedEngagement($this->actionSlug);
        $fresh = $engagement->fresh();
        $expected = ($fresh->updated_at ?? $fresh->created_at)->toIso8601String();

        Event::assertDispatched(
            EngagementCompletedEvent::class,
            function (EngagementCompletedEvent $event) use ($expected): bool {
                $this->assertIsString($event->completedAt);
                $this->assertIsString($event->broadcastWith()['completedAt']);

                return $event->completedAt === $expected;
            }
        );
    }

    /**
     * A double-encoded body is a string once the Json cast runs, so `$message->message['status']`
     * would be a TypeError. getMessage() is what makes this survive.
     */
    public function testHasSubmittedMessageSurvivesDoubleEncodedMessageJson(): void
    {
        $engagement = $this->submittedEngagement($this->actionSlug);

        DB::connection('social')
            ->table('messages')
            ->where('id', $engagement->message_id)
            ->update(['message' => json_encode(json_encode(['verb' => $this->actionSlug, 'status' => 'submitted']))]);

        $this->assertTrue($engagement->fresh()->hasSubmittedMessage());

        DB::connection('social')
            ->table('messages')
            ->where('id', $engagement->message_id)
            ->update(['message' => json_encode(json_encode(['verb' => $this->actionSlug, 'status' => 'opened']))]);

        $this->assertFalse($engagement->fresh()->hasSubmittedMessage());
    }

    private function submittedEngagement(string $slug): Engagement
    {
        return $this->engagementWithPayload($slug, ['verb' => $slug, 'status' => 'submitted']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function engagementWithPayload(string $slug, array $payload): Engagement
    {
        $wiring = $this->makeChecklistWiring($this->lead, $slug);
        $message = $this->makeChecklistMessage($this->lead, $payload);

        return $this->makeChecklistEngagement($this->lead, $wiring['companyAction'], $slug, $message->getId());
    }
}
