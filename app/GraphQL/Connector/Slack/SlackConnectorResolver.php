<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Slack;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Actions\ConnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\DisconnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\GenerateSlackManifestAction;
use Kanvas\Connectors\Slack\Services\SlackConnectionStatusService;
use Kanvas\Intelligence\Agents\Models\Agent;

class SlackConnectorResolver
{
    /**
     * @return array<string, mixed>
     */
    public function manifest(mixed $root, array $request): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        return new GenerateSlackManifestAction($agent)->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(mixed $root, array $request): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = (array) $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        return new ConnectSlackAgentAction(
            agent: $agent,
            botToken: (string) $input['bot_token'],
            signingSecret: (string) $input['signing_secret'],
        )->execute();
    }

    public function disconnect(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        return new DisconnectSlackAgentAction($agent)->execute();
    }

    /**
     * @return array<string, mixed>|null null when the agent isn't listening on Slack
     */
    public function connection(mixed $root, array $request): ?array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        return new SlackConnectionStatusService()->forAgent($agent);
    }
}
