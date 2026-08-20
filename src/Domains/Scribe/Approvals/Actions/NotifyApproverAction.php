<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Client as SlackClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Approvals\Enums\ApprovalConfigurationEnum;
use Throwable;

/**
 * Best-effort Slack DM to the configured approver when something lands in the approval queue.
 * Silently does nothing when Slack isn't configured, and never lets a Slack failure block the
 * record it's notifying about — that record is already safely created by the time this runs.
 */
class NotifyApproverAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly string $text,
    ) {
    }

    public function execute(): void
    {
        $slackUserId = (string) ($this->app->get(ApprovalConfigurationEnum::APPROVER_SLACK_USER_ID->value) ?? '');
        $agentId = (string) ($this->app->get(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value) ?? '');

        if ($slackUserId === '' || $agentId === '') {
            return;
        }

        try {
            $agent = Agent::find((int) $agentId);

            if ($agent === null) {
                return;
            }

            $client = SlackClient::getInstanceByAgent($agent);
            $dmChannel = $client->openDirectMessageChannel($slackUserId);
            $client->postMessage($dmChannel, $this->text);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
