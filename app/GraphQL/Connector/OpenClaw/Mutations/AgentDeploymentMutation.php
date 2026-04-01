<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\CollectDeploymentUsageAction;
use Kanvas\Connectors\OpenClaw\Actions\DispatchAgentDeploymentAction;
use Kanvas\Connectors\OpenClaw\Actions\ExecDeploymentCommandAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerLogsAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerStatusAction;
use Kanvas\Connectors\OpenClaw\Actions\GetDeploymentConfigAction;
use Kanvas\Connectors\OpenClaw\Actions\UpdateDeploymentConfigAction;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Jobs\RestartAgentContainerJob;
use Kanvas\Connectors\OpenClaw\Jobs\TerminateAgentJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

class AgentDeploymentMutation
{
    public function launch(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp((int) $input['machine_id'], $company, $app);

        return new DispatchAgentDeploymentAction(
            $agent,
            $machine,
            $app,
            $company,
        )->execute();
    }

    public function terminate(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        TerminateAgentJob::dispatch($deployment);

        return true;
    }

    public function restart(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        RestartAgentContainerJob::dispatch($deployment);

        return true;
    }

    public function logs(mixed $root, array $request): string
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $lines = (int) ($request['lines'] ?? 100);

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        return new GetAgentContainerLogsAction($deployment, $lines)->execute();
    }

    public function status(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        return new GetAgentContainerStatusAction($deployment)->execute();
    }

    public function collectUsage(mixed $root, array $request): AgentUsageSnapshot
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        return new CollectDeploymentUsageAction(
            $deployment,
            $app,
            $company,
        )->execute();
    }

    public function setSlackTokens(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        $agent->set(CustomFieldEnum::SLACK_BOT_TOKEN->value, $request['slack_bot_token']);
        $agent->set(CustomFieldEnum::SLACK_APP_TOKEN->value, $request['slack_app_token']);

        return true;
    }

    public function setTelegramToken(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        $agent->set(CustomFieldEnum::TELEGRAM_BOT_TOKEN->value, $request['telegram_bot_token']);

        return true;
    }

    public function execCommand(mixed $root, array $request): bool
    {
        $app = app(Apps::class);

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getById((int) $request['deployment_id'], $app);

        return new ExecDeploymentCommandAction(
            $deployment,
            (string) $request['command'],
            (string) $request['session_id'],
        )->execute();
    }

    public function getConfig(mixed $root, array $request): string
    {
        $app = app(Apps::class);

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getById((int) $request['deployment_id'], $app);

        return new GetDeploymentConfigAction($deployment)->execute();
    }

    public function updateConfig(mixed $root, array $request): bool
    {
        $app = app(Apps::class);

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getById((int) $request['deployment_id'], $app);

        return new UpdateDeploymentConfigAction(
            $deployment,
            (string) $request['config'],
        )->execute();
    }
}
