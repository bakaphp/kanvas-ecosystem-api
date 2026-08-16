<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Slack;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Slack\Actions\ConnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\DisconnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\GenerateSlackManifestAction;
use Kanvas\Connectors\Slack\Actions\SetSlackChannelListeningAction;
use Kanvas\Connectors\Slack\Services\SlackConnectionStatusService;
use Kanvas\Intelligence\Agents\Models\Agent;

class SlackConnectorResolver
{
    use ResolvesActingContext;

    /**
     * @return array<string, mixed>
     */
    public function manifest(mixed $root, array $request): array
    {
        return new GenerateSlackManifestAction(
            $this->agent((int) $request['agent_id'])
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(mixed $root, array $request): array
    {
        $input = (array) $request['input'];

        return new ConnectSlackAgentAction(
            agent: $this->agent((int) $input['agent_id']),
            botToken: (string) $input['bot_token'],
            signingSecret: (string) $input['signing_secret'],
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function listenToAllChannels(mixed $root, array $request): array
    {
        return new SetSlackChannelListeningAction(
            agent: $this->agent((int) $request['agent_id']),
            enabled: (bool) $request['enabled'],
            joinExistingChannels: (bool) ($request['join_existing_channels'] ?? true),
        )->execute();
    }

    public function disconnect(mixed $root, array $request): bool
    {
        return new DisconnectSlackAgentAction(
            $this->agent((int) $request['agent_id'])
        )->execute();
    }

    /**
     * @return array<string, mixed>|null null when the agent isn't listening on Slack
     */
    public function connection(mixed $root, array $request): ?array
    {
        return new SlackConnectionStatusService()->forAgent(
            $this->agent((int) $request['agent_id'])
        );
    }

    private function agent(int $agentId): Agent
    {
        $ctx = $this->actingContext();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp($agentId, $ctx->company, $ctx->app);

        return $agent;
    }
}
