<?php

declare(strict_types=1);

namespace Kanvas\Notifications\DataTransferObject;

use Spatie\LaravelData\Data;

class Notifications extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public int $users_id,
        public int $from_users_id,
        public int $companies_id,
        public int $apps_id,
        public int $system_modules_id,
        public int $notification_type_id,
        public int $entity_id,
        public string $content,
        public int $read,
        public ?string $content_group = null,
    ) {
    }
}
