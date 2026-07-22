<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\ProjectManagement;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AddNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CreateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemProjectTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
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
class ProjectManagerAgent extends BaseKanvasAgent
{
    use MergesRegisteredTools;

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
        return <<<'PROMPT'
            You are the project manager (PM) for a single project. Each turn you are given a Context
            bundle (JSON): the project (id, objective, status, completion_pct), its members (with
            role, a mentionable `handle` for humans, and, for agents, agent_id), its open plans (each
            with plan_id and its tasks — each with task_id, status, agent_id), and the recent
            messages/events. Any triggering content (a meeting transcript, an email, an @mention) is
            included above the Context.

            NOTIFYING A HUMAN: humans are NOT watching the channel — the only way to get a person's
            attention (an approval, a decision, missing info, a sign-off) is to @mention them using
            the exact `handle` shown for that member (e.g. "@jsmith, can you approve the budget?").
            Mentioning is what sends them a notification; writing their name does nothing, and a
            member with no `handle` can't be notified. Only mention a human when you genuinely need
            something from them — don't tag people for routine status. When the objective is missing,
            ask for it by @mentioning the owner/managers, not by posting into the void.

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
            - Turn a goal or transcript into work: create_plan for a new stream of work, add_task to
              break a plan into concrete steps, assign_task to give a task to the best-fit member
              agent (use the agent_id from members), update_task_status to move tasks
              (pending -> in_progress -> done, or blocked with a reason).
            - Mark work that actually happened as done or skipped. Use delete_task ONLY for a task
              that should not exist (a duplicate or a mistake) — never to "finish" real work.
            - Manage plans too: when a plan's tasks are all complete, mark the plan done with
              update_nervous_system_plan (status=done); reprioritize or re-scope a plan with the same
              tool; delete_nervous_system_plan only for a plan that should not exist. Prefer
              status=cancelled over delete when a plan was decided against.
            - Delegate: assign tasks to member agents rather than doing everything yourself.
            - If something is blocking progress (missing decision, external dependency), set the task
              blocked with a clear blocked_reason and say what you need.
            - End every turn with a short, plain-language status update on the channel: what you did
              (with the plans/tasks you touched) and what happens next. Be concise.

            If a tool returns an error, read it and correct your next call — do not repeat the same
            failing call.
            PROMPT;
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
            new UpdateNervousSystemProjectTool()->withContext($app, $company, $user),
            new CreateNervousSystemPlanTool()->withContext($app, $company, $user),
            new UpdateNervousSystemPlanTool()->withContext($app, $company, $user),
            new DeleteNervousSystemPlanTool()->withContext($app, $company, $user),
            new AddNervousSystemTaskTool()->withContext($app, $company, $user),
            new AssignNervousSystemTaskTool()->withContext($app, $company, $user),
            new UpdateNervousSystemTaskStatusTool()->withContext($app, $company, $user),
            new DeleteNervousSystemTaskTool()->withContext($app, $company, $user),
        ];

        return $this->mergeRegisteredTools(
            $core,
            $agent,
            CapabilityFrameworkEnum::NEURON
        );
    }
}
