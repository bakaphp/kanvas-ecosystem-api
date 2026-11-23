<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;

/**
 * Switches off what served an account before this domain did: the receivers on a provider's old
 * webhook jobs and the rule steps that ran its responder. Which classes those were is the
 * provider's knowledge and is passed in by name, because the classes themselves are deleted. A rule
 * a person built around the responder keeps whatever else it runs.
 */
final class RetireLegacyInboundAction
{
    /**
     * @param list<string> $receiverJobs class names the old receivers' `actions` rows still carry
     * @param list<string> $responderActivities class names of the old responder activities
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $receiverJobs,
        private readonly array $responderActivities
    ) {
    }

    /**
     * @param (callable(Rule): ?string)|null $onRule sees each responder rule before it is retired
     *                                               (to carry its agent over); a string it returns
     *                                               goes on the report
     * @param (callable(ReceiverWebhook): bool)|null $onlyReceivers narrows the receivers to the
     *                                                              ones that served this account
     *
     * @return list<string> what was retired, for the setup report
     */
    public function execute(?callable $onRule = null, ?callable $onlyReceivers = null): array
    {
        $retired = [];

        foreach ($this->responderRules() as $rule) {
            $note = $onRule !== null ? $onRule($rule) : null;

            if ($note !== null) {
                $retired[] = $note;
            }

            $this->responderSteps($rule)->update(['is_deleted' => 1]);

            if (! $rule->workflowActivities()->where('is_deleted', 0)->exists()) {
                $rule->forceFill(['is_deleted' => 1])->saveOrFail();
            }

            $retired[] = 'rule ' . $rule->name;
        }

        $receivers = ReceiverWebhook::query()
            ->fromApp((int) $this->connection->apps_id)
            ->fromCompany((int) $this->connection->companies_id)
            ->notDeleted()
            ->where('is_active', true)
            ->whereHas('action', fn (Builder $action) => $action->whereIn('model_name', $this->receiverJobs))
            ->get();

        foreach ($receivers as $receiver) {
            if ($onlyReceivers !== null && ! $onlyReceivers($receiver)) {
                continue;
            }

            $receiver->forceFill(['is_active' => false])->saveOrFail();
            $retired[] = 'receiver ' . $receiver->uuid;
        }

        return $retired;
    }

    /**
     * @return iterable<Rule>
     */
    private function responderRules(): iterable
    {
        return Rule::query()
            ->fromApp((int) $this->connection->apps_id)
            ->fromCompany((int) $this->connection->companies_id)
            ->notDeleted()
            ->whereHas('workflowActivities', fn (Builder $step) => $this->onlyResponder($step))
            ->get();
    }

    private function responderSteps(Rule $rule): Builder
    {
        return $this->onlyResponder(RuleAction::query()->where('rules_id', $rule->getId()));
    }

    private function onlyResponder(Builder $steps): Builder
    {
        return $steps
            ->where('is_deleted', 0)
            ->whereHas(
                'activity.action',
                fn (Builder $action) => $action->whereIn('model_name', $this->responderActivities)
            );
    }
}
