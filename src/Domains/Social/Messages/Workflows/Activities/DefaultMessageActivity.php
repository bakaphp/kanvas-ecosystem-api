<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class DefaultMessageActivity extends Activity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        if (! key_exists('customsFields', $params)) {
            throw new Exception('Custom fields are required');
        }

        try {
            $messageType = MessagesTypesRepository::getByVerb('system-message', $app);
        } catch (ModelNotFoundException $e) {
            $messageTypeDto = MessageTypeInput::from([
                'apps_id' => $app->getId(),
                'name' => 'system-message',
                'verb' => 'system-message',
            ]);
            $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();
        }
        $messages = [];
        foreach ($params['customsFields'] as $customField) {
            $messageContent = $entity->get($customField);
            if (empty($messageContent)) {
                continue;
            }
            $data = MessageInput::from(
                [
                    'app' => $app,
                    'company' => $entity->company,
                    'user' => $entity->user,
                    'type' => $messageType,
                    'message' => $messageContent,
                ]
            );
            $message[] = (new CreateMessageAction($data))->execute();
        }

        return [
            'message' => 'Default message activity executed',
            'messages' => $messages,
        ];
    }
}
