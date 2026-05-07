<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\Movipass\Actions\AssignMechanicToOrderAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class AutoAssignMechanicJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly int $attempt = 1,
        public readonly int $maxAttempts = 3,
        public readonly int $retryDelayMinutes = 5,
    ) {
    }

    public function handle(): void
    {
        try {
            new AssignMechanicToOrderAction(
                $this->order,
                Users::getById((int) $this->order->users_id),
            )->execute();
        } catch (ValidationException) {
            if ($this->attempt < $this->maxAttempts) {
                self::dispatch(
                    $this->order,
                    $this->attempt + 1,
                    $this->maxAttempts,
                    $this->retryDelayMinutes,
                )->delay(now()->addMinutes($this->retryDelayMinutes));

                return;
            }

            throw new ValidationException(
                'No available mechanics for order ' . $this->order->getId() . ' after ' . $this->attempt . ' attempts'
            );
        }
    }
}
