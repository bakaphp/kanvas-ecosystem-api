<?php
declare(strict_types=1);

namespace Kanvas\Guild\Leads\Workflows\CustomFields\Activities;

use Illuminate\Database\Eloquent\Model;
use Baka\Contracts\AppInterface;
use Workflow\Activity;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\Messages\Actions\CreateMessageAction;

class DefaultMessageActivity extends Activity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
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

        foreach($entity->getAllCustomFields() as $customField) {
            if ($customField->name === 'message') {
                $message = MessageInput::from(
                    [
                    'app' => $app,
                    'company' => $entity->company,
                    'user' => $entity->user,
                    'type' => $messageType,
                    'message' => $customField->value
                    ]
                );
                (new CreateMessageAction($message))->execute();
            }
        }
        return [
            'message' => 'Default message activity executed',
        ];
    }
}
