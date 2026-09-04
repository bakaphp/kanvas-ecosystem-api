<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Enums;

/**
 * Config keys for the generic "send a notification to Slack" integration
 * (`Kanvas\Connectors\Slack\Handlers\SlackNotificationHandler`).
 *
 * Kept separate from `ConfigurationEnum` — that one holds the agent/listener install state
 * (signing secret, bot user id, joined channels...); this one is the small, company-scoped
 * set of credentials a tenant pastes in through the generic `integrationCompany` mutation to
 * push plain notifications (workflow rules, alerts) into a Slack channel.
 */
enum NotificationConfigurationEnum: string
{
    // An Incoming Webhook URL. Simplest path — no bot install required, posts to the single
    // channel the webhook was created for.
    case WEBHOOK_URL = 'slack_notification_webhook_url';

    // A Bot User OAuth Token (xoxb-...), used with `chat.postMessage` when the tenant wants to
    // choose the destination channel per call instead of being pinned to one webhook.
    case BOT_TOKEN = 'slack_notification_bot_token';

    // Fallback channel (id like `C0123456789` or name like `#general`) used when a caller does
    // not pass one explicitly. Only meaningful with BOT_TOKEN — a webhook is already bound to a
    // channel on Slack's side.
    case DEFAULT_CHANNEL = 'slack_notification_default_channel';
}
