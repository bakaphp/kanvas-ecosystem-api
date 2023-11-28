<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\DistributeChannelAction;
use Kanvas\Social\Messages\Actions\DistributeToUsers;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Enums\ActivityTypeEnum;
use Kanvas\Social\Messages\Enums\DistributionTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessageRepository;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

class MessageManagementMutation
{
    public function interaction(mixed $root, array $request): Message
    {
        $message = MessageRepository::getById((int)$request['id']);
        $action = new CreateMessageAction($message, auth()->user(), ActivityTypeEnum::from($request['type']));
        $action->execute();

        return $message;
    }

    /**
     * create
     *
     * @param array $request
     */
    public function create(mixed $root, array $request): Message
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        /** @var array */
        $messageData = $request['input'];

        $messageType = MessagesTypesRepository::getById((int)$messageData['message_types_id'], $app);

        /** @var SystemModules $systemModule */
        $systemModule = SystemModules::getById((int)$messageData['system_modules_id'], $app);

        $data = MessageInput::fromArray($messageData, $user, $messageType, $company, $app);
        $action = new CreateMessageAction($data, $systemModule, $messageData['entity_id']);
        $message = $action->execute();

        if (! key_exists('distribution', $messageData)) {
            return $message;
        }

        $distributionType = DistributionTypeEnum::from($messageData['distribution']['distributionType']);

        if ($distributionType->value == DistributionTypeEnum::ALL->value) {
            $channels = key_exists('channels', $messageData['distribution']) ? $messageData['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, $user))->execute();
            (new DistributeToUsers($message))->execute();
        } elseif ($distributionType->value == DistributionTypeEnum::Channels->value) {
            $channels = key_exists('channels', $messageData['distribution']) ? $messageData['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, $user))->execute();
        } elseif ($distributionType->value == DistributionTypeEnum::Followers->value) {
            (new DistributeToUsers($message))->execute();
        }

        return $message;
    }

    public function update(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(App::class));
        $message->update($request['input']);

        return $message;
    }

    public function attachTopicToMessage(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(App::class));
        $message->topics()->attach($request['topicId']);

        return $message;
    }

    public function detachTopicToMessage(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(App::class));
        $message->topics()->detach($request['topicId']);

        return $message;
    }
}
