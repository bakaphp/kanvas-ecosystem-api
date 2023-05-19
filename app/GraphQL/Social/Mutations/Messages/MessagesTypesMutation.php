<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use  Kanvas\Languages\Models\Languages;
use Kanvas\Social\Messages\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\Messages\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\Messages\MessagesTypes\Models\MessageType;
use Kanvas\Social\Messages\MessagesTypes\Repositories\MessagesTypesRepository;

class MessagesTypesMutation
{
    /**
     * create
     *
     * @param  mixed $request
     */
    public function create(mixed $root, array $request): MessageType
    {
        Languages::findOrFail($request['input']['languages_id']);
        $request['input']['apps_id'] = app(Apps::class)->id;
        $messageTypeInput = MessageTypeInput::from($request['input']);
        $createMessageTypesAction = new CreateMessageTypeAction(
            $messageTypeInput
        );

        return $createMessageTypesAction->execute();
    }

    /**
     * update
     *
     * @param  mixed $request
     */
    public function update(mixed $root, array $request): MessageType
    {
        Languages::findOrFail($request['input']['languages_id']);
        $messageType = MessagesTypesRepository::getById($request['id']);
        $messageType->update($request['input']);
        
        return $messageType;
    }
}
