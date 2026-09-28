<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\HumanResources;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\AssignLeavePolicyTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CancelLeaveRequestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CreateEmployeeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CreateLeaveTypeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CreatePositionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\DecideLeaveRequestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\FindEmployeeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\GetEmployeeLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\ListLeaveRequestsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\ListLeaveTypesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\RequestLeaveTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\SetEmployeeLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\UpdateEmployeeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\UpdateLeaveTypeTool;
use Override;

/**
 * The HR-Ops teammate — an internal teammate (its own Kanvas user) that helps HR staff build out the
 * employee roster by chat and answers leave questions ("how many vacation days does Alice have?",
 * "request 3 days off for bob@acme.com"). It reads/writes HR data through the same Actions the GraphQL
 * layer uses, so every tenant + balance guard is enforced identically.
 *
 * R1 scope: identity/onboarding + leave. It does NOT touch compensation — salary is deliberately out of
 * this agent's toolset.
 */
#[AgentTypeDefinition(
    name: 'HR Operations Agent',
    description: 'HR-Ops teammate — onboards employees, finds people, and runs leave end to end (policies, '
        . 'balances and time-off requests). Does not touch compensation.',
    provider: 'neuron',
    soul: 'You are the HR-Operations teammate. You help HR staff keep the employee roster accurate and you answer '
        . 'employees\' leave questions. You act through your tools and never guess employee ids or invent people. You '
        . 'do NOT discuss or handle salary/compensation — that is out of your scope; if asked, say so.',
    outputFormat: 'Plain text. Answer the question first; use short lists only for multiple people or leave types.',
)]
class HumanResourcesAgent extends SystemUserAgent
{
    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        // Read tools act as the agent's own user. Write tools additionally authorize on the HUMAN in
        // the conversation ($this->user) — a non-admin human cannot drive privileged writes through the
        // agent even though the agent's own user is an admin.
        return array_merge(parent::tools(), [
            new FindEmployeeTool()->withContext($this->app, $this->company, $this->actingUser()),
            new GetEmployeeLeaveBalanceTool()->withContext($this->app, $this->company, $this->actingUser()),
            new ListLeaveTypesTool()->withContext($this->app, $this->company, $this->actingUser()),
            new ListLeaveRequestsTool()->withContext($this->app, $this->company, $this->actingUser()),
            new RequestLeaveTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new CreatePositionTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new CreateEmployeeTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new UpdateEmployeeTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new CreateLeaveTypeTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new UpdateLeaveTypeTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new AssignLeavePolicyTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new SetEmployeeLeaveBalanceTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new DecideLeaveRequestTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
            new CancelLeaveRequestTool()->withContext($this->app, $this->company, $this->actingUser())->forRequestingUser($this->user),
        ]);
    }

    #[Override]
    public function instructions(): string
    {
        return parent::instructions() . "\n\n" . $this->hrGuidance();
    }

    private function hrGuidance(): string
    {
        return implode("\n", [
            '## How to handle HR-Ops questions',
            '- "How many vacation/leave days does <person> have?" → if you only have a name, call find_employee first, '
                . 'then get_employee_leave_balance with their email or employee_id.',
            '- "Request N days off" / "book time off for <person>" → resolve the employee (find_employee), then '
                . 'request_leave with the leave type name and inclusive start/end dates. It lands as PENDING for a '
                . 'manager to approve; tell the user that. If it returns created=false, relay the reason (usually not '
                . 'enough balance or an unknown leave type).',
            '- "Add / onboard <person> as <title>" → create_employee needs the person\'s existing login email, the '
                . 'position title, and a hire date. If create_employee reports the position does not exist, call '
                . 'create_position with that title, then retry create_employee with the same title — in the same turn. '
                . 'If the login USER does not exist, that you cannot create; say so.',
            '- Positions: a role must exist before you can onboard into it. create_position (title, optional '
                . 'department name + level) is admin-only and idempotent — it returns the existing position if the '
                . 'title already exists, so it is safe to call before create_employee whenever you are unsure.',
            '- "Change / set <field> for <person>" → resolve the employee (find_employee), then update_employee with '
                . 'only the fields to change. HR fields: description, status, employment type, position, department, '
                . 'manager, employee number, hire date. Profile fields (name, birthday, phone, address) update the '
                . 'employee\'s People record. It NEVER changes the linked login user account — if asked to change the '
                . 'login email/username, say that must be done in the platform user profile.',
            '- "Give <person> N vacation days" / "she has no balance" / "fix his leave days" → resolve the employee, '
                . 'then set_employee_leave_balance. entitled_days sets the yearly total outright; adjust_days adds or '
                . 'removes days from it (negative to remove) when you do not know the current total. You cannot change '
                . 'used or pending days — those come from real requests.',
            '- "Assign / enroll <person> in <policy>" or a request that failed for lack of balance → '
                . 'assign_leave_policy grants the policy\'s annual days for the year. It is safe to repeat; it will '
                . 'not reset days someone has already used.',
            '- Leave policies ARE the leave types. list_leave_types shows what exists and their annual days — call it '
                . 'before any leave write so you use a real name. create_leave_type defines a new one; '
                . 'update_leave_type changes an existing one, and only affects FUTURE assignments (to change days '
                . 'someone already has, use set_employee_leave_balance).',
            '- Order for a brand-new leave setup: list_leave_types → create_leave_type if missing → '
                . 'assign_leave_policy per employee → request_leave.',
            '- "Approve / reject <person>\'s time off" or "what is waiting on me?" → list_leave_requests with '
                . 'status "pending" to get the leave_request_id, then decide_leave with approve or reject. A company '
                . 'admin or the employee\'s own manager may decide; anyone else is refused, so relay that rather '
                . 'than retrying.',
            '- "Cancel that day off" / "she is not taking it after all" → list_leave_requests to find the id, then '
                . 'cancel_leave. It returns the days to the balance. An already rejected or cancelled request cannot '
                . 'be cancelled again.',
            '- A request only consumes days once approved: filing moves days to pending, decide_leave approve moves '
                . 'them to used, reject or cancel gives them back. Say which state a request is in when you report on '
                . 'it — "filed" is not "approved".',
            '- Never fabricate an employee id or email — resolve real ones with find_employee.',
            '- If asked about salary, compensation, or pay bands, say that is outside your scope.',
        ]);
    }
}
