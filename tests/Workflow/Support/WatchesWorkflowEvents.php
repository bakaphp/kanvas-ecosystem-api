<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * Whether a workflow event fired, and with what: a rule of the test company on the event, and the
 * params of every run started for it. Read off the stored workflow, so it works with the queue
 * faked as well as without.
 */
trait WatchesWorkflowEvents
{
    use BuildsSyncRules;

    /** @var array<int, int> rule id => the last workflow id before it was watched */
    private array $watchedSince = [];

    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $conditions
     */
    protected function watchWorkflowEvent(string $event, string $modelClass, array $conditions = []): Rule
    {
        $rule = $this->createSyncRule(
            app: app(Apps::class),
            event: $event,
            modelClass: $modelClass,
            activityClass: PlainRecordingActivity::class,
            company: auth()->user()->getCurrentCompany(),
            conditions: $conditions,
            actionName: 'Test watch ' . $event . ' ' . uniqid(),
        );

        $this->watchedSince[$rule->getId()] = (int) StoredWorkflow::query()->max('id');

        return $rule;
    }

    /**
     * @return Collection<int, array<string, mixed>> the params of each run, oldest first
     */
    protected function firedWith(Rule $rule): Collection
    {
        return StoredWorkflow::query()
            ->where('id', '>', $this->watchedSince[$rule->getId()] ?? 0)
            ->where('class', DynamicRuleWorkflow::class)
            ->orderBy('id')
            ->get()
            ->map(fn (StoredWorkflow $workflow): array => $workflow->workflowArguments())
            ->filter(fn (array $arguments): bool => ($arguments[1] ?? null) instanceof Rule && $arguments[1]->getId() === $rule->getId())
            ->map(fn (array $arguments): array => (array) ($arguments[3] ?? []))
            ->values();
    }
}
