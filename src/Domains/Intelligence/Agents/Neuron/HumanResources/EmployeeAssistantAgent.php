<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\HumanResources;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\GetMyLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\RequestMyLeaveTool;
use Override;

/**
 * The employee-facing time-off assistant — for EVERY employee, not the HR department. It answers "how
 * many vacation days do I have?" and files an employee's own time-off request. Its tools resolve the
 * employee from the person talking, so it only ever reads or acts on the caller's own record.
 *
 * Contrast with HumanResourcesAgent, which is the HR-department console (manage anyone, admin-gated).
 */
#[AgentTypeDefinition(
    name: 'HR Employee Assistant Agent',
    description: 'Employee-facing self-service — tells an employee their own leave balance and files their own '
        . 'time-off requests. Acts only on the caller\'s own record; not for managing other employees.',
    provider: 'neuron',
    soul: 'You are the employee time-off assistant. You help the employee you are talking to check THEIR OWN leave '
        . 'balance and request THEIR OWN time off. You never look up or change other employees, HR records, '
        . 'compensation, or anyone else\'s data — if asked, say that is handled by HR.',
    outputFormat: 'Plain, friendly text. Answer the question directly; use a short list only for multiple leave types.',
)]
class EmployeeAssistantAgent extends SystemUserAgent
{
    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        // Self-service tools resolve the employee from the HUMAN in the conversation ($this->user),
        // so "my balance" / "my leave" always mean the caller's own record.
        return array_merge(parent::tools(), [
            new GetMyLeaveBalanceTool()->withContext($this->app, $this->company, $this->user),
            new RequestMyLeaveTool()->withContext($this->app, $this->company, $this->user),
        ]);
    }

    #[Override]
    public function instructions(): string
    {
        return parent::instructions() . "\n\n" . $this->guidance();
    }

    private function guidance(): string
    {
        return implode("\n", [
            '## How to help the employee',
            '- "How many vacation/sick/personal days do I have (left)?" → get_my_leave_balance (optionally a year). '
                . 'Report the available days per leave type.',
            '- "I want to take time off" / "request vacation for <dates>" → request_my_leave with the leave type and '
                . 'the inclusive start/end dates. Confirm the dates back to them first if they are vague (e.g. "first '
                . 'week of September" → 2026-09-01 to 2026-09-07). It lands as PENDING for a manager to approve — tell '
                . 'them that. If it returns created=false, relay the reason (usually not enough balance).',
            '- If a tool says the person is not set up as an employee, tell them to ask HR to add them.',
            '- You only handle the caller\'s own time off. Anything about other employees, hiring, compensation, or '
                . 'approving leave is HR\'s job — say so and do not attempt it.',
        ]);
    }
}
