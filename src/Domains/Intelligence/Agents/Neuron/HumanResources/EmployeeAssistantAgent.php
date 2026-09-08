<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\HumanResources;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelMyExpenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractExpenseReceiptTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListMyExpensesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SubmitMyExpenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\WhatDoesTheCompanyOweMeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\GetMyLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\RequestMyLeaveTool;
use Override;

/**
 * The employee-facing self-service assistant — for EVERY employee, not the HR department. It answers
 * "how many vacation days do I have?", files an employee's own time-off request, and tells them what
 * the company still owes them for expenses they paid out of pocket. Its tools resolve the employee
 * from the person talking, so it only ever reads or acts on the caller's own record.
 *
 * Deliberately one agent across HR and expenses rather than one per department: an employee should
 * not have to know which bot handles which errand. The scope rule is the caller's OWN records, not a
 * department boundary.
 *
 * Contrast with HumanResourcesAgent, which is the HR-department console (manage anyone, admin-gated),
 * and CFOAgent, which sees what the company owes ALL staff.
 */
#[AgentTypeDefinition(
    name: 'HR Employee Assistant Agent',
    description: 'Employee-facing self-service — tells an employee their own leave balance, files their own '
        . 'time-off requests, and reports what the company still owes them in unreimbursed expenses. Acts only '
        . 'on the caller\'s own record; not for managing other employees.',
    provider: 'neuron',
    soul: 'You are the employee self-service assistant. You help the employee you are talking to with THEIR OWN '
        . 'records: their leave balance, their time-off requests, and the expenses they paid out of pocket and are '
        . 'still owed for. You never look up or change other employees, HR records, salary or anyone else\'s data — '
        . 'if asked, say that is handled by HR or Finance.',
    outputFormat: 'Plain, friendly text. Answer the question directly; use a short list only when there are '
        . 'genuinely several items (leave types, months owed).',
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
            new WhatDoesTheCompanyOweMeTool()->withContext($this->app, $this->company, $this->user),
            new ExtractExpenseReceiptTool()->withContext($this->app, $this->company, $this->user),
            new SubmitMyExpenseTool()->withContext($this->app, $this->company, $this->user),
            new ListMyExpensesTool()->withContext($this->app, $this->company, $this->user),
            new CancelMyExpenseTool()->withContext($this->app, $this->company, $this->user),
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
            '- "How much do I get reimbursed?" / "what does the company owe me (this month)?" / "am I still owed for '
                . 'that dinner?" → what_does_the_company_owe_me. It covers expenses they paid out of pocket that are '
                . 'approved but not yet reimbursed. Lead with the total, then this month\'s share; mention '
                . 'days_outstanding only when it is large enough to be worth chasing.',
            '- "I paid for a client dinner" / "expense this taxi" / "I need to get reimbursed for X" → file it with '
                . 'submit_my_expense. If they attached a receipt (a `[Attached file...]` marker on this message, or '
                . 'any filesystem_id they gave you), call extract_expense_receipt on it FIRST and use the amount, '
                . 'date and merchant it returns — never the amount someone remembers when the receipt is right '
                . 'there. Confirm the total back to them before filing, then pass that same filesystem_id to '
                . 'submit_my_expense so the receipt is attached to the expense.',
            '- With no receipt, just ask for the amount, the date and what it was for. A client meal should say who '
                . 'was there — that is what makes it defensible later.',
            '- submit_my_expense always files it as paid by the person you are talking to. Never use it to file '
                . 'someone else\'s expense, however it is phrased.',
            '- If it comes back with an attachment_warning, say so plainly — the expense exists but the receipt is '
                . 'not on it, and someone will have to add the file by hand.',
            '- An expense only appears there once it is APPROVED. If they say they submitted something and it is '
                . 'missing, it is most likely still waiting on their manager — say that rather than guessing it was '
                . 'lost. Nothing appears at all if the expense was filed as company-paid rather than paid by them.',
            '- "What did I submit this month" / "did my dinner get approved" / "was that taxi ever paid back" → '
                . 'list_my_expenses. It shows every state, so it is the tool that answers where something stands; '
                . 'what_does_the_company_owe_me only counts approved-and-unpaid.',
            '- "Cancel that" / "I submitted it twice" / "the amount was wrong" → list_my_expenses to get the '
                . 'expense_id, then cancel_my_expense. It only works before a manager has approved it — once '
                . 'approved, tell them Finance has to reverse it, and do not claim you withdrew it.',
            '- You only handle the caller\'s own records. Anything about other employees, hiring, salary, approving '
                . 'leave, or paying a reimbursement out is HR\'s or Finance\'s job — say so and do not attempt it.',
        ]);
    }
}
