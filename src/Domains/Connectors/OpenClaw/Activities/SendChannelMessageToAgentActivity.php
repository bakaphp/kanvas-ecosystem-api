<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenClaw\Actions\SendChannelMessageToAgentAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SendChannelMessageToAgentActivity extends KanvasActivity
{
    public function execute(Message $entity, Apps $app, array $params): array
    {
        $channelId = (int) ($params['channel_id'] ?? 0);

        if ($channelId === 0) {
            throw new ValidationException('channel_id is required to route a message to an OpenClaw agent');
        }

        /** @var Channel $channel */
        $channel = Channel::getByIdFromCompanyApp($channelId, $entity->company, $app);

        $agentId = (int) ($params['agent_id'] ?? $app->get('openclaw_default_agent_id') ?? 0);

        if ($agentId === 0) {
            throw new ValidationException('agent_id is required (pass in params or set openclaw_default_agent_id on the app)');
        }

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp($agentId, $entity->company, $app);

        /** @var Companies $company */
        $company = $entity->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::OPENCLAW,
            additionalParams: $params,
            integrationOperation: fn (): Message => new SendChannelMessageToAgentAction(
                $agent,
                $entity,
                $channel,
            )->execute(),
            company: $company,
        );
    }
}
