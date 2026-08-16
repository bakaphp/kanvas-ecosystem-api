<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WordPress\Actions\PushMessageToWordPressAction;
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
#[WorkflowAction]
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
