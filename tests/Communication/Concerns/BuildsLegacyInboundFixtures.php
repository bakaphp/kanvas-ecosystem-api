<?php

declare(strict_types=1);

namespace Tests\Communication\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

/**
 * What a company had before its provider moved onto connections: a responder rule naming an agent
 * and a receiver pointing at the provider's own webhook job. Both are referenced by class name only,
 * because the classes themselves are gone.
 */
trait BuildsLegacyInboundFixtures
{
    abstract protected function currentApp(): Apps;

    abstract protected function currentCompany(): Companies;

    protected function legacyResponderRule(string $activity, Agent $agent): Rule
    {
        $systemModule = SystemModulesRepository::getByModelName(Channel::class, $this->currentApp());
        $step = RuleWorkflowAction::query()->firstOrCreate(
            [
                'system_modules_id' => $systemModule->getId(),
                'actions_id' => $this->legacyAction($activity)->getId(),
            ],
            ['is_deleted' => 0]
        );

        $rule = Rule::query()->create([
            'name' => 'Legacy auto-reply',
            'rules_types_id' => RuleType::getByName(WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value)->getId(),
            'systems_modules_id' => $systemModule->getId(),
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'params' => ['agent_id' => $agent->getId()],
            'pattern' => '1',
            'is_async' => 1,
            'is_deleted' => 0,
        ]);

        RuleAction::query()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $step->getId(),
            'weight' => 0,
            'is_deleted' => 0,
        ]);

        return $rule;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    protected function legacyReceiver(string $job, array $configuration = []): ReceiverWebhook
    {
        return ReceiverWebhook::factory()
            ->app($this->currentApp()->getId())
            ->user(auth()->user()->getId())
            ->company($this->currentCompany()->getId())
            ->create([
                'action_id' => $this->legacyAction($job)->getId(),
                'configuration' => $configuration,
                'is_active' => true,
            ]);
    }

    /**
     * Rules other suites left on the shared company would be retired by the action under test too.
     */
    protected function forgetLegacyResponderRules(string $activity): void
    {
        Rule::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany())
            ->whereHas(
                'workflowActivities.activity.action',
                fn (Builder $action) => $action->where('model_name', $activity)
            )
            ->update(['is_deleted' => 1]);
    }

    private function legacyAction(string $class): Action
    {
        return Action::query()->firstOrCreate(['model_name' => $class], ['name' => class_basename($class)]);
    }
}
