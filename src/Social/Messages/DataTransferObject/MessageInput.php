<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\DataTransferObject;

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
}
