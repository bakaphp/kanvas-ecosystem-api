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
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
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
        $this->assertSame(ChecklistPdfGenerationEnum::GENERATING->value, $entries[0]['status']);
        $this->assertSame($context->taskListItem->getId(), $entries[0]['task_id']);
        $this->assertSame($context->taskListItem->companyAction->getId(), $entries[0]['company_action_id']);
        $this->assertSame((int) $context->taskListItem->companyAction->actions_id, $entries[0]['action_id']);
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
            array_column($entries, 'task_id')
        );
    }

    public function testRetryUpsertsRatherThanDuplicating(): void
    {
        $context = $this->makeContext('pdf-track-upsert');

        foreach ([ChecklistPdfGenerationEnum::GENERATING, ChecklistPdfGenerationEnum::FAILED, ChecklistPdfGenerationEnum::GENERATING] as $status) {
            $entries = new TrackChecklistPdfGenerationAction(context: $context, status: $status)->execute();
        }

        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::GENERATING->value, $entries[0]['status']);
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
        $this->assertSame(ChecklistPdfGenerationEnum::FAILED->value, $entries[0]['status']);
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
        $this->assertSame($taskB->taskListItem->getId(), $entries[0]['task_id']);
        $this->assertNotNull($this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
    }

    public function testEachWriteDispatchesTheBroadcastWithThePostWriteSnapshot(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $context = $this->makeContext('pdf-track-broadcast');

        new TrackChecklistPdfGenerationAction(
            context: $context,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        Event::assertDispatched(
            ChecklistGeneratePdfEvent::class,
            fn (ChecklistGeneratePdfEvent $event): bool => $event->leadUuid === $this->lead->uuid
                && $event->leadId === $this->lead->getId()
                && count($event->entries) === 1
                && $event->entries[0]['status'] === ChecklistPdfGenerationEnum::GENERATING->value
                && $event->broadcastWith()['items'] === $event->entries
        );
    }

    public function testClearEventCarriesTheSurvivingEntryNotARereadOfTheLead(): void
    {
        $taskA = $this->makeContext('pdf-track-snapshot-a');
        $taskB = $this->makeContext('pdf-track-snapshot-b');

        new TrackChecklistPdfGenerationAction(
            context: $taskA,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();
        new TrackChecklistPdfGenerationAction(
            context: $taskB,
            status: ChecklistPdfGenerationEnum::GENERATING
        )->execute();

        Event::fake([ChecklistGeneratePdfEvent::class]);

        new TrackChecklistPdfGenerationAction(context: $taskA, status: null)->execute();

        Event::assertDispatched(
            ChecklistGeneratePdfEvent::class,
            fn (ChecklistGeneratePdfEvent $event): bool => count($event->entries) === 1
                && $event->entries[0]['task_id'] === $taskB->taskListItem->getId()
                && $event->broadcastWith()['items'] === $event->entries
        );
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

        $message = Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->lead->company->getId())
            ->create([
                'message' => [
                    'verb' => $action->slug,
                    'checkListId' => $context->taskListItem->task_list_id,
                ],
            ])
            ->fresh();

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('Action not found');

        ChecklistPdfContext::fromMessage($message, app(Apps::class));
    }

    public function testBroadcastChannelAndEventName(): void
    {
        $event = new ChecklistGeneratePdfEvent($this->lead->getId(), (string) $this->lead->uuid, []);

        $this->assertSame(
            'checklist-generate-pdf-lead-' . $this->lead->uuid,
            $event->broadcastOn()->name
        );
        $this->assertSame('checklist.generate.pdf', $event->broadcastAs());
    }

    private function makeContext(string $slug): ChecklistPdfContext
    {
        $wiring = $this->makeChecklistWiring($this->lead, $slug);

        $engagement = $this->makeChecklistEngagement($this->lead, $wiring['companyAction'], $slug);

        return new ChecklistPdfContext(engagement: $engagement, taskListItem: $wiring['taskListItem']);
    }
}
