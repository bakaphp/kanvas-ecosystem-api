<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class ProcessMessageTaskUpdatesActionTest extends TestCase
{
    private TaskList $taskList;
    private CompanyAction $companyAction;

    /**
     * Regression: a get-docs submission carrying a single document type used to
     * complete every get-docs task item under the company action, because
     * applyDataConditions only narrowed the query for hardcoded ids 3 and 10.
     */
    public function testGetDocsCompletesOnlyTheSubmittedDocumentType(): void
    {
        [$company, $lead] = $this->bootChecklist();

        $bankStatements = $this->createDocItem('Bank Statements', 9);
        $tradeIn = $this->createDocItem('Trade In', 15);
        $showroom = $this->createDocItem('Showroom', 2);

        new ProcessMessageTaskUpdatesAction(
            $this->getDocsMessage($lead, [9]),
            $lead,
            auth()->user(),
        )->execute();

        $this->assertItemStatus($bankStatements, $lead, 'completed');
        $this->assertItemUntouched($tradeIn, $lead);
        $this->assertItemUntouched($showroom, $lead);

        $company->del('default_checklist_id');
    }

    public function testGetDocsCompletesEverySubmittedDocumentType(): void
    {
        [$company, $lead] = $this->bootChecklist();

        $bankStatements = $this->createDocItem('Bank Statements', 9);
        $tradeIn = $this->createDocItem('Trade In', 15);
        $showroom = $this->createDocItem('Showroom', 2);

        new ProcessMessageTaskUpdatesAction(
            $this->getDocsMessage($lead, [9, 15]),
            $lead,
            auth()->user(),
        )->execute();

        $this->assertItemStatus($bankStatements, $lead, 'completed');
        $this->assertItemStatus($tradeIn, $lead, 'completed');
        $this->assertItemUntouched($showroom, $lead);

        $company->del('default_checklist_id');
    }

    public function testGetDocsWithNoDocumentsCompletesNothing(): void
    {
        [$company, $lead] = $this->bootChecklist();

        $bankStatements = $this->createDocItem('Bank Statements', 9);

        $result = new ProcessMessageTaskUpdatesAction(
            $this->getDocsMessage($lead, []),
            $lead,
            auth()->user(),
        )->execute();

        $this->assertFalse($result['success']);
        $this->assertItemUntouched($bankStatements, $lead);

        $company->del('default_checklist_id');
    }

    /**
     * Regression: a co-buyer's ID-verification submission used to complete the main
     * buyer's task because both tasks share the same company action and the query
     * ignored who was verified. A co-buyer item is flagged with a cobuyer-picker step;
     * the message's contact_uuid resolves to a person other than the lead's main people.
     */
    public function testCoBuyerVerificationCompletesOnlyTheCoBuyerItem(): void
    {
        [$company, $lead] = $this->bootChecklist('id-verification', 'ID Verification');

        $mainBuyer = $this->createRoleItem('Verify Mainbuyer DL', isCoBuyer: false);
        $coBuyer = $this->createRoleItem('Verify Cobuyer DL', isCoBuyer: true);

        new ProcessMessageTaskUpdatesAction(
            $this->idVerificationMessage($lead, fake()->uuid()),
            $lead,
            auth()->user(),
        )->execute();

        $this->assertItemStatus($coBuyer, $lead, 'completed');
        $this->assertItemUntouched($mainBuyer, $lead);

        $company->del('default_checklist_id');
    }

    public function testMainBuyerVerificationCompletesOnlyTheMainBuyerItem(): void
    {
        [$company, $lead] = $this->bootChecklist('id-verification', 'ID Verification');

        $mainBuyer = $this->createRoleItem('Verify Mainbuyer DL', isCoBuyer: false);
        $coBuyer = $this->createRoleItem('Verify Cobuyer DL', isCoBuyer: true);

        new ProcessMessageTaskUpdatesAction(
            $this->idVerificationMessage($lead, $lead->people->uuid),
            $lead,
            auth()->user(),
        )->execute();

        $this->assertItemStatus($mainBuyer, $lead, 'completed');
        $this->assertItemUntouched($coBuyer, $lead);

        $company->del('default_checklist_id');
    }

    /**
     * Builds a company action + checklist for the given action slug on the auth user's
     * company and pins it as the default checklist so the message processor resolves to
     * exactly these items.
     *
     * @return array{0: Companies, 1: Lead}
     */
    private function bootChecklist(string $actionSlug = 'get-docs', string $actionName = 'Get Docs'): array
    {
        // Creating the Engagement fires EngagementStatusChangedEvent, which renders
        // a push-notification template not seeded in CI. The test asserts on task
        // status, not notifications, so faking them keeps it environment-independent.
        Notification::fake();

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Get Docs Pipeline ' . fake()->uuid(),
            'slug' => 'get-docs-pipeline-' . fake()->uuid(),
            'weight' => 0,
        ]);

        foreach (['sent' => 1, 'submitted' => 2] as $slug => $weight) {
            PipelineStage::create([
                'pipelines_id' => $pipeline->getId(),
                'name' => ucfirst($slug),
                'slug' => $slug,
                'weight' => $weight,
            ]);
        }

        $action = Action::firstOrCreate(
            ['slug' => $actionSlug],
            [
                'companies_id' => 0,
                'apps_id' => 0,
                'users_id' => 0,
                'pipelines_id' => $pipeline->getId(),
                'name' => $actionName,
                'is_active' => 1,
                'is_published' => 1,
                'is_deleted' => 0,
            ],
        );

        $this->companyAction = CompanyAction::create([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'actions_id' => $action->getId(),
            'name' => $actionName,
            'pipelines_id' => $pipeline->getId(),
            'is_active' => 1,
            'is_published' => 1,
            'weight' => 1,
            'is_deleted' => 0,
        ]);

        $this->taskList = TaskList::create([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'Get Docs Checklist ' . fake()->uuid(),
            'config' => [],
        ]);

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $company->set('default_checklist_id', $this->taskList->getId());

        return [$company, $lead];
    }

    private function createDocItem(string $name, int $documentTypeId): TaskListItem
    {
        return TaskListItem::create([
            'task_list_id' => $this->taskList->getId(),
            'companies_action_id' => $this->companyAction->getId(),
            'name' => $name,
            'config' => ['id' => $documentTypeId],
            'weight' => 1,
        ]);
    }

    private function createRoleItem(string $name, bool $isCoBuyer): TaskListItem
    {
        $steps = [['data' => ['remote-only' => ['chrome']], 'type' => 'open-customer-action']];

        if ($isCoBuyer) {
            array_unshift($steps, ['data' => [], 'type' => 'cobuyer-picker']);
        }

        return TaskListItem::create([
            'task_list_id' => $this->taskList->getId(),
            'companies_action_id' => $this->companyAction->getId(),
            'name' => $name,
            'config' => ['steps' => $steps, 'checkout' => ['hide' => true]],
            'weight' => 1,
        ]);
    }

    private function idVerificationMessage(Lead $lead, string $contactUuid): Message
    {
        $message = Message::factory()->create([
            'message' => [
                'verb' => 'id-verification',
                'status' => 'submitted',
                'data' => [],
                'contact_uuid' => $contactUuid,
            ],
        ]);

        $message->addEntity($lead);

        return $message;
    }

    /**
     * @param array<int, int> $documentTypeIds
     */
    private function getDocsMessage(Lead $lead, array $documentTypeIds): Message
    {
        $data = [];
        foreach ($documentTypeIds as $id) {
            $data[(string) $id] = [
                'type' => ['id' => $id, 'name' => 'Document ' . $id],
                'files' => [],
            ];
        }

        $message = Message::factory()->create([
            'message' => [
                'verb' => 'get-docs',
                'status' => 'submitted',
                'data' => $data,
            ],
        ]);

        // Link the message to the lead so Engagement observers can resolve entity().
        $message->addEntity($lead);

        return $message;
    }

    private function assertItemStatus(TaskListItem $item, Lead $lead, string $expectedStatus): void
    {
        $engagementItem = TaskEngagementItem::where('task_list_item_id', $item->getId())
            ->where('lead_id', $lead->getId())
            ->first();

        $this->assertNotNull($engagementItem, "Expected a TaskEngagementItem for {$item->name}");
        $this->assertEquals($expectedStatus, $engagementItem->status);
    }

    private function assertItemUntouched(TaskListItem $item, Lead $lead): void
    {
        $engagementItem = TaskEngagementItem::where('task_list_item_id', $item->getId())
            ->where('lead_id', $lead->getId())
            ->first();

        $this->assertNull(
            $engagementItem,
            "TaskListItem '{$item->name}' was not submitted and must not have been completed",
        );
    }
}
