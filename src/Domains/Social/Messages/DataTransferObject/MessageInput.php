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
    ): self
    {
        if (key_exists('parent_id', $data['input'])) {
            $parent = Message::getById((int)$data['input']['parent_id'], $app);
        }

        return new self(
            $app->getId(),
            $company->getId(),
            $user->getId(),
            $data['input']['message_types_id'],
            $data['input']['message'],
            $parent ? $parent->getId() : 0,
            $data['input']['reactions_count'] ?? 0,
            $data['input']['comments_count'] ?? 0,
            $data['input']['total_liked'] ?? 0,
            $data['input']['total_saved'] ?? 0,
            $data['input']['total_shared'] ?? 0,
            $parent ? $parent->uuid : null
        );
    }
}
