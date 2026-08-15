<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class PrivatizeConversationMessageActivity extends KanvasActivity implements WorkflowActivityInterface
{
    private const array SUPPORTED_VERBS = ['agent-message', 'message'];

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Message || (int) $entity->apps_id !== $app->getId()) {
            return [
                'message' => 'Entity is not a message from the current app, skipping',
                'result' => false,
            ];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: static function (Message $message): array {
                $verb = $message->messageType?->verb;
                if (! in_array($verb, self::SUPPORTED_VERBS, true)) {
                    return [
                        'message' => 'Message type is not a supported conversation message, skipping',
                        'message_id' => $message->getId(),
                        'verb' => $verb,
                        'result' => false,
                    ];
                }

                $message->is_public = 0;
                $message->is_locked = 1;
                $message->saveQuietly();

                return [
                    'message' => 'Conversation message set to private and locked',
                    'message_id' => $message->getId(),
                    'verb' => $verb,
                    'is_public' => false,
                    'is_locked' => true,
                    'result' => true,
                ];
            },
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
