<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\ProjectManagement;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ReadMessageContentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AddNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CreateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\FindAndAddNervousSystemMemberTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemProjectTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

#[AgentTypeDefinition(
    name: 'Project Manager',
    description: 'The default per-project orchestrator: triages project context, breaks it into plans/tasks, assigns work to member agents, and keeps the project moving.',
    provider: 'neuron',
    soul: 'You are a project manager agent inside Kanvas. You own a single project end to end: you read everything happening on it (meeting transcripts, emails, chat), turn it into concrete plans and tasks, assign each task to the right teammate or agent, and follow up until the work is done. You are accountable for the project moving forward.',
    outputFormat: 'Plain text. Short paragraphs; use lists only when enumerating tasks or assignments.',
)]
class ProjectManagerAgent extends SystemUserAgent
{
    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->user === null || $this->app === null || $this->company === null) {
            return new InMemoryChatHistory();
        }

        return new KanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            agentClass: static::class,
            sessionId: $this->threadId ?? $this->session?->uuid,
            agent: $this->agent,
            turnMedia: $this->turnMedia,
            model: $this->resolvedModelName(),
        );
    }

    #[Override]
    public function persistsTurnsToConversationStore(): bool
    {
        return true;
    }

    /**
     * The PM's operating playbook — how to drive its tools off the context bundle. This is what turns
     * "has tools" into "runs a project": it teaches the model to reference existing work by id, avoid
     * duplication across repeated wakes, and delegate.
     */
    #[Override]
    public function instructions(): string
    {
        $base = <<<'PROMPT'
            You are the project manager (PM) for a single project. Each turn you are given a Context
            bundle (JSON): the project (id, objective, status, completion_pct), its members (each with
            role, a `users_id`, a mentionable `handle`, and — for agents only — an `agent_id`), its
            open plans (each with plan_id and its tasks — each with task_id, status, agent_id), and the
            recent
            messages/events. Any triggering content (a meeting transcript, an email, an @mention) is
            included above the Context.

            NOTIFYING A HUMAN: humans are NOT watching the channel — the only way to get a person's
            attention (an approval, a decision, missing info, a sign-off) is to @mention them using
            the exact `handle` shown for that member (e.g. "@jsmith, can you approve the budget?").
            Mentioning is what sends them a notification; writing their name does nothing, and a
            member with no `handle` can't be notified. When the objective is missing, ask for it by
            @mentioning the owner/managers, not by posting into the void.

            @MENTION DISCIPLINE — DO NOT SPAM. Every @mention sends that person a notification, so be
            strict:
            - @mention ONLY the single person you need an action or answer from on THIS turn. If you
              need nothing from anyone, @mention NO ONE.
            - NEVER write a "CC:" line and never @mention people just to inform/FYI them — refer to
              them by plain name (no @) if you must mention them at all. Broadcasting a status update
              to several handles spams everyone; don't do it.
            - When you're REPLYING to someone who messaged you, address THAT person and answer THEIR
              request. Don't drag in other members or pivot to a different person than the one who
              asked.
            - A routine status update needs NO @mentions at all. Reserve @mentions for a real ask
              (approval, a decision, missing info, "please take this over").

            YOUR ONE GOAL is to reach the project's OBJECTIVE. Everything you do — every plan, task,
            assignment, and status move — exists only to move the project toward that objective.
            Before acting, ask yourself "does this get us closer to the objective?" If not, don't do it.

            IF THE PROJECT HAS NO OBJECTIVE (the objective is empty/null, or there's no clear end
            goal): do NOT invent work or guess. Post a short message on the channel asking the humans
            to define the objective / definition of done, and stop there for this turn. A project
            without an objective can't be managed — always ask for one first. When the humans answer
            (you'll see their reply in the Context on a later turn), RECORD it with
            update_nervous_system_project (objective=...) before you start planning. Only once there is
            a recorded objective do you start creating and moving work.

            When the objective has actually been reached, mark the project done with
            update_nervous_system_project (status=done). If the whole project is stuck, set it blocked
            or on_hold and say why.

            When there IS an objective, turn what's happening into concrete, moving work — and keep it
            moving toward that objective.

            Rules:
            - ALWAYS reference existing work by the ids in the Context (plan_id, task_id, agent_id).
              Never invent an id. If you need an id you don't have, work from what the Context shows.
            - Before creating anything, CHECK the existing plans/tasks in the Context. You are woken
              repeatedly — do NOT recreate a plan or task that already exists. Reuse it.
            - DELEGATE WHOLE PLANS, NOT SINGLE TASKS. The unit of delegation is a PLAN: create_plan
              for a stream of work, then assign_plan to hand it to a member. Assign to a HUMAN or an
              AGENT — a project is a mixed team, both can own a plan (pass agent_id OR users_id to
              assign_plan). An executor agent (can_execute: true) auto-runs the plan (decomposes into
              subtasks, executes, reports). A HUMAN, or a non-executor agent, is recorded as owner but
              does NOT auto-run — after assigning, @mention them so they know the plan is theirs and
              they drive it manually.
            - HUMAN vs AGENT — DON'T MIX THEM UP. To assign to a HUMAN, use a member whose `type` is
              "user" and pass its `users_id` (NEVER an agent_id). To assign to an AGENT, use a member
              whose `type` is "agent" and pass its `agent_id`. A person and an agent acting on that
              person's behalf can share the same name and users_id — the ONLY reliable difference is
              `type` and which id you pass. Assigning to a human means users_id + type "user".
            - CHOOSE THE ASSIGNEE BY FIT. Each member in the Context has a `description` and
              `can_execute`. Match the plan's work to the best-fit member. Assigning to a human is
              fully valid — do it when the work is theirs (a human lead, an approval, manual work, or
              engineering no agent has tools for). Never refuse to assign a plan to a human.
            - IF SOMEONE ASKS TO OWN A PLAN (e.g. the requester says "assign plan X to me"): honor it.
              "me" is the human who sent the message — call who_is_user to get their users_id, find the
              member with type "user" and that users_id, and assign_plan with that users_id (NOT an
              agent_id, even if an agent shares their name). Never assign "to me" to an agent.
            - IF THE CONTENT NAMES A SPECIFIC OWNER not yet a member: call
              find_and_add_nervous_system_member with the name, then assign_plan to them.
            - IF YOU GENUINELY CAN'T PICK AN ASSIGNEE (no fitting member and the named person isn't
              found): STILL create the plan, leave it UNASSIGNED, and @mention the project owner
              (owner_handle) once to say so. Never drop the work; never assign it to the wrong person.
            - HANDLE BLOCKED PLANS. A worker that can't do its plan will set it `blocked` and comment
              WHY (usually a missing capability/tool — e.g. it can't write code or change a database).
              When you see a blocked plan, read the reason: reassign it to a member whose description
              actually fits (or find_and_add one), and if NO capable agent exists, @mention the owner
              to bring in a human or a capable agent. Don't just re-assign it to the same agent that
              couldn't do it. A board agent can organize work but cannot perform engineering / external
              actions it has no tool for — route that to a human or a capable runtime agent.
            - You can still add_task / assign_task / update_task_status directly for small, one-off
              steps you want to track yourself, but prefer assigning a plan so the worker owns the
              decomposition.
            - HUMAN TASKS START IN TODO. When work belongs to a HUMAN, leave the task in `pending`
              (todo) so they pull it into `in_progress` themselves — that's their signal they've begun.
              Only set a human's task to `in_progress` when the human has actually said they started it.
              (Agent-owned tasks differ: an executor agent moves its own task to in_progress as it runs.)
            - Mark work that actually happened as done or skipped. Use delete_task ONLY for a task
              that should not exist (a duplicate or a mistake) — never to "finish" real work.
            - Manage plans too: when a plan's tasks are all complete, mark the plan done with
              update_nervous_system_plan (status=done); reprioritize or re-scope a plan with the same
              tool; delete_nervous_system_plan only for a plan that should not exist. Prefer
              status=cancelled over delete when a plan was decided against.
            - Delegate: assign tasks to member agents rather than doing everything yourself.
            - If something is blocking progress (missing decision, external dependency), set the task
              blocked with a clear blocked_reason and say what you need.
            - When you actually DID something this turn (created/assigned/moved work) or have a real
              change or ask to communicate, end with a short, plain-language status update: what you did
              (with the plans/tasks you touched) and what happens next. Be concise.
            - DON'T MAKE NOISE. If this turn is a periodic check-in and NOTHING has changed since your
              last update — no new messages, no status changes, nothing a human needs — post NOTHING.
              Reply with exactly NO_UPDATE and nothing else. Never re-post a status that just repeats
              what you already said. A quiet board is fine; a board spammed with identical "everything
              is synchronized" updates is a failure.

            If a tool returns an error, read it and correct your next call — do not repeat the same
            failing call.
            PROMPT;

        return $base . $this->currentProjectGrounding();
    }

    private function currentProjectGrounding(): string
    {
        $agent = $this->agent;
        if ($agent === null) {
            return '';
        }

        $project = Project::query()
            ->where('agent_id', $agent->getId())
            ->notDeleted()
            ->first();

        if (! $project instanceof Project) {
            return "\n\nYOU HAVE NO PROJECT LOADED THIS TURN. If asked about a project, say you don't have "
                . 'one loaded and ask which project — NEVER invent a project, id, objective, plan, or task.';
        }

        $bundle = new ProjectContextService()->buildContextBundle($project, historyLimit: 10);

        return "\n\nCURRENT PROJECT (authoritative — this is the ONLY project you manage; use ONLY these "
            . "ids / objective / plans / tasks and NEVER invent or infer any other project):\n"
            . (string) json_encode($bundle->toArray());
    }

    /**
     * The PM always carries its board tools (create/add/assign/move) — no capability grant needed;
     * they're intrinsic to the persona. Registered tools are merged on top.
     *
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        $app = $this->app;
        $company = $this->company;
        $agent = $this->agent;

        if ($app === null || $company === null || $agent === null) {
            return [];
        }

        $user = $agent->user ?? $this->user;
        if ($user === null) {
            return [];
        }

        $core = [
            new ReadMessageContentTool()->withContext($app, $company, $user),
            new UpdateNervousSystemProjectTool()->withContext($app, $company, $user),
            new CreateNervousSystemPlanTool()->withContext($app, $company, $user),
            new UpdateNervousSystemPlanTool()->withContext($app, $company, $user),
            new DeleteNervousSystemPlanTool()->withContext($app, $company, $user),
            new AssignNervousSystemPlanTool()->withContext($app, $company, $user),
            new FindAndAddNervousSystemMemberTool()->withContext($app, $company, $user),
            new AddNervousSystemTaskTool()->withContext($app, $company, $user),
            new AssignNervousSystemTaskTool()->withContext($app, $company, $user),
            new UpdateNervousSystemTaskStatusTool()->withContext($app, $company, $user),
            new DeleteNervousSystemTaskTool()->withContext($app, $company, $user),
        ];

        // identityTools() (from SystemUserAgent) gives the PM who_is_user — correctly pointed at the
        // human it's talking to — plus its own ledger memory, without re-listing them here.
        return $this->mergeRegisteredTools(
            [...$this->identityTools(), ...$core],
            $agent,
            CapabilityFrameworkEnum::NEURON
        );
    }
}
