<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WordPress\Actions\PushMessageToWordPressAction;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Connectors\WordPress\RestClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Wire this to the message-created rule so anything an agent writes with a post-shaped body lands
 * on the site. `message_type_id` in the rule params keeps unrelated chat traffic out; the rest of
 * the params are post defaults the message body can override.
 */
#[WorkflowAction(
    name: 'Push Message To WordPress',
    description: 'Publishes a Kanvas message as a post on the WordPress site connected to the message\'s '
        . 'company. Uploads any attached media, resolves categories and tags by name, and re-runs as an '
        . 'update to the same post rather than creating a duplicate.',
    integration: IntegrationsEnum::WORDPRESS,
    requiresConfig: [
        ConfigurationEnum::SITE_URL,
        ConfigurationEnum::USERNAME,
        ConfigurationEnum::APPLICATION_PASSWORD,
    ],
    requiredParams: ['message_type_id'],
    params: [
        'message_type_id' => 'Only messages of these types are published. One id, a list of ids, or a '
            . 'comma-separated string — a newsroom publishing from both email and WhatsApp names both. '
            . 'Leaving it unset does not error — it publishes EVERY message on the channel to the site, '
            . 'which is why it is required here.',
        'status' => 'draft | pending | publish | private | future. Use "pending" when a human should '
            . 'review before it goes live. Defaults to the site\'s configured default.',
        'categories' => 'Category names, not WordPress ids. Created on the site if term creation is allowed.',
        'tags' => 'Tag names, not WordPress ids.',
        'author_id' => 'WordPress user id to attribute the post to.',
    ],
)]
class PushMessageToWordPressActivity extends KanvasActivity
{
    public function execute(
        Message $message,
        Apps $app,
        array $params
    ): array {
        $this->overwriteAppService($app);

        $company = $message->company;

        if (! RestClient::isConfigured($app, $company)) {
            return $this->skip($message, 'WordPress REST credentials are not configured for this company');
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::WORDPRESS,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams): array {
                $messageTypeIds = $this->messageTypeIds($additionalParams['message_type_id'] ?? null);

                if ($messageTypeIds !== [] && ! in_array((int) $message->message_types_id, $messageTypeIds, true)) {
                    return $this->skip(
                        $message,
                        'Message type ' . $message->message_types_id
                            . ' does not match the configured ' . implode(', ', $messageTypeIds)
                    );
                }

                // An inbound message is SOURCE material, never the post. Both sides of a channel
                // conversation share one message type (a Mailgun inbound and the agent's reply are
                // both `mailgun-email`), so type alone would publish the customer's own email —
                // and it would succeed, because `content` is a valid post key. Absent flag =
                // publish: a message created by hand or through the API has no from_me at all.
                $body = $message->getMessage();

                if (array_key_exists('from_me', $body) && ! $body['from_me']) {
                    return $this->skip($message, 'Inbound message, skipping WordPress publish');
                }

                // Kanvas visibility (is_public) is deliberately not consulted — the post's own wp
                // status decides what the site shows, so a private Kanvas message can still ship
                // as a WP draft.
                if ($message->isLocked()) {
                    return $this->skip($message, 'Message is locked, skipping WordPress publish');
                }

                try {
                    return new PushMessageToWordPressAction($message, $additionalParams)->execute();
                } catch (ValidationException $e) {
                    return $this->skip($message, $e->getMessage());
                }
            },
            company: $company,
        );
    }

    /**
     * A rule can name one type or several — a newsroom that publishes what its agent writes over
     * email AND WhatsApp needs both. Accepts an id, an array of ids, or the comma-separated string
     * a plain text rule param gives you.
     *
     * @return list<int>
     */
    private function messageTypeIds(mixed $configured): array
    {
        $values = is_string($configured) ? explode(',', $configured) : (array) $configured;

        $ids = array_map(static fn (mixed $id): int => (int) trim((string) $id), $values);

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Expected business conditions, not faults — flagged FAILED for the integration history without
     * an exception or a Sentry report.
     */
    private function skip(Message $message, string $reason): array
    {
        return $this->failWorkflow([
            'message_id' => $message->getId(),
            'message' => $reason,
        ]);
    }
}
