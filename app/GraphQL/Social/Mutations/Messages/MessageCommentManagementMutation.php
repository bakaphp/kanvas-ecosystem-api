<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Illuminate\Support\Facades\Validator;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\ValidParentComment;
use Kanvas\Social\MessagesComments\Actions\CreateMessageComment;
use Kanvas\Social\MessagesComments\DataTransferObject\MessageComment as CommentsDto;
use Kanvas\Social\MessagesComments\Models\MessageComment;

class MessageCommentManagementMutation
{
    public function addComment(mixed $root, array $request): MessageComment
    {
        $message = Message::getById((int)$request['input']['message_id'], app(Apps::class));

        $parentId = key_exists('parent_id', $request['input']) ? MessageComment::getById($request['input']['parent_id'])->id : 0;

        $dto = CommentsDto::from([
            'app' => app(Apps::class),
            'company' => auth()->user()->getCurrentCompany(),
            'user' => auth()->user(),
            'message' => $message,
            'comment' => $request['input']['comment'],
            'parent_id' => $parentId,
        ]);

        return (new CreateMessageComment($dto))->execute();

        return $message;
    }

    public function updateComment(mixed $root, array $request): MessageComment
    {
        $comment = MessageComment::getById($request['comment_id'], app(Apps::class));
        $user = auth()->user();

        if ($comment->canEdit($user)) {
            throw new AuthenticationException('You are not allowed to update this comment');
        }

        $validator = Validator::make($request, [
            'parent_id' => [new ValidParentComment($comment->app->getId())],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        $parentId = key_exists('parent_id', $request['input']) ? MessageComment::getById($request['input']['parent_id'])->id : $comment->parent_id;

        $comment->update([
            'message' => $request['input']['comment'],
            'parent_id' => $parentId,
        ]);

        return $comment;
    }

    public function delete(mixed $root, array $request): bool
    {
        $comment = MessageComment::getById($request['id'], app(Apps::class));
        if (! $comment->canDelete(auth()->user())) {
            throw new AuthenticationException('You are not allowed to delete this message');
        }

        return $comment->delete();
    }
}
