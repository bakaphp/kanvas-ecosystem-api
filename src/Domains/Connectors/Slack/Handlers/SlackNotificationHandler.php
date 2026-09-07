<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Slack\Enums\NotificationConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackNotificationService;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Connects the generic "send a Slack notification" integration through the shared
 * `integrationCompany` mutation. Distinct from the Slack agent/listener install flow
 * (`Actions\ConnectSlackAgentAction` / `ConnectSlackListenerAction`), which provisions a full
 * two-way conversational channel — this only needs enough to push one-way alerts.
 *
 * At least one of `webhook_url` / `bot_token` is required; both may be set so a rule can choose
 * per call. Credentials are company-scoped: each tenant configures its own destination.
 */
class SlackNotificationHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $webhookUrl = trim((string) ($this->data['webhook_url'] ?? ''));
        $botToken = trim((string) ($this->data['bot_token'] ?? ''));
        $defaultChannel = trim((string) ($this->data['default_channel'] ?? ''));

        if ($webhookUrl === '' && $botToken === '') {
            throw new ValidationException(
                'Provide a Slack Incoming Webhook URL or a Bot User OAuth Token to connect Slack notifications.'
            );
        }

        if ($webhookUrl !== '') {
            SlackNotificationService::validateWebhook($webhookUrl);
            $this->company->set(NotificationConfigurationEnum::WEBHOOK_URL->value, $webhookUrl);
        }

        if ($botToken !== '') {
            SlackNotificationService::validateBotToken($botToken);
            $this->company->set(NotificationConfigurationEnum::BOT_TOKEN->value, $botToken);
        }

        if ($defaultChannel !== '') {
            $this->company->set(NotificationConfigurationEnum::DEFAULT_CHANNEL->value, $defaultChannel);
        }

        return true;
    }
}
