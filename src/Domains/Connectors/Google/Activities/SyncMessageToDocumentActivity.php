<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Google\Actions\SyncMessageToDocumentAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class SyncMessageToDocumentActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

    public function execute(Model $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

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
            'result' => $result,
            'message' => $message->id,
            'slug' => $message->slug ?? $message->uuid,
        ];
    }
}
