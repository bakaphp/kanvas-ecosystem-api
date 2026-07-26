<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Orchestrator\ProjectOrchestratorAgent;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Provisions (idempotently) ONE orchestrator per company: a dedicated user, the orchestrator agent, its
 * company Inbox project (routing hub — no plans), and the company-level routing receiver. Safe to
 * re-run — each step reuses what already exists. Requires the global 'Project Orchestrator' agent-type
 * to be synced first (`kanvas:intelligence:sync-agent-types`).
 */
class EnsureCompanyOrchestratorAgentAction
{
    private const string INBOX_MARKER = 'orchestrator_inbox';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly SignalSourceEnum $source = SignalSourceEnum::READ_AI,
    ) {
    }

    public function execute(): Agent
    {
        $agentType = AgentType::query()
            ->where('handler', ProjectOrchestratorAgent::class)
            ->where('apps_id', 0)
            ->first();

        if ($agentType === null) {
            throw new ValidationException(
                "The 'Project Orchestrator' agent-type is not synced. Run kanvas:intelligence:sync-agent-types first.",
            );
        }

        $user = $this->ensureUser();
        $agent = $this->ensureAgent($agentType, $user);
        $inbox = $this->ensureInboxProject($agent, $user);
        $this->ensureReceiver($user, $agent, $inbox);

        return $agent;
    }

    private function ensureUser(): Users
    {
        $email = sprintf('orchestrator+company-%d@orchestrator.kanvas.app', $this->company->getId());

        $existing = Users::query()->where('email', $email)->first();
        if ($existing instanceof Users) {
            return $existing;
        }

        $create = new CreateUserAction(
            new RegisterInput(
                firstname: 'Project',
                lastname: 'Orchestrator',
                displayname: sprintf('orchestrator-%d', $this->company->getId()),
                email: $email,
                password: Str::random(40),
                branch: $this->company->branch,
            ),
            $this->app,
        );
        $create->disableWorkflow();

        return $create->execute();
    }

    private function ensureAgent(AgentType $agentType, Users $user): Agent
    {
        // Check first: CreateAgentAction's updateOrCreate matches on ALL fields (incl. JSON-cast role/
        // config), so re-invoking it isn't reliably idempotent — a prior orchestrator agent for this
        // (company, type, user) is the one true instance.
        $existing = Agent::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('agent_type_id', $agentType->getId())
            ->where('user_id', $user->getId())
            ->notDeleted()
            ->first();

        if ($existing instanceof Agent) {
            return $existing;
        }

        return new CreateAgentAction(
            new AgentData(
                app: $this->app,
                company: $this->company,
                user: $user,
                agentType: $agentType,
                name: 'Project Orchestrator',
                role: 'orchestrator',
                is_active: true,
                description: 'Routes inbound signals to the right project PM.',
            ),
        )->execute();
    }

    private function ensureInboxProject(Agent $agent, Users $user): Project
    {
        // The orchestrator agent is the PM of exactly one project — its Inbox — so agent_id identifies it.
        $existing = Project::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('agent_id', $agent->getId())
            ->first();

        if ($existing instanceof Project) {
            return $existing;
        }

        $inbox = new CreateProjectAction(
            ProjectData::from($this->app, $user, $this->company, [
                'title' => 'Signal Inbox',
                'objective' => 'Routing hub for unlabeled inbound signals. Holds no plans.',
                'agent_id' => $agent->getId(),
            ]),
        )->execute();

        $inbox->metadata = (array) ($inbox->metadata ?? []) + [self::INBOX_MARKER => true];
        $inbox->saveQuietly();

        return $inbox;
    }

    private function ensureReceiver(Users $user, Agent $agent, Project $inbox): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessOrchestratorSignalJob::class],
            ['name' => 'Orchestrator Signal Ingest'],
        );

        // Already provisioned? The inbox project's receiver points at the orchestrator job.
        $current = $inbox->receiverWebhook;
        if ($current instanceof ReceiverWebhook && (int) $current->action_id === (int) $action->getId()) {
            return $current;
        }

        $receiver = new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $this->app,
                company: $this->company,
                user: $user,
                action: $action,
                name: sprintf('Orchestrator ingest (company %d)', $this->company->getId()),
                description: 'Unlabeled inbound signals routed to the right project PM.',
                configuration: [
                    'signal_source' => $this->source->value,
                    'orchestrator_agent_id' => $agent->getId(),
                    'inbox_project_id' => $inbox->getId(),
                ],
                is_active: true,
                run_async: true,
            ),
        )->execute();

        // Point the Inbox at the orchestrator receiver (replacing the default project receiver the
        // observer bound on creation).
        $inbox->receiver_webhook_id = $receiver->getId();
        $inbox->saveQuietly();

        return $receiver;
    }
}
