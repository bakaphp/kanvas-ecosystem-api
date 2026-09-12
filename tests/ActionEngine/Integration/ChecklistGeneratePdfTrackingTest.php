<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Tasks\Actions\TrackChecklistPdfGenerationAction;
use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;
use Kanvas\ActionEngine\Tasks\Events\ChecklistGeneratePdfEvent;
use Kanvas\ActionEngine\Tasks\Support\ChecklistPdfContext;
use Kanvas\ActionEngine\Tasks\Support\ChecklistPdfEntry;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;
use Tests\Traits\BuildsChecklistFixtures;

final class ChecklistGeneratePdfTrackingTest extends TestCase
{
    use BuildsChecklistFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'action_engine', 'ecosystem', 'social'];

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lead = Lead::factory()->create();
    }

    /**
     * Redis is not rolled back by DatabaseTransactions and set() writes it before the DB, so a
     * leftover custom field would leak into the next test through get()'s Redis-first read.
     */
    protected function tearDown(): void
    {
        $this->lead->del(TrackChecklistPdfGenerationAction::CUSTOM_FIELD);

        parent::tearDown();
    }

    public function testGeneratingCreatesTheCustomFieldWhenAbsent(): void
    {
        $context = $this->makeContext('pdf-track-absent');

        $entries = new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::GENERATING, $entries[0]->status);
        $this->assertSame($context->taskListItem->getId(), $entries[0]->taskId);
        $this->assertSame($context->taskListItem->companyAction->getId(), $entries[0]->companyActionId);
        $this->assertSame((int) $context->taskListItem->companyAction->actions_id, $entries[0]->actionId);
        $this->assertSame((int) $context->engagement->message_id, $entries[0]->messageId);
    }

    /**
     * The client needs message_id to find this run's entity_integration_history row and hand it to
     * integrationWorkflowRetry, so it has to survive the round trip through the custom field.
     */
    public function testMessageIdSurvivesTheRoundTripThroughTheCustomField(): void
    {
        $context = $this->makeContext('pdf-track-message-id');

        new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::FAILED
        )->execute();

        $stored = $this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD);

        $this->assertSame((int) $context->engagement->message_id, $stored[0]['message_id']);
        $this->assertNotSame(0, $stored[0]['message_id']);
    }

    public function testGeneratingAppendsSecondTaskWithoutTouchingTheFirst(): void
    {
        $first = $this->makeContext('pdf-track-append-one');
        $second = $this->makeContext('pdf-track-append-two');

        new TrackChecklistPdfGenerationAction(
            context: $first,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        $entries = new TrackChecklistPdfGenerationAction(
            context: $second,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        $this->assertCount(2, $entries);
        $this->assertSame(
            [$first->taskListItem->getId(), $second->taskListItem->getId()],
            array_map(fn (ChecklistPdfEntry $entry): int => $entry->taskId, $entries)
        );
    }

    public function testRetryUpsertsRatherThanDuplicating(): void
    {
        $context = $this->makeContext('pdf-track-upsert');

        foreach ([ChecklistPdfGenerationEnum::GENERATING, ChecklistPdfGenerationEnum::FAILED, ChecklistPdfGenerationEnum::GENERATING] as $status) {
            $entries = new TrackChecklistPdfGenerationAction(context: $context, status: $status)->execute();
        }

        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::GENERATING, $entries[0]->status);
    }

    public function testFailedFlipsTheStatusInPlace(): void
    {
        $context = $this->makeContext('pdf-track-failed');

        new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        $entries = new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::FAILED
        )->execute();

        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::FAILED, $entries[0]->status);
    }

    public function testClearingTheLastEntryDeletesTheCustomFieldRow(): void
    {
        $context = $this->makeContext('pdf-track-clear-last');

        new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        $entries = new TrackChecklistPdfGenerationAction(context: $context, status: null)->execute();

        $this->assertSame([], $entries);
        $this->assertNull($this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
        $this->assertNull($this->lead->getCustomField(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
    }

    public function testClearingOneTaskLeavesAConcurrentTaskEntryAlive(): void
    {
        $taskA = $this->makeContext('pdf-track-concurrent-a');
        $taskB = $this->makeContext('pdf-track-concurrent-b');

        new TrackChecklistPdfGenerationAction(
            context: $taskA,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();
        new TrackChecklistPdfGenerationAction(
            context: $taskB,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        $entries = new TrackChecklistPdfGenerationAction(context: $taskA, status: null)->execute();

        $this->assertCount(1, $entries);
        $this->assertSame($taskB->taskListItem->getId(), $entries[0]->taskId);
        $this->assertNotNull($this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
    }

    public function testEveryWriteNotifiesTheLeadChannel(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $context = $this->makeContext('pdf-track-broadcast');

        new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();
        new TrackChecklistPdfGenerationAction(context: $context, status: null)->execute();

        Event::assertDispatchedTimes(ChecklistGeneratePdfEvent::class, 2);
        Event::assertDispatched(
            ChecklistGeneratePdfEvent::class,
            fn (ChecklistGeneratePdfEvent $event): bool => $event->leadUuid === $this->lead->uuid
        );
    }

    /**
     * Re-marking a task that is already in that state — or clearing one that has no entry — would
     * otherwise rewrite an identical array, firing a create-custom-field workflow and a broadcast
     * that makes every client refetch for nothing.
     */
    public function testAnUnchangedWriteNotifiesNobody(): void
    {
        $context = $this->makeContext('pdf-track-noop');

        new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        Event::fake([ChecklistGeneratePdfEvent::class]);

        new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    public function testClearingATaskThatWasNeverTrackedNotifiesNobody(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $entries = new TrackChecklistPdfGenerationAction(
            context: $this->makeContext('pdf-track-clear-unknown'),
            status: null
        )->execute();

        $this->assertSame([], $entries);
        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    /**
     * A deploy that lands mid-generation leaves entries without `message_id`. They have to keep
     * working — invalidating them would strand a spinner the client can no longer clear.
     */
    public function testAnEntryWithoutMessageIdSurvivesOtherWrites(): void
    {
        $legacy = $this->makeContext('pdf-track-legacy-survives');
        $other = $this->makeContext('pdf-track-legacy-other');

        $this->seedEntryWithoutMessageId($legacy);

        $entries = new TrackChecklistPdfGenerationAction(
            context: $other,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        $this->assertCount(2, $entries);

        $survivor = current(array_filter(
            $entries,
            fn (ChecklistPdfEntry $entry): bool => $entry->taskId === $legacy->taskListItem->getId()
        ));

        $this->assertInstanceOf(ChecklistPdfEntry::class, $survivor);
        $this->assertSame(0, $survivor->messageId);
        $this->assertSame(ChecklistPdfGenerationEnum::GENERATING, $survivor->status);
    }

    public function testAnEntryWithoutMessageIdIsStillMatchableByTask(): void
    {
        $legacy = $this->makeContext('pdf-track-legacy-clear');

        $this->seedEntryWithoutMessageId($legacy);

        $entries = new TrackChecklistPdfGenerationAction(context: $legacy, status: null)->execute();

        $this->assertSame([], $entries);
        $this->assertNull($this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
    }

    /**
     * The event is a notification, not a snapshot — carrying entries would make the client reconcile
     * payloads the queue and Pusher do not deliver in order.
     */
    public function testTheBroadcastCarriesNoPayload(): void
    {
        $this->assertSame([], new ChecklistGeneratePdfEvent((string) $this->lead->uuid)->broadcastWith());
    }

    /**
     * Regression: Action::getBySlug() is nullable and CompanyAction::getByAction()'s first parameter
     * is not, so a soft-deleted Action used to raise a TypeError here. TypeError extends Error, not
     * Exception, so the activity's `catch (Exception)` never caught it — it escaped to
     * executeIntegration, got report()ed to Sentry, and the response dropped the id of the file that
     * had already been generated. ModelNotFoundException keeps it on the intended failWorkflow path.
     */
    public function testSoftDeletedActionThrowsModelNotFoundInsteadOfTypeError(): void
    {
        $context = $this->makeContext('pdf-track-soft-deleted');
        $companyAction = $context->taskListItem->companyAction;

        $action = Action::findOrFail($companyAction->actions_id);
        $action->is_deleted = 1;
        $action->saveOrFail();

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('Action not found');

        ChecklistPdfContext::fromMessage($context->engagement->message, app(Apps::class));
    }

    public function testBroadcastChannelAndEventName(): void
    {
        $event = new ChecklistGeneratePdfEvent((string) $this->lead->uuid);

        $this->assertSame(
            'checklist-generate-pdf-lead-' . $this->lead->uuid,
            $event->broadcastOn()->name
        );
        $this->assertSame('checklist.generate.pdf', $event->broadcastAs());
    }

    /**
     * The shape the custom field held before `message_id` was added to it.
     */
    private function seedEntryWithoutMessageId(ChecklistPdfContext $context): void
    {
        $companyAction = $context->taskListItem->companyAction;

        $this->lead->set(TrackChecklistPdfGenerationAction::CUSTOM_FIELD, [[
            'action_id' => (int) $companyAction->actions_id,
            'company_action_id' => $companyAction->getId(),
            'task_id' => $context->taskListItem->getId(),
            'status' => ChecklistPdfGenerationEnum::GENERATING->value,
        ]]);
    }

    private function makeContext(string $slug): ChecklistPdfContext
    {
        $wiring = $this->makeChecklistWiring($this->lead, $slug);

        $message = $this->makeChecklistMessage($this->lead, [
            'verb' => $slug,
            'checkListId' => $wiring['taskList']->getId(),
        ]);

        $engagement = $this->makeChecklistEngagement(
            $this->lead,
            $wiring['companyAction'],
            $slug,
            $message->getId()
        );

        return new ChecklistPdfContext(engagement: $engagement, taskListItem: $wiring['taskListItem']);
    }
}
