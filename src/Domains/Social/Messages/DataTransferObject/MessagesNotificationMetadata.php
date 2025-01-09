<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\DataTransferObject;

use Kanvas\Notifications\Enums\NotificationDistributionEnum;
use Kanvas\Social\Messages\Models\Message;
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
        public array $message,
        public array $usersId = []
    ) {
    }

    /**
     * Generate new instance of DTO from array.
     */
    public static function fromArray(array $request): self
    {
        return new self(
            notificationTypeId: (int) $request['metadata']['notification_type_id'],
            distributionType: $request['metadata']['distribution']['type'],
            message: $request['message'],
            usersId: $request['metadata']['distribution']['users_id'] ?? [],
        );
    }

    public function distributeToSpecificUsers(): bool
    {
        return $this->distributionType === NotificationDistributionEnum::USERS->value && count($this->usersId) > 0;
    }

    public function distributeToFollowers(): bool
    {
        return $this->distributionType === NotificationDistributionEnum::FOLLOWERS->value;
    }
}
