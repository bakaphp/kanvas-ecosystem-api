<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesWorkflowCatalogForTool;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * What this company already automates — needed before changing anything, and the only way to see that
 * a rule is bound to a legacy entity.
 *
 * `will_run` is the field that matters. Rules are matched by the triggering record's concrete class,
 * so a rule pointed at `Gewaer\Models\Messages` never matches a real `Kanvas\…\Message` and simply
 * never fires. It looks completely healthy in the database. Nothing errors, nothing logs, and the
 * symptom is an absence — which is indistinguishable from "no message qualified".
 */
#[AgentTool(name: 'List Company Workflows', category: 'workflow')]
class ListCompanyWorkflowsTool extends Tool implements HasRunKey
{
    use ResolvesWorkflowCatalogForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_company_workflows',
            description: 'Lists the automation this company already has, with what each one watches, the '
                . 'conditions it requires, the settings it passes and whether it can actually run. Call this '
                . 'before creating a workflow — if one already does the job, change it with '
                . 'update_company_workflow instead of adding a second that duplicates the work.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Optional term to filter by workflow name, e.g. "wordpress", "lead".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $search = null): array
    {
        if (! $this->hasTenantContext()) {
            return [
                'status' => 'error',
                'message' => 'This tool has no company context, so it cannot list workflows.',
            ];
        }

        $query = Rule::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('is_deleted', 0)
            ->orderByDesc('id');

        $search = trim((string) $search);

        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $workflows = $query->limit(25)->get()
            ->map(fn (Rule $rule): array => $this->describe($rule))
            ->all();

        $broken = array_values(array_filter($workflows, fn (array $w): bool => ! $w['will_run']));

        $result = [
            'status' => 'success',
            'workflows' => $workflows,
        ];

        if ($broken !== []) {
            $result['warning'] = 'Some of these can never run — they watch a record type that no longer '
                . 'exists under the current namespaces. Recreate them against the Kanvas entity and delete '
                . 'the old one; editing the entity is not possible.';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Rule $rule): array
    {
        $module = $rule->systemModule;
        $entity = (string) ($module->model_name ?? '');

        return [
            'workflow_id' => $rule->getId(),
            'name' => $rule->name,
            'entity' => $module->name ?? 'unknown',
            'trigger' => $rule->type->name ?? 'unknown',
            'conditions' => $this->conditionsOf($rule),
            'params' => is_array($rule->params) ? $rule->params : [],
            'actions' => array_map(fn (Action $action): string => (string) $action->name, $this->actionsOfRule($rule)),
            'runs_in_background' => (bool) $rule->is_async,
            // Rules are matched on the triggering record's concrete class, so a legacy entity means
            // this rule is never even considered.
            'will_run' => str_starts_with($entity, 'Kanvas\\'),
        ];
    }

    /**
     * @return list<string>
     */
    private function conditionsOf(Rule $rule): array
    {
        return $rule->getRulesConditions()
            ->where('is_deleted', 0)
            ->get()
            ->map(fn ($condition): string => trim(sprintf(
                '%s %s %s',
                $condition->attribute_name,
                $condition->operator,
                (string) $condition->value
            )))
            ->all();
    }
}
