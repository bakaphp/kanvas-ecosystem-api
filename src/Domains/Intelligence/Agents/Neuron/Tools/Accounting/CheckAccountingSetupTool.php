<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Setup\Actions\SyncAccountingSetupPlanAction;
use Kanvas\Scribe\Setup\DataTransferObject\ReadinessItem;
use Kanvas\Scribe\Setup\Services\AccountingReadinessService;
use NeuronAI\Tools\Tool;
use Override;
use Throwable;

/**
 * The same answer the AP/AR prompt line and the onboarding setup plan read, so the agent, the agents it
 * sets up and the checklist the person sees never disagree. Scoped to the agent's own company: setup is
 * per company, never across a read grant.
 */
#[AgentTool(name: 'Check Accounting Setup', category: 'accounting')]
class CheckAccountingSetupTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;

    protected string $name = 'check_accounting_setup';

    protected ?string $description = 'What the accounts payable / receivable agents still need before they can work: one line per '
        . 'requirement (ledger, fiscal periods, accounting inbox, approver, bank, accounting software, and for the Dominican '
        . 'Republic the e-CF provider and the e-NCF ranges) with status ready / missing / degraded, who fixes it and how, '
        . 'and which open onboarding setup tasks each one closes. Call it FIRST whenever you help with accounting setup '
        . 'or before saying the agents are ready, and explain each fix to the person in plain language, one step at a time. '
        . 'A missing e-CF provider is connected by the person in the integration connect step of onboarding: never ask '
        . 'for provider passwords, API keys or certificates in chat.';

    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        try {
            $readiness = new AccountingReadinessService()->check($this->app, $this->company);
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The accounting setup could not be checked right now.', guidance: 'Say so and do not guess what is ready.');
        }

        $items = array_values(array_map(fn (ReadinessItem $item): array => $item->toArray(), $readiness));
        $notReady = array_values(array_filter(
            $readiness,
            fn (ReadinessItem $item): bool => ! $item->isReady() && ! $item->optional,
        ));

        return $this->ok(
            [
                'ready' => $notReady === [],
                'items' => $items,
                'setup_tasks' => $this->setupTasks($readiness),
            ],
            $notReady === []
                ? 'Everything the accounting agents need is in place.'
                : 'Walk the person through the items that are not ready, one at a time, using fix_by and fix_how.',
        );
    }

    /**
     * @param array<string, ReadinessItem> $readiness
     * @return list<array<string, mixed>>
     */
    private function setupTasks(array $readiness): array
    {
        $row = CompanyOnboarding::forCompany($this->app, $this->company);
        if ($row === null) {
            return [];
        }

        $tasks = [];
        foreach (new SyncAccountingSetupPlanAction($row)->openTasks($readiness) as ['task' => $task, 'requirement' => $requirement, 'item' => $item]) {
            $tasks[] = [
                'task_id' => $task->getId(),
                'title' => $task->title,
                'requirement' => $requirement,
                'closes_now' => (bool) $item?->isReady(),
            ];
        }

        return $tasks;
    }
}
