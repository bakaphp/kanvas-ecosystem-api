<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Messages\Actions\CheckMessageContentAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

class CheckMessageContentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        if ((new CheckMessageContentAction($entity->message, $app))->execute()) {
            $entity->is_public = 0;
            $entity->set('is_nsfw', 1);
            $entity->save();
            return [
                'message' => 'Message content is not allowed, message has been set to private',
                'is_public' => false,
                'message_id' => $entity->message,
            ];
        }

        return [
            'message' => 'No issues found in message content',
            'message_id' => $entity->getId(),
        ];
    }
}
