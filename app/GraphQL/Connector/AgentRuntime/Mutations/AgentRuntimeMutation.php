<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\AgentRuntime\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\AgentRuntime\Actions\GetGatewayLogsAction;
use Kanvas\Connectors\AgentRuntime\Actions\GetHealthAction;
use Kanvas\Connectors\AgentRuntime\Actions\GetUsageAction;
use Kanvas\Connectors\AgentRuntime\Actions\ListAgentsAction;
use Kanvas\Connectors\AgentRuntime\Actions\RestartGatewayAction;

class AgentRuntimeMutation
{
    public function restartGateway(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new RestartGatewayAction($app, $company)->execute();
    }

    public function gatewayLogs(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $lines = (int) ($req['lines'] ?? 100);

        return new GetGatewayLogsAction($app, $company, $lines)->execute();
    }

    public function listAgents(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $result = new ListAgentsAction($app, $company)->execute();

        return (string) json_encode($result);
    }

    public function usage(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new GetUsageAction($app, $company)->execute();
    }

    public function health(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new GetHealthAction($app, $company)->execute();
    }
}
