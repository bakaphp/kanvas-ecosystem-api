<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Social\Messages\Repositories\MessageRepository;
use Kanvas\Social\MessagesComments\Actions\CreateMessageComment;
use Kanvas\Social\MessagesComments\DataTransferObject\MessageComment as CommentsDto;
use Kanvas\Social\MessagesComments\Models\MessageComment;

class MessageCommentManagementMutation
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
}
