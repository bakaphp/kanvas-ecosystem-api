<?php

declare(strict_types=1);

namespace Kanvas\Social\DataTransferObject;

use Spatie\LaravelData\Data;

class MessagesNotificationsPayloadDto extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public string $verb,
        public string $event,
        public array $channels,
        public string $type,
        public ?int $follower_id,
        public array $message,
    ) {
    }

    /**
     * Generate new instance of DTO from array.
     */
    public static function fromArray(array $request): self
    {
        return new self(
            verb: $request['metadata']['verb'],
            event: $request['metadata']['event'],
            channels: $request['metadata']['channels'],
            type: $request['metadata']['distribution']['type'],
            follower_id: $request['metadata']['distribution']['followerId'] ?? null,
            message: $request['message'],
        );
    }
}
