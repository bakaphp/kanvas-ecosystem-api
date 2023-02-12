<?php

declare(strict_types=1);

namespace Kanvas\Notifications\DataTransferObject;

class Notifications
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
        public string $created_at,
        public ?string $updated_at = null,
        public int $is_deleted,
        public ?string $content_group = null,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['users_id'],
            $data['from_users_id'],
            $data['companies_id'],
            $data['apps_id'],
            $data['system_modules_id'],
            $data['notification_type_id'],
            $data['entity_id'],
            $data['content'],
            $data['read'],
            $data['created_at'],
            $data['updated_at'] ?? null,
            $data['is_deleted'],
            $data['content_group'] ?? null,
        );
    }
}
