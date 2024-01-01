<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\DataTransferObject;

use Kanvas\Notifications\Enums\NotificationDistributionEnum;
use Spatie\LaravelData\Data;

class MessagesNotificationMetadata extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public int $notificationTypeId,
        public string $distributionType,
        public ?int $followerId,
        public array $message,
    ) {
    }

    /**
     * Generate new instance of DTO from array.
     */
    public static function fromArray(array $request): self
    {
        return new self(
            notificationTypeId: $request['metadata']['notification_type_id'],
            distributionType: $request['metadata']['distribution']['type'],
            followerId: $request['metadata']['distribution']['follower_id'] ?? null,
            message: $request['message'],
        );
    }

    public function distributeToOneFollower(): bool
    {
        return $this->distributionType === NotificationDistributionEnum::ONE->value && $this->followerId > 0;
    }

    public function distributeToFollowers(): bool
    {
        return $this->distributionType === NotificationDistributionEnum::FOLLOWERS->value;
    }
}
