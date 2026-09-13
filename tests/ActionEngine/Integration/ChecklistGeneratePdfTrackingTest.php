<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
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

        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);

        $entries = $this->entries();

        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::GENERATING, $entries[0]->status);
        $this->assertSame($context->taskListItem->getId(), $entries[0]->taskId);
        $this->assertSame($context->taskListItem->companyAction->getId(), $entries[0]->companyActionId);
        $this->assertSame((int) $context->taskListItem->companyAction->actions_id, $entries[0]->actionId);
        $this->assertSame((int) $context->engagement->message_id, $entries[0]->messageId);
        $this->assertNotSame(0, $entries[0]->messageId);
    }

    public function testGeneratingAppendsSecondTaskWithoutTouchingTheFirst(): void
    {
        $first = $this->makeContext('pdf-track-append-one');
        $second = $this->makeContext('pdf-track-append-two');

        $this->track($first, ChecklistPdfGenerationEnum::GENERATING);
        $this->track($second, ChecklistPdfGenerationEnum::GENERATING);

        $this->assertSame(
            [$first->taskListItem->getId(), $second->taskListItem->getId()],
            array_map(fn (ChecklistPdfEntry $entry): int => $entry->taskId, $this->entries())
        );
    }

    public function testRetryUpsertsRatherThanDuplicating(): void
    {
        $context = $this->makeContext('pdf-track-upsert');

        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);
        $this->track($context, ChecklistPdfGenerationEnum::FAILED);
        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);

        $entries = $this->entries();

        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::GENERATING, $entries[0]->status);
    }

    public function testFailedFlipsTheStatusInPlace(): void
    {
        $context = $this->makeContext('pdf-track-failed');

        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);
        $this->track($context, ChecklistPdfGenerationEnum::FAILED);

        $entries = $this->entries();

        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::FAILED, $entries[0]->status);
    }

    public function testClearingTheLastEntryDeletesTheCustomFieldRow(): void
    {
        $context = $this->makeContext('pdf-track-clear-last');

        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);
        $this->track($context, null);

        $this->assertNull($this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
        $this->assertNull($this->lead->getCustomField(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
    }

    public function testClearingOneTaskLeavesAConcurrentTaskEntryAlive(): void
    {
        $taskA = $this->makeContext('pdf-track-concurrent-a');
        $taskB = $this->makeContext('pdf-track-concurrent-b');

        $this->track($taskA, ChecklistPdfGenerationEnum::GENERATING);
        $this->track($taskB, ChecklistPdfGenerationEnum::GENERATING);
        $this->track($taskA, null);

        $entries = $this->entries();

        $this->assertCount(1, $entries);
        $this->assertSame($taskB->taskListItem->getId(), $entries[0]->taskId);
    }

    public function testEveryWriteNotifiesTheLeadChannel(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $context = $this->makeContext('pdf-track-broadcast');

        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);
        $this->track($context, null);

        Event::assertDispatchedTimes(ChecklistGeneratePdfEvent::class, 2);
        Event::assertDispatched(
            ChecklistGeneratePdfEvent::class,
            fn (ChecklistGeneratePdfEvent $event): bool => $event->leadUuid === $this->lead->uuid
        );
    }

    /**
     * An identical rewrite would fire a create-custom-field workflow and make every client refetch
     * for nothing.
     */
    public function testAnUnchangedWriteNotifiesNobody(): void
    {
        $context = $this->makeContext('pdf-track-noop');

        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);

        Event::fake([ChecklistGeneratePdfEvent::class]);

        $this->track($context, ChecklistPdfGenerationEnum::GENERATING);

        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    public function testClearingATaskThatWasNeverTrackedNotifiesNobody(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $this->track($this->makeContext('pdf-track-clear-unknown'), null);

        $this->assertSame([], $this->entries());
        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    /**
     * A TypeError here would escape the activity's `catch (Exception)` and drop the generated file's
     * id from the response.
     */
    public function testSoftDeletedActionThrowsModelNotFound(): void
    {
        $context = $this->makeContext('pdf-track-soft-deleted');

        $action = $context->taskListItem->companyAction->action;
        $action->is_deleted = 1;
        $action->saveOrFail();

        $this->expectException(ModelNotFoundException::class);

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
        $this->assertSame([], $event->broadcastWith());
    }

    private function track(ChecklistPdfContext $context, ?ChecklistPdfGenerationEnum $status): void
    {
        new TrackChecklistPdfGenerationAction(context: $context, status: $status)->execute();
    }

    /**
     * @return list<ChecklistPdfEntry>
     */
    private function entries(): array
    {
        return TrackChecklistPdfGenerationAction::readEntries($this->lead);
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
