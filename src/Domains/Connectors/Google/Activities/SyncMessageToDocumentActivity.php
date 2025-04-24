<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Google\Actions\SyncMessageToDocumentAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

class SyncMessageToDocumentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $messageType = $params['message_type_id'] ?? null;

        if ($messageType !== null) {
            if ((int) $message->message_types_id !== (int) $messageType) {
                return [
                    'result'  => false,
                    'message' => 'Message type does not match the expected '.$messageType.' but found '.$message->message_types_id,
                    'id'      => $message->id,
                ];
            }
        }

        $syncMessageToDocument = new SyncMessageToDocumentAction(
            $app,
            $message->company,
            $message->user
        );

        $result = $syncMessageToDocument->execute(
            $message->messageType,
            [$message->id]
        );

        return [
            'result'  => $result,
            'message' => $message->id,
            'slug'    => $message->slug ?? $message->uuid,
        ];
    }
}
