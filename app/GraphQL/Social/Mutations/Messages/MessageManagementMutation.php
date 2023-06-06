<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Jobs\FillUserMessage;
use Kanvas\Social\Messages\Models\UserMessageActivityType;
use Kanvas\Social\Messages\Repositories\MessageRepository;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;

class MessageManagementMutation
{
    /**
     * create
     *
     * @param  mixed $request
     * @return void
     */
    public function create(mixed $root, array $request)
    {
        $parent = null;
        if (key_exists('parent_id', $request['input'])) {
            $parent = MessageRepository::getById($request['input']['parent_id']);
        }

        $messageType = MessagesTypesRepository::getById($request['input']['message_types_id']);
        $systemModule = SystemModules::getById($request['input']['system_modules_id']);

        $request['input']['parent_id'] = $parent?->id;
        $request['input']['parent_unique_id'] = $parent?->uuid;
        $request['input']['apps_id'] = app(Apps::class)->id;
        $request['input']['companies_id'] = auth()->user()->getCurrentCompany()->getId();
        $request['input']['users_id'] = auth()->user()->id;
        $data = MessageInput::from($request['input']);
        $action = new CreateMessageAction($data, $systemModule, $request['input']['entity_id']);
        $message = $action->execute();
        $activityType = UserMessageActivityType::where('name', 'follow')->firstOrFail();
        $activity = [
            'username' => '',
            'entity_namespace' => '',
            'text' => ' ',
            'type' => $activityType->id,
        ];

        FillUserMessage::dispatch($message, $activity, $message->user)->onQueue('message');

        return $message;
    }
}
