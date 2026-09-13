<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;
use Kanvas\ActionEngine\Tasks\Events\ChecklistGeneratePdfEvent;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Activities\GeneratePdfActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;
use Tests\Traits\BuildsChecklistFixtures;

/**
 * Every run points at a template that doesn't exist, so rendering throws deterministically and the
 * failure branch is exercised without depending on wkhtmltopdf or S3 being available.
 */
final class GeneratePdfActivityChecklistTest extends TestCase
{
    use BuildsChecklistFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'action_engine', 'social', 'ecosystem', 'workflow'];

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();

        $this->registerInternalIntegration();
    }

    /**
     * Redis is not rolled back by DatabaseTransactions, so a leftover field would leak into the next
     * test through get()'s Redis-first read.
     */
    protected function tearDown(): void
    {
        $this->lead->del(GeneratePdfActivity::CHECKLIST_PDF_CUSTOM_FIELD);

        parent::tearDown();
    }

    public function testMessageWithoutCheckListIdWritesNothing(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $this->runActivity($this->makeChecklistMessage($this->lead, ['content' => 'no checklist here']));

        $this->assertNull($this->lead->get(GeneratePdfActivity::CHECKLIST_PDF_CUSTOM_FIELD));
        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    public function testNonMessageEntityWritesNothing(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $this->runActivity($this->lead);

        $this->assertNull($this->lead->get(GeneratePdfActivity::CHECKLIST_PDF_CUSTOM_FIELD));
        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    public function testGenerationFailureMarksTheTaskFailed(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        ['message' => $message, 'wiring' => $wiring] = $this->wireChecklist('failure');

        $result = $this->runActivity($message);

        $this->assertSame(
            [[
                'action_id' => $wiring['action']->getId(),
                'company_action_id' => $wiring['companyAction']->getId(),
                'task_id' => $wiring['taskListItem']->getId(),
                'message_id' => $message->getId(),
                'status' => ChecklistPdfGenerationEnum::FAILED->value,
            ]],
            $this->lead->get(GeneratePdfActivity::CHECKLIST_PDF_CUSTOM_FIELD)
        );
        // executeIntegration swallows the rethrow and returns an error array.
        $this->assertArrayHasKey('trace', $result);
        Event::assertDispatchedTimes(ChecklistGeneratePdfEvent::class, 2);
    }

    public function testFailureKeepsAnotherTaskOnTheSameLead(): void
    {
        $otherTask = [
            'action_id' => 1,
            'company_action_id' => 1,
            'task_id' => PHP_INT_MAX,
            'message_id' => 1,
            'status' => ChecklistPdfGenerationEnum::GENERATING->value,
        ];

        $this->lead->set(GeneratePdfActivity::CHECKLIST_PDF_CUSTOM_FIELD, [$otherTask]);

        ['message' => $message, 'wiring' => $wiring] = $this->wireChecklist('concurrent');

        $this->runActivity($message);

        $entries = $this->lead->get(GeneratePdfActivity::CHECKLIST_PDF_CUSTOM_FIELD);

        $this->assertCount(2, $entries);
        $this->assertSame($otherTask, $entries[0]);
        $this->assertSame($wiring['taskListItem']->getId(), $entries[1]['task_id']);
    }

    public function testBroadcastChannelAndEventName(): void
    {
        $event = new ChecklistGeneratePdfEvent((string) $this->lead->uuid);

        $this->assertSame('checklist-generate-pdf-lead-' . $this->lead->uuid, $event->broadcastOn()->name);
        $this->assertSame('checklist.generate.pdf', $event->broadcastAs());
        $this->assertSame([], $event->broadcastWith());
    }

    private function runActivity(Message|Lead $entity): array
    {
        $activity = new GeneratePdfActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        return $activity->execute($entity, app(Apps::class), [
            'template_pdf' => 'checklist-pdf-missing-template-' . uniqid(),
            'pdf_file_name' => 'checklist-tracking.pdf',
        ]);
    }

    private function wireChecklist(string $suffix): array
    {
        $slug = 'checklist-pdf-activity-' . $suffix . '-' . uniqid();

        $wiring = $this->makeChecklistWiring($this->lead, $slug);

        $message = $this->makeChecklistMessage($this->lead, [
            'verb' => $slug,
            'checkListId' => $wiring['taskList']->getId(),
        ]);

        $this->makeChecklistEngagement(
            $this->lead,
            $wiring['companyAction'],
            $slug,
            $message->getId()
        );

        return [
            'message' => $message,
            'wiring' => $wiring,
        ];
    }

    private function registerInternalIntegration(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $region = Regions::getDefault($company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        IntegrationsCompany::firstOrCreate(
            [
                'companies_id' => $company->getId(),
                'integrations_id' => Integrations::getByName(IntegrationsEnum::INTERNAL->value)->getId(),
                'region_id' => $region->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
                'is_active' => 1,
            ]
        );
    }
}
