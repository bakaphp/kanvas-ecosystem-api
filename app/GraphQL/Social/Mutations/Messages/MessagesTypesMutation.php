<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Languages\Models\Languages;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;

class MessagesTypesMutation
{
    public function create(mixed $root, array $request): MessageType
    {
        Languages::getById($request['input']['languages_id']);
        $request['input']['apps_id'] = app(Apps::class)->id;
        $messageTypeInput = MessageTypeInput::from($request['input']);
        $createMessageTypesAction = new CreateMessageTypeAction(
            $messageTypeInput
        );

        return $createMessageTypesAction->execute();
    }

    public function update(mixed $root, array $request): MessageType
    {
        $app = app(Apps::class);
        Languages::getById($request['input']['languages_id']);
        $messageType = MessagesTypesRepository::getById($request['id'], $app);
        $messageType->update($request['input']);

        return $messageType;
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);

        /** @var MessageType $messageType */
        $messageType = MessageType::getById((int) $request['id'], $app);

        if ($messageType->hasMessages()) {
            throw new ValidationException('Cannot delete a message type that has been used by messages');
        }

        return $messageType->softDelete();
    }
}
