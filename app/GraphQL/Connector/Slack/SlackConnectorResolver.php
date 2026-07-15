<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Slack;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Actions\ConnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\DisconnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\GenerateSlackManifestAction;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Ship an agent as its own Slack app. Two steps for the client:
 *   1. slackAgentManifest(agent_id) → open install_url, the customer creates the app in their
 *      workspace and installs it
 *   2. connectSlackAgent(...) with the bot token + signing secret they copy back
 */
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

        return new GenerateSlackManifestAction(
            agent: $agent,
            app: $app,
            company: $company,
            user: $user,
        )->execute();
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
            app: $app,
            company: $company,
            user: $user,
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

        return new DisconnectSlackAgentAction($agent, $app)->execute();
    }
}
