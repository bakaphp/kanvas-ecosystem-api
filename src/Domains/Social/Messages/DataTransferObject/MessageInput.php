<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Social\Messages\Models\Message;
use Spatie\LaravelData\Data;

class MessageInput extends Data
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public int $apps_id,
        public int $companies_id,
        public int $users_id,
        public int $message_types_id,
        public mixed $message = '',
        public int $parent_id = 0,
        public ?int $reactions_count = 0,
        public ?int $comments_count = 0,
        public ?int $total_liked = 0,
        public ?int $total_saved = 0,
        public ?int $total_shared = 0,
        public ?string $parent_unique_id = null,
    ) {
    }

    public static function fromArray(
        array $data,
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): self {
        $parent = null;
        if (key_exists('parent_id', $data)) {
            $parent = Message::getById((int)$data['parent_id'], $app);
        }

        return new self(
            $app->getId(),
            $company->getId(),
            $user->getId(),
            (int) $data['message_types_id'],
            $data['message'],
            $parent ? $parent->getId() : 0,
            $data['reactions_count'] ?? 0,
            $data['comments_count'] ?? 0,
            $data['total_liked'] ?? 0,
            $data['total_saved'] ?? 0,
            $data['total_shared'] ?? 0,
            $parent ? $parent->uuid : null
        );
    }
}
