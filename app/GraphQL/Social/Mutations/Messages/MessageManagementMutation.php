<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Enums\DistributionTypeEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\DistributeChannelAction;
use Kanvas\Social\Messages\Actions\DistributeToUsers;
use Kanvas\Social\Messages\Actions\InteractionMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Enums\ActivityTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessageRepository;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;

class MessageManagementMutation
{
    public function interaction(mixed $root, array $request): Message
    {
        $message = MessageRepository::getById((int)$request['id']);
        $action = new InteractionMessageAction($message, auth()->user(), ActivityTypeEnum::from($request['type']));
        $userMessage = $action->execute();

        return $message;
    }

    /**
     * create
     *
     * @param  mixed $request
     * @return void
     */
    public function create(mixed $root, array $request): Message
    {
        $parent = null;
        if (key_exists('parent_id', $request['input'])) {
            $parent = MessageRepository::getById((int)$request['input']['parent_id']);
        }

        $messageType = MessagesTypesRepository::getById((int)$request['input']['message_types_id']);
        $systemModule = SystemModules::getById((int)$request['input']['system_modules_id']);

        $request['input']['parent_id'] = $parent ? $parent->id : 0;
        $request['input']['parent_unique_id'] = $parent?->uuid;
        $request['input']['apps_id'] = app(Apps::class)->id;
        $request['input']['companies_id'] = auth()->user()->getCurrentCompany()->getId();
        $request['input']['users_id'] = auth()->user()->id;
        $data = MessageInput::from($request['input']);
        $action = new CreateMessageAction($data, $systemModule, $request['input']['entity_id']);
        $message = $action->execute();

        if(! key_exists('distribution', $request['input'])) {
            return $message;
        }
        $distributionType = DistributionTypeEnum::from($request['input']['distribution']['distributionType']);

        if($distributionType->value == DistributionTypeEnum::ALL->value) {
            $channels = key_exists('channels', $request['input']['distribution']) ? $request['input']['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, auth()->user()))->execute();
            (new DistributeToUsers($message))->execute();

        } elseif($distributionType->value == DistributionTypeEnum::Channels->value) {
            $channels = key_exists('channels', $request['input']['distribution']) ? $request['input']['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, auth()->user()))->execute();
        } elseif($distributionType->value == DistributionTypeEnum::Users->value) {
            (new DistributeToUsers($message))->execute();
        }

        return $message;

    }
}
