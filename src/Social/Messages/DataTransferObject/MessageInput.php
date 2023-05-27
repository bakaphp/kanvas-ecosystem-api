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
        public ?int $parent_id = null,
        public ?int $parent_unique_id = null,
        public int $apps_id,
        public int $companies_id,
        public int $users_id,
        public int $message_types_id,
        public mixed $message,
        public ?int $reactions_count = null,
        public ?int $comments_count = null,
        public ?int $total_liked = null,
        public ?int $total_saved = null,
        public ?int $total_shared = null
    ) {
    }
}
