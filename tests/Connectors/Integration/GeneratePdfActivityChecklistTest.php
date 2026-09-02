<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Kanvas\ActionEngine\Tasks\Actions\TrackChecklistPdfGenerationAction;
use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;
use Kanvas\ActionEngine\Tasks\Events\ChecklistGeneratePdfEvent;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Activities\GeneratePdfActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Templates\Models\Templates;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;
use Tests\Traits\BuildsChecklistFixtures;

final class GeneratePdfActivityChecklistTest extends TestCase
{
    use BuildsChecklistFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'action_engine', 'social', 'ecosystem', 'workflow'];

    private Lead $lead;
    private string $templateName;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $this->templateName = 'checklist-pdf-tracking-' . uniqid();

        Templates::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => $this->templateName,
            'template' => '<p>Checklist PDF</p>',
            'parent_template_id' => 0,
            'is_system' => false,
        ]);

        $this->registerInternalIntegration();
    }

    /**
     * Redis survives DatabaseTransactions, so a leftover marker would leak into the next test.
     */
    protected function tearDown(): void
    {
        $this->lead->del(TrackChecklistPdfGenerationAction::CUSTOM_FIELD);

        parent::tearDown();
    }

    public function testMessageWithoutCheckListIdWritesNoMarker(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $this->runActivity($this->createMessage(['content' => 'no checklist here']));

        $this->assertNull($this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    public function testNonMessageEntityWritesNoMarker(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        $this->runActivity($this->lead);

        $this->assertNull($this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD));
        Event::assertNotDispatched(ChecklistGeneratePdfEvent::class);
    }

    public function testGenerationFailureLeavesTheMarkerFailed(): void
    {
        Event::fake([ChecklistGeneratePdfEvent::class]);

        ['message' => $message, 'taskListItem' => $taskListItem] = $this->wireChecklist('failure');

        $result = $this->runActivity($message);

        $entries = $this->lead->get(TrackChecklistPdfGenerationAction::CUSTOM_FIELD);

        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);
        $this->assertSame(ChecklistPdfGenerationEnum::FAILED->value, $entries[0]['status']);
        $this->assertSame($taskListItem->getId(), $entries[0]['task_id']);
        // executeIntegration swallowed the rethrow, so the activity returns an error array rather
        // than propagating — see the retries section of the plan.
        $this->assertArrayHasKey('trace', $result);

        $statuses = [];
        Event::assertDispatched(
            ChecklistGeneratePdfEvent::class,
            function (ChecklistGeneratePdfEvent $event) use (&$statuses): bool {
                $statuses[] = $event->entries[0]['status'];

                return true;
            }
        );

        $this->assertSame(
            [ChecklistPdfGenerationEnum::GENERATING->value, ChecklistPdfGenerationEnum::FAILED->value],
            $statuses
        );
    }

    /**
     * The PDF is never uploaded in this environment (no S3 credentials on the app), so every run
     * ends in the failure branch. That still exercises the tracking, which is what these cases are
     * about; the success branch is covered by ChecklistGeneratePdfTrackingTest.
     */
    private function runActivity(mixed $entity): array
    {
        $activity = new GeneratePdfActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        return $activity->execute($entity, app(Apps::class), [
            'template_pdf' => $this->templateName,
            'pdf_file_name' => 'checklist-tracking.pdf',
        ]);
    }

    /**
     * @return array{message: Message, taskListItem: TaskListItem}
     */
    private function wireChecklist(string $suffix): array
    {
        $slug = 'checklist-pdf-activity-' . $suffix . '-' . uniqid();

        $wiring = $this->makeChecklistWiring($this->lead, $slug);

        $message = $this->createMessage([
            'verb' => $slug,
            'checkListId' => $wiring['taskList']->getId(),
        ]);

        $this->makeChecklistEngagement($this->lead, $wiring['companyAction'], $slug, $message->getId());

        return [
            'message' => $message,
            'taskListItem' => $wiring['taskListItem'],
        ];
    }

    private function createMessage(array $payload): Message
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'checklist-pdf'],
            ['name' => 'Checklist Pdf']
        );

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => $payload,
                'is_public' => 1,
                'is_locked' => 0,
            ]);

        // The Engagement observer broadcasts a status-changed event that reads the message's entity,
        // so the module link has to exist or the fixture blows up far from what we are testing.
        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $this->lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $message->fresh();
    }

    private function registerInternalIntegration(): IntegrationsCompany
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

        return IntegrationsCompany::firstOrCreate(
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
