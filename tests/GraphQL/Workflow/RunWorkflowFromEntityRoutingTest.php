<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncPullRules;
use Tests\Workflow\Support\RecordingPullActivity;

/**
 * Rules route a pull/push; the SalesAssist match answers only a tenant with no rule.
 *
 * Serial: `can-run-sync-workflow` is an app setting, which lives in Redis shared by every
 * paratest process.
 */
#[Group('serial')]
class RunWorkflowFromEntityRoutingTest extends TestCase
{
    use BuildsSyncPullRules;
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'workflow', 'crm'];

    public function testAPullRuleOnTheAppWinsOverTheSalesAssistFallback(): void
    {
        $app = app(Apps::class);
        $previous = $app->get('can-run-sync-workflow');
        $app->set('can-run-sync-workflow', 1);

        try {
            $this->setUpInternalIntegration();
            $rule = $this->createSyncPullRule($app, RecordingPullActivity::class, 'Test Recording Pull');

            $result = $this->pullLead('2896936000576898002');

            $this->assertSame(RecordingPullActivity::class, $result['activity']);
            $this->assertSame(0, $result['entity_id'], 'an entity_id of "0" pulls through a stub lead');
            $this->assertSame('2896936000576898002', $result['pulled_id']);
            $this->assertNotNull($result['workflow_id'], 'a sync run must hand the activity its real stored workflow');

            $history = EntityIntegrationHistory::query()
                ->where('workflow_id', $result['workflow_id'])
                ->firstOrFail();

            $this->assertSame($rule->getId(), (int) $history->rules_id);
            $this->assertSame(
                Action::query()->where('model_name', RecordingPullActivity::class)->firstOrFail()->getId(),
                (int) $history->actions_id,
                'the history row names the activity that ran, so the UI can show it without workflow_logs'
            );
            $this->assertSame('RecordingPullActivity', $history->workflow->getActivityName());
        } finally {
            $this->restoreSetting($app, 'can-run-sync-workflow', $previous);
        }
    }

    public function testWithoutARuleThePullStillReachesTheSalesAssistFallback(): void
    {
        $app = app(Apps::class);
        $previous = $app->get('can-run-sync-workflow');
        $app->set('can-run-sync-workflow', 1);

        try {
            $this->assertFalse(
                $this->leadHasPullRule($app),
                'this test needs an app with no pull rule on leads; one was found'
            );

            Log::spy();

            // Whatever SalesAssist answers for this company (it probes the dealer CRM credentials the
            // company happens to carry) is not under test; that the call reached it is.
            $this->graphQL($this->mutation(), $this->variables('123'), [], $this->getAppKeyHeader());

            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'falling back to the SalesAssist activity')
                    && $context['activity'] === PullLeadActivity::class)
                ->once();
        } finally {
            $this->restoreSetting($app, 'can-run-sync-workflow', $previous);
        }
    }

    public function testIncludeRunReturnsTheWorkflowAndHistoryIdsAroundTheOutput(): void
    {
        $app = app(Apps::class);
        $previous = $app->get('can-run-sync-workflow');
        $app->set('can-run-sync-workflow', 1);

        try {
            $this->setUpInternalIntegration();
            $this->createSyncPullRule($app, RecordingPullActivity::class, 'Test Recording Pull');

            $variables = $this->variables('555');
            $variables['input']['include_run'] = true;

            $response = $this->graphQL($this->mutation(), $variables, [], $this->getAppKeyHeader());
            $response->assertSuccessful()->assertGraphQLErrorFree();
            $run = $response->json('data.runWorkflowFromEntity');

            $this->assertTrue($run['success']);
            $this->assertSame($run['output']['workflow_id'], $run['workflow_id']);
            $this->assertSame(RecordingPullActivity::class, $run['output']['activity']);
            $this->assertSame(
                $run['history_id'],
                EntityIntegrationHistory::query()->where('workflow_id', $run['workflow_id'])->value('id')
            );
        } finally {
            $this->restoreSetting($app, 'can-run-sync-workflow', $previous);
        }
    }

    public function testActivityClassMustBeACataloguedWorkflowActivity(): void
    {
        $response = $this->graphQL($this->mutation(), [
            'input' => [
                'entity_namespace' => 'lead',
                'entity_id' => '0',
                'action' => WorkflowEnum::PULL->value,
                'activity_class' => Lead::class,
            ],
        ], [], $this->getAppKeyHeader());

        $this->assertStringContainsString(
            'is not a workflow activity',
            (string) $response->json('errors.0.message') . (string) $response->json('errors.0.extensions.debugMessage')
        );
    }

    public function testARuleThatExistsButDoesNotMatchStillLeavesTheFallbackInCharge(): void
    {
        $app = app(Apps::class);
        $previous = $app->get('can-run-sync-workflow');
        $app->set('can-run-sync-workflow', 1);

        try {
            $rule = $this->createSyncPullRule($app, RecordingPullActivity::class, 'Test Recording Pull');
            RuleCondition::factory()->create([
                'rules_id' => $rule->getId(),
                'attribute_name' => 'interaction',
                'operator' => '==',
                'value' => 'never-sent',
            ]);

            Log::spy();

            $this->graphQL($this->mutation(), $this->variables('123'), [], $this->getAppKeyHeader());

            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message) => str_contains($message, 'falling back to the SalesAssist activity'))
                ->once();
        } finally {
            $this->restoreSetting($app, 'can-run-sync-workflow', $previous);
        }
    }

    private function pullLead(string $externalId): array
    {
        $response = $this->graphQL($this->mutation(), $this->variables($externalId), [], $this->getAppKeyHeader());

        $response->assertSuccessful()->assertGraphQLErrorFree();

        return $response->json('data.runWorkflowFromEntity');
    }

    private function mutation(): string
    {
        return '
            mutation($input: runWorkflowEntityInput!) {
                runWorkflowFromEntity(input: $input)
            }
        ';
    }

    private function variables(string $externalId): array
    {
        return [
            'input' => [
                'entity_namespace' => 'lead',
                'entity_id' => '0',
                'action' => WorkflowEnum::PULL->value,
                'params' => ['entity_id' => $externalId, 'sync' => true],
            ],
        ];
    }

    private function leadHasPullRule(Apps $app): bool
    {
        return Rule::query()
            ->where('rules_types_id', RuleType::getByName(WorkflowEnum::PULL->value)->getId())
            ->where('systems_modules_id', SystemModulesRepository::getByModelName(Lead::class, $app)->getId())
            ->whereIn('apps_id', [0, $app->getId()])
            ->where('is_deleted', 0)
            ->exists();
    }

    /**
     * The mutation is app-key guarded, so inside the request the acting user is the key's owner,
     * not the test's authenticated user — the integration has to exist for that company.
     */
    private function setUpInternalIntegration(): void
    {
        $app = app(Apps::class);
        $user = $app->keys()->first()->user;

        $this->setIntegration(
            $app,
            IntegrationsEnum::INTERNAL,
            'Kanvas\\Connectors\\Internal\\Handlers\\InternalHandler',
            $user->getCurrentCompany(),
            $user
        );
    }

    private function restoreSetting(Apps $app, string $key, mixed $previous): void
    {
        if ($previous === null) {
            $app->del($key);

            return;
        }

        $app->set($key, $previous);
    }

    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ];
    }
}
