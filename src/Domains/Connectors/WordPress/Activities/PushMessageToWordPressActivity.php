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
        'message_type_id' => 'Only messages of this type are published. Leaving it unset does not error — '
            . 'it publishes EVERY message on the channel to the site, which is why it is required here.',
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
                $messageTypeId = $additionalParams['message_type_id'] ?? null;

                if ($messageTypeId !== null && $message->message_types_id !== (int) $messageTypeId) {
                    return $this->skip(
                        $message,
                        'Message type ' . $message->message_types_id . ' does not match the configured ' . $messageTypeId
                    );
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
