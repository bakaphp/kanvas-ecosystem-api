<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Workflows;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PushMessageToItemActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param \Kanvas\Social\Messages\Models\Message $message
     */
    #[Override]
    public function execute(Model $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $messageType = $params['message_type_id'] ?? null;

        if ($messageType !== null) {
            if ($message->message_types_id !== (int) $messageType) {
                return [
                    'result' => false,
                    'message' => 'Message type does not match the expected ' . $messageType . ' but found ' . $message->message_types_id,
                    'id' => $message->id,
                ];
            }
        }

        try {
            $messageIndex = new RecombeeIndexService($app);
            $messageIndex->createPromptMessageDatabase();

            $result = $messageIndex->indexPromptMessage($message);
        } catch (Exception $e) {
            return [
                'result' => false,
                'message' => $e->getMessage(),
                'id' => $message->id,
            ];
        }

        return [
            'result' => $result,
            'message' => $message->id,
            'slug' => $message->slug ?? $message->uuid,
        ];
    }
}
