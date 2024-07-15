<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesComments\Actions;

use Illuminate\Support\Facades\Validator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Validations\ValidParentComment;
use Kanvas\Social\MessagesComments\DataTransferObject\MessageComment as CommentsDto;
use Kanvas\Social\MessagesComments\Models\MessageComment;

class CreateMessageComment
{
    public function __construct(
        protected CommentsDto $commentsDto
    ) {
    }

    public function execute(): MessageComment
    {
        $validator = Validator::make($this->commentsDto->toArray(), [
            'parent_id' => [new ValidParentComment($this->commentsDto->app->getId())],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        $data = [
            'apps_id' => $this->commentsDto->app->getId(),
            'companies_id' => $this->commentsDto->company->getId(),
            'users_id' => $this->commentsDto->user->getId(),
            'message' => $this->commentsDto->comment,
            'parent_id' => $this->commentsDto->parent_id,
            'message_id' => $this->commentsDto->message->getId(),
        ];

        if ($this->commentsDto->parent_id == null || $this->commentsDto->parent_id == 0) {
            $data['parent_id'] = null;
        }

        return MessageComment::create($data);
    }
}
