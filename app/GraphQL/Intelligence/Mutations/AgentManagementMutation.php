<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\Actions\RebuildAgentToolInstructionsAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentAction;
use Kanvas\Intelligence\Agents\Actions\Voice\ConfigureAgentInboundWebhookAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentDTO;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentType as AgentTypeModel;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Repositories\UsersRepository;

class AgentManagementMutation
{
    public function create(mixed $root, array $req): Agent
    {
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agentType = AgentTypeModel::getById($input['agent_type_id'], app: $app);
        $agentModel = isset($input['agent_model_id']) ? AgentModel::getById($input['agent_model_id'], app: $app) : null;
        $task = isset($input['company_task_list_id']) ? TaskList::getById($input['company_task_list_id'], app: $app) : null;
        $parentAgent = isset($input['parent_agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $input['parent_agent_id'], $company, $app)
            : null;
        $llmConfig = $this->resolveLlmConfig($input, $company, $app);

        $input = $this->mapRoleToFields($input);

        $personaUser = isset($input['user_id'])
            ? UsersRepository::getUserOfAppById((int) $input['user_id'], $app)
            : auth()->user();

        $agentDTO = new AgentDTO(
            app: $app,
            company: $company,
            user: $personaUser,
            agentModel: $agentModel,
            agentType: $agentType,
            name: $input['name'],
            role: $input['role'],
            is_active: $input['is_active'],
            description: $input['description'] ?? null,
            config: $input['config'],
            task: $task,
            communicationChannel: $input['communication_channels'] ?? [],
            soul: $input['soul'] ?? null,
            instructions: $input['instructions'] ?? null,
            outputFormat: $input['output_format'] ?? null,
            identity: $input['identity'] ?? null,
            userContext: $input['user_context'] ?? null,
            toolsConfig: $input['tools_config'] ?? null,
            voiceConfig: $this->normalizeVoiceConfigPhone($input['voice_config'] ?? null),
            tools: isset($input['tool_ids']) ? $this->resolveTools($input['tool_ids'], $app) : null,
            parentAgent: $parentAgent,
            createdBy: auth()->user(),
            isSubAgent: (bool) ($input['is_sub_agent'] ?? false),
            agentLlmConfig: $llmConfig,
        );

        $agent = new CreateAgentAction($agentDTO)->execute();

        if (! empty($input['swarm_ids'])) {
            $this->syncSwarms($agent, $input['swarm_ids'], $company, $app);
        }

        if (isset($input['tool_ids'])) {
            $this->syncTools($agent, $input['tool_ids'], $app);
        }

        new RebuildAgentToolInstructionsAction($agent, $app)->execute();

        // Best-effort: point the agent's Twilio number at the inbound webhook.
        // Never throws — inbound wiring must not block creating the agent.
        new ConfigureAgentInboundWebhookAction($agent, $app)->execute();

        return $agent;
    }

    /**
     * Re-run the inbound-webhook wiring for an agent's Twilio number — the admin
     * "retry" button. Returns the ConfigureAgentInboundWebhookAction outcome
     * ({status, message, url}); the action also persists it to
     * voice_config.inbound_webhook so the Voice tab reflects the fresh result.
     *
     * @return array{status: string, message: string, url: string|null}
     */
    public function configureInboundWebhook(mixed $root, array $req): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return new ConfigureAgentInboundWebhookAction($agent, $app)->execute();
    }

    public function update(mixed $root, array $req): Agent
    {
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::getByIdFromCompanyApp((int) $req['id'], $company, $app);
        $agentType = AgentTypeModel::getById($input['agent_type_id'], app: $app);
        $agentModel = isset($input['agent_model_id']) ? AgentModel::getById($input['agent_model_id'], app: $app) : null;
        $task = isset($input['company_task_list_id']) ? TaskList::getById($input['company_task_list_id'], app: $app) : null;
        $parentAgent = isset($input['parent_agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $input['parent_agent_id'], $company, $app)
            : null;
        $llmConfig = $this->resolveLlmConfig($input, $company, $app);

        $input = $this->mapRoleToFields($input);
        $personaUser = isset($input['user_id'])
            ? UsersRepository::getUserOfAppById((int) $input['user_id'], $app)
            : ($agent->user ?? auth()->user());

        $agentDTO = new AgentDTO(
            app: $app,
            company: $company,
            user: $personaUser,
            agentType: $agentType,
            agentModel: $agentModel,
            name: $input['name'],
            role: $input['role'],
            is_active: $input['is_active'],
            description: $input['description'] ?? null,
            config: $input['config'],
            task: $task,
            communicationChannel: $input['communication_channels'] ?? [],
            soul: $input['soul'] ?? null,
            instructions: array_key_exists('instructions', $input) ? $input['instructions'] : $agent->instructions,
            outputFormat: $input['output_format'] ?? null,
            identity: $input['identity'] ?? null,
            userContext: $input['user_context'] ?? null,
            toolsConfig: $input['tools_config'] ?? null,
            voiceConfig: $this->normalizeVoiceConfigPhone($input['voice_config'] ?? null),
            parentAgent: $parentAgent,
            isSubAgent: (bool) ($input['is_sub_agent'] ?? false),
            agentLlmConfig: $llmConfig,
        );

        $agent = new UpdateAgentAction($agentDTO, $agent)->execute();

        if (isset($input['swarm_ids'])) {
            $this->syncSwarms($agent, $input['swarm_ids'], $company, $app);
        }

        if (isset($input['tool_ids'])) {
            $this->syncTools($agent, $input['tool_ids'], $app);
        }

        new RebuildAgentToolInstructionsAction($agent, $app)->execute();

        // Best-effort: (re)point the agent's Twilio number at the inbound webhook
        // when its number changes. Never throws — must not block the update.
        new ConfigureAgentInboundWebhookAction($agent, $app)->execute();

        return $agent->refresh();
    }

    public function delete(mixed $root, array $req): bool
    {
        $agent = Agent::getByIdFromCompanyApp(
            id: $req['id'],
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany()
        );

        return (bool) $agent->delete();
    }

    private function resolveLlmConfig(array $input, CompanyInterface $company, AppInterface $app): ?AgentLlmConfig
    {
        if (empty($input['agent_llm_config_id'])) {
            return null;
        }

        return AgentLlmConfig::getByIdFromCompanyApp((int) $input['agent_llm_config_id'], $company, $app);
    }

    /**
     * Canonicalize the per-agent phone number inside voice_config on save, so the
     * stored value is a clean E.164 caller id — valid for Twilio outbound and an
     * exact match for inbound number routing. Non-array input passes through.
     */
    private function normalizeVoiceConfigPhone(mixed $voiceConfig): mixed
    {
        if (
            is_array($voiceConfig)
            && isset($voiceConfig['phone_number'])
            && is_string($voiceConfig['phone_number'])
        ) {
            $voiceConfig['phone_number'] = AgentsRepository::cleanPhoneNumber($voiceConfig['phone_number']);
        }

        return $voiceConfig;
    }

    /**
     * When role is provided and soul/instructions are not explicitly set,
     * auto-populate them from role['background'] and role['steps'] for backward compatibility.
     */
    private function mapRoleToFields(array $input): array
    {
        $role = $input['role'] ?? null;

        if (! is_array($role)) {
            return $input;
        }

        if (empty($input['soul']) && ! empty($role['background'])) {
            $background = $role['background'];
            $input['soul'] = is_array($background) ? implode("\n", $background) : (string) $background;
        }

        if (empty($input['instructions']) && ! empty($role['steps'])) {
            $steps = $role['steps'];
            $input['instructions'] = is_array($steps) ? implode("\n", $steps) : (string) $steps;
        }

        return $input;
    }

    /**
     * @param array<int, string> $toolIds
     *
     * @return array<int, Tool>
     */
    private function resolveTools(array $toolIds, AppInterface $app): array
    {
        $tools = [];
        foreach ($toolIds as $toolId) {
            /** @var Tool $tool */
            $tool = Tool::query()
                ->where('id', (int) $toolId)
                ->whereIn('apps_id', [0, $app->getId()])
                ->firstOrFail();
            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * @param array<int, string> $toolIds
     */
    private function syncTools(Agent $agent, array $toolIds, AppInterface $app): void
    {
        $ids = [];
        foreach ($toolIds as $toolId) {
            $tool = Tool::query()
                ->where('id', (int) $toolId)
                ->whereIn('apps_id', [0, $app->getId()])
                ->firstOrFail();
            $ids[] = $tool->getId();
        }

        $agent->selectedTools()->sync($ids);
    }

    /**
     * @param array<int, string> $swarmIds
     */
    private function syncSwarms(
        Agent $agent,
        array $swarmIds,
        CompanyInterface $company,
        AppInterface $app
    ): void {
        $agent->swarms()->detach();

        foreach ($swarmIds as $swarmId) {
            AgentSwarm::getByIdFromCompanyApp(
                (int) $swarmId,
                $company,
                $app
            );

            $agent->swarms()->attach((int) $swarmId);
        }
    }
}
