<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesComments\Actions;

use Kanvas\Social\MessagesComments\DataTransferObject\MessageComment as CommentsDto;
use Kanvas\Social\MessagesComments\Models\MessageComment;

class CreateComment
{
    public function __construct(
        protected CommentsDto $commentsDto
    ) {
    }

    public function execute(): MessageComment
    {
        $comment = MessageComment::firstOrCreate([
            'apps_id' => $this->commentsDto->apps->id,
            'companies_id' => $this->commentsDto->companies->id,
            'users_id' => $this->commentsDto->users->id,
            'message' => $this->commentsDto->message,
            'parent_id' => $this->commentsDto->parentId,
            'message_id' => $this->commentsDto->messages->id,
        ]);

        return $comment;
    }
}
