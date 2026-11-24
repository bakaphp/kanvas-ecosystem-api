<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * Spec v6.0 §9.1: without availability on a technician receives no orders, and going offline with a
 * case in hand needs the cabin, so the APK cannot drop a running service by toggling off.
 */
class SetRoadsideTechnicianAvailabilityAction
{
    public function __construct(
        private readonly Users $technician,
        private readonly AppInterface $app,
        private readonly bool $online,
    ) {
    }

    public function execute(): MechanicAvailabilityEnum
    {
        if (! $this->online && $this->activeOrder() !== null) {
            throw new ValidationException('You cannot go offline with an order in progress; contact the cabin');
        }

        $status = $this->online ? MechanicAvailabilityEnum::ACTIVO : MechanicAvailabilityEnum::NO_DISPONIBLE;
        new UpdateMechanicAvailabilityAction($this->technician, $status)->execute();

        return $status;
    }

    private function activeOrder(): ?Order
    {
        return Order::fromApp($this->app)
            ->notDeleted()
            ->whereHas(
                'orderStatus',
                fn ($query) => $query->whereIn('slug', [
                    MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug(),
                    MovipassOrderStatusEnum::DISPATCHED->slug(),
                    MovipassOrderStatusEnum::ON_SITE->slug(),
                    MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
                ])
            )
            ->where('metadata->assistance_case->mechanic->user_id', $this->technician->getId())
            ->first();
    }
}
