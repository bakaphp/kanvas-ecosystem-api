<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\DistributeChannelAction;
use Kanvas\Social\Messages\Actions\DistributeToUsers;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Enums\ActivityTypeEnum;
use Kanvas\Social\Messages\Enums\DistributionTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;

class MessageManagementMutation
{
    public function interaction(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id']);
        $action = new CreateMessageAction($message, auth()->user(), ActivityTypeEnum::from($request['type']));
        $action->execute();

        return $message;
    }

    /**
     * create
     */
    public function create(mixed $root, array $request): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $messageData = $request['input'];
        $messageType = MessagesTypesRepository::getByVerb($messageData['message_verb'], $app);
        $systemModule = key_exists('system_modules_id', $messageData) ? SystemModules::getById((int)$messageData['system_modules_id'], $app) : null;
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
        $message = Message::getById((int)$request['id'], app(Apps::class));
        if (! $message->canEdit(auth()->user())) {
            throw new AuthenticationException('You are not allowed to edit this message');
        }
        $message->update($request['input']);

        return $message;
    }

    public function attachTopicToMessage(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(Apps::class));
        $message->topics()->attach($request['topicId']);

        return $message;
    }

    public function detachTopicToMessage(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(Apps::class));
        $message->topics()->detach($request['topicId']);

        return $message;
    }
}
