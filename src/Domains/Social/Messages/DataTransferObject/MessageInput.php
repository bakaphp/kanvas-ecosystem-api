<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Validator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\ValidParentMessage;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Spatie\LaravelData\Data;

class MessageInput extends Data
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public CompanyInterface $company,
        public UserInterface $user,
        public MessageType $type,
        public mixed $message = '',
        public ?int $parent_id = null,
        public ?int $reactions_count = 0,
        public ?int $comments_count = 0,
        public ?int $total_liked = 0,
        public ?int $total_saved = 0,
        public ?int $total_shared = 0,
        public ?string $parent_unique_id = null,
        public array $tags = []
    ) {
    }

    public static function fromArray(
        array $data,
        UserInterface $user,
        MessageType $type,
        CompanyInterface $company,
        AppInterface $app
    ): self {
        $parent = null;

        $validator = Validator::make($data, [
            'parent_id' => [new ValidParentMessage($app->getId())],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        if (key_exists('parent_id', $data)) {
            $parent = Message::getById((int)$data['parent_id'], $app);
        }

        return new self(
            $app,
            $company,
            $user,
            $type,
            $data['message'],
            $parent ? $parent->getId() : 0,
            $data['reactions_count'] ?? 0,
            $data['comments_count'] ?? 0,
            $data['total_liked'] ?? 0,
            $data['total_saved'] ?? 0,
            $data['total_shared'] ?? 0,
            $parent ? $parent->uuid : null,
            $data['tags'] ?? []
        );
    }
}
