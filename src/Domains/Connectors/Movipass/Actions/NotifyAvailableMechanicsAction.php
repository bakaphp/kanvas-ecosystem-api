<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Notifications\PendingOrderAssignmentNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

class NotifyAvailableMechanicsAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly AppInterface $app,
        protected readonly UserInterface $user,
        protected readonly array $excludeIds = [],
    ) {
    }

    public function execute(): void
    {
        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        $mechanics = new GetAvailableMechanicsAction($this->excludeIds)->execute();

        if ($mechanics->isEmpty()) {
            throw new ValidationException('No available mechanics to notify for this order');
        }

        $assistanceCase['notified_mechanic_ids'] = $mechanics->pluck('id')->toArray();

        $this->order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...($metadata['data'] ?? []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $this->order->saveQuietly();

        $notification = new PendingOrderAssignmentNotification($this->order);

        foreach ($mechanics as $mechanic) {
            $mechanic->notify($notification);
        }

        $this->order->transitionToStatus(
            $this->user,
            MovipassOrderStatusEnum::AWAITING_OPERATOR->slug(),
        );
    }
}
