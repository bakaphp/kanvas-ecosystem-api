<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * @todo replace with factories once the ActionEngine models have them
 */
trait BuildsChecklistFixtures
{
    /**
     * @return array{action: Action, companyAction: CompanyAction, taskList: TaskList, taskListItem: TaskListItem}
     */
    protected function makeChecklistWiring(Lead $lead, string $slug): array
    {
        $app = app(Apps::class);
        $company = $lead->company;

        $action = Action::firstOrCreate([
            'companies_id' => 0,
            'apps_id' => 0,
            'users_id' => 0,
            'pipelines_id' => 1,
            'slug' => $slug,
            'name' => 'Checklist Fixture ' . $slug,
            'is_active' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
        ]);

        $companyAction = CompanyAction::firstOrCreate([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $action->getId(),
            'pipelines_id' => 1,
            'name' => 'Checklist Fixture ' . $slug,
            'is_active' => 1,
            'is_published' => 1,
            'weight' => 1,
            'is_deleted' => 0,
        ]);

        $taskList = TaskList::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Checklist Fixture List',
        ]);

        $taskListItem = TaskListItem::firstOrCreate([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Checklist Fixture Item ' . $slug,
            'config' => [],
            'weight' => 1,
        ]);

        return [
            'action' => $action,
            'companyAction' => $companyAction,
            'taskList' => $taskList,
            'taskListItem' => $taskListItem,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function makeChecklistMessage(Lead $lead, array $payload): Message
    {
        $app = app(Apps::class);
        $company = $lead->company;

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
        // so without the module link the fixture blows up far from whatever is under test.
        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $message->fresh();
    }

    protected function makeChecklistEngagement(
        Lead $lead,
        CompanyAction $companyAction,
        string $slug,
        int $messageId = 0
    ): Engagement {
        return Engagement::firstOrCreate([
            'companies_id' => $lead->company->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'users_id' => auth()->user()->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $lead->people_id,
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $messageId,
            'slug' => $slug,
            'entity_uuid' => Str::uuid()->toString(),
            'pipelines_stages_id' => 0,
        ]);
    }
}
