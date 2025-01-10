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
        public ?array $entity_content = null,
        public ?string $content_group = null,
    ) {
    }

    /**
     * Generate new instance of DTO from array.
     */
    public static function fromArray(array $request): self
    {
        return new self(
            users_id: $request['users_id'],
            from_users_id: $request['from_users_id'],
            companies_id: $request['companies_id'],
            apps_id: $request['apps_id'],
            system_modules_id: $request['system_modules_id'],
            notification_type_id: $request['notification_type_id'],
            entity_id: $request['entity_id'],
            content: $request['content'],
            read: $request['read'],
            content_group: $request['content_group'] ?? null,
            entity_content: $request['entity_content'] ?? null,
        );
    }
}
