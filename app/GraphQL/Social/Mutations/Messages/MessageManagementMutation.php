<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\DistributeChannelAction;
use Kanvas\Social\Messages\Actions\DistributeToUsers;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Enums\ActivityTypeEnum;
use Kanvas\Social\Messages\Enums\DistributionTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessageRepository;
use Kanvas\Social\MessagesComments\Actions\CreateMessageComment;
use Kanvas\Social\MessagesComments\DataTransferObject\MessageComment as CommentsDto;
use Kanvas\Social\MessagesComments\Models\MessageComment;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;

class MessageManagementMutation
{
    public function addComment(mixed $root, array $request): Message
    {
        $message = MessageRepository::getById((int)$request['input']['message_id']);

        $parentId = key_exists('parent_id', $request['input']) ? MessageComment::getById($request['input']['parent_id'])->id : 0;

        $dto = CommentsDto::from([
            'apps' => app(Apps::class),
            'companies' => auth()->user()->getCurrentCompany(),
            'users' => auth()->user(),
            'messages' => $message,
            'message' => $request['input']['message'],
            'parent_id' => $parentId,
        ]);

        (new CreateMessageComment($dto))->execute();

        return $message;
    }

    public function updateComment(mixed $root, array $request): Message
    {
        $comment = MessageComment::getById($request['comment_id']);
        if($comment->users_id != auth()->user()->id) {
            throw new Exception('You are not allowed to update this comment');
        }
        $parentId = key_exists('parent_id', $request['input']) ? MessageComment::getById($request['input']['parent_id'])->id : $comment->parent_id;

        $comment->update([
            'message' => $request['input']['message'],
            'parent_id' => $parentId,
        ]);

        return $comment->messages;
    }

    public function interaction(mixed $root, array $request): Message
    {
        $message = MessageRepository::getById((int)$request['id']);
        $action = new CreateMessageAction($message, auth()->user(), ActivityTypeEnum::from($request['type']));
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

        if (! key_exists('distribution', $request['input'])) {
            return $message;
        }
        $distributionType = DistributionTypeEnum::from($request['input']['distribution']['distributionType']);

        if ($distributionType->value == DistributionTypeEnum::ALL->value) {
            $channels = key_exists('channels', $request['input']['distribution']) ? $request['input']['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, auth()->user()))->execute();
            (new DistributeToUsers($message))->execute();
        } elseif ($distributionType->value == DistributionTypeEnum::Channels->value) {
            $channels = key_exists('channels', $request['input']['distribution']) ? $request['input']['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, auth()->user()))->execute();
        } elseif ($distributionType->value == DistributionTypeEnum::Followers->value) {
            (new DistributeToUsers($message))->execute();
        }

        return $message;
    }
}
