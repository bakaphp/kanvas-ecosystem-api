<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Support\TransientEntity;
use RuntimeException;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncPullRules;
use Tests\Workflow\Support\PlainRecordingActivity;
use Throwable;
use Workflow\WorkflowStub;

/**
 * Rows are the budget. A rule that does not match must not leave a `workflows` row, a plain
 * activity (no executeIntegration) is recorded by the engine only when it fails or when its rule
 * opts in, and the row carries the workflow, the rule and the activity so the UI can name the run.
 */
final class ActivityRunRecordingTest extends TestCase
{
    use BuildsSyncPullRules;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm'];

    public function testANonMatchingRuleStartsNoWorkflowAtAll(): void
    {
        $app = app(Apps::class);
        $lead = $this->createLead();
        $rule = $this->createSyncPullRule($app, PlainRecordingActivity::class, 'Test Plain Recording');

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'interaction',
            'operator' => '==',
            'value' => 'likes',
        ]);

        $before = StoredWorkflow::count();

        $result = new ProcessWorkflowEventAction($app, $lead)->execute(WorkflowEnum::PULL->value, ['interaction' => 'like']);

        $this->assertNull($result);
        $this->assertSame($before, StoredWorkflow::count(), 'a rule that does not match must cost no workflows row');
    }

    public function testAThrowingActivityIsAlwaysRecordedAsFailed(): void
    {
        $app = app(Apps::class);
        $lead = $this->createLead();
        $rule = $this->createSyncPullRule($app, PlainRecordingActivity::class, 'Test Plain Recording');

        try {
            new ProcessWorkflowEventAction($app, $lead)->execute(WorkflowEnum::PULL->value, ['boom' => true]);
        } catch (Throwable) {
        }

        $row = $this->historyFor($rule);

        $this->assertNotNull($row, 'a failed run must leave a history row even without executeIntegration');
        $this->assertSame(StatusEnum::FAILED->value, $row->status->slug);
        $this->assertNotEmpty($row->exception);
        $this->assertNotNull($row->workflow_id);
        $this->assertSame($this->actionId(), (int) $row->actions_id);
        $this->assertNull($row->integrations_company_id);
    }

    public function testASoftFailureIsRecordedAsFailedWithTheResponse(): void
    {
        $app = app(Apps::class);
        $lead = $this->createLead();
        $rule = $this->createSyncPullRule($app, PlainRecordingActivity::class, 'Test Plain Recording');

        new ProcessWorkflowEventAction($app, $lead)->execute(WorkflowEnum::PULL->value, ['soft_fail' => true]);

        $row = $this->historyFor($rule);

        $this->assertNotNull($row);
        $this->assertSame(StatusEnum::FAILED->value, $row->status->slug);
        $this->assertSame('soft fail', $row->response['message']);
    }

    public function testASuccessfulRunIsNotRecordedUnlessTheRuleAsksForIt(): void
    {
        $app = app(Apps::class);
        $lead = $this->createLead();
        $quiet = $this->createSyncPullRule($app, PlainRecordingActivity::class, 'Test Plain Recording');

        $result = new ProcessWorkflowEventAction($app, $lead)->execute(WorkflowEnum::PULL->value, []);

        $this->assertSame(PlainRecordingActivity::class, $result->output()[0]['activity']);
        $this->assertNull($this->historyFor($quiet), 'a quiet success must not add a row');

        $quiet->is_deleted = 1;
        $quiet->saveQuietly();
        $verbose = $this->createSyncPullRule($app, PlainRecordingActivity::class, 'Test Plain Recording', ['log_success' => true]);

        new ProcessWorkflowEventAction($app, $lead)->execute(WorkflowEnum::PULL->value, []);

        $row = $this->historyFor($verbose);
        $this->assertNotNull($row, 'log_success on the rule records successful runs too');
        $this->assertSame(StatusEnum::CONNECTED->value, $row->status->slug);
        $this->assertSame($lead->getId(), (int) $row->entity_id);
        $this->assertSame('PlainRecordingActivity', $row->workflow->getActivityName());
    }

    public function testAQueuedActivityRecordsOneRowOnItsFinalFailureNotOnEveryRetry(): void
    {
        Bus::fake();
        $app = app(Apps::class);
        $lead = $this->createLead();
        $rule = $this->createSyncPullRule($app, PlainRecordingActivity::class, 'Test Plain Recording');
        $storedWorkflow = WorkflowStub::make(DynamicRuleWorkflow::class);
        $activity = new PlainRecordingActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::findOrFail($storedWorkflow->id()),
            $lead,
            $app,
            ['boom' => true, 'rule' => $rule]
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $activity->handle();
            } catch (Throwable) {
            }
        }

        $this->assertNull($this->historyFor($rule), 'a retried attempt must not add a row');

        $activity->failed(new RuntimeException('boom'));

        $this->assertSame(1, EntityIntegrationHistory::query()->where('rules_id', $rule->getId())->count());
        $this->assertSame(StatusEnum::FAILED->value, $this->historyFor($rule)->status->slug);
    }

    public function testAQueuedActivityRebuildsAnUnsavedSubjectInsteadOfReloadingIt(): void
    {
        $app = app(Apps::class);
        $stub = new Lead();
        $stub->forceFill(['id' => 0, 'apps_id' => $app->getId(), 'companies_id' => auth()->user()->getCurrentCompany()->getId()]);
        $storedWorkflow = WorkflowStub::make(DynamicRuleWorkflow::class);

        $activity = new PlainRecordingActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::findOrFail($storedWorkflow->id()),
            TransientEntity::fromModel($stub),
            $app,
            []
        );

        $this->assertSame(0, $activity->handle()['entity_id']);
    }

    private function historyFor(Rule $rule): ?EntityIntegrationHistory
    {
        return EntityIntegrationHistory::query()->where('rules_id', $rule->getId())->orderByDesc('id')->first();
    }

    private function actionId(): int
    {
        return Action::query()->where('model_name', PlainRecordingActivity::class)->firstOrFail()->getId();
    }
}
