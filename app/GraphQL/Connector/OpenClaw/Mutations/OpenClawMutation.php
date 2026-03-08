<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\GetGatewayLogsAction;
use Kanvas\Connectors\OpenClaw\Actions\GetUsageAction;
use Kanvas\Connectors\OpenClaw\Actions\ListAgentsAction;
use Kanvas\Connectors\OpenClaw\Actions\RestartGatewayAction;

class OpenClawMutation
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
}
