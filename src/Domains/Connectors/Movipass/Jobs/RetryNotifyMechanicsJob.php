<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\NotifyAvailableMechanicsAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;

use Kanvas\Connectors\Movipass\Events\AssistanceMechanicNotFoundEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class RetryNotifyMechanicsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Users $mechanic,
        public readonly array $cancelledIds = [],
        public readonly int $attempt = 1,
        public readonly int $maxAttempts = 3,
        public readonly int $retryDelayMinutes = 1,
    ) {
    }

    public function handle(): void
    {
        $app = Apps::getById($this->order->apps_id);

        try {
            new NotifyAvailableMechanicsAction(
                $this->order,
                $app,
                $this->mechanic,
                $this->cancelledIds,
            )->execute();
        } catch (ValidationException) {
            if ($this->attempt < $this->maxAttempts) {
                self::dispatch(
                    $this->order,
                    $this->mechanic,
                    $this->cancelledIds,
                    $this->attempt + 1,
                    $this->maxAttempts,
                    $this->retryDelayMinutes,
                )->delay(now()->addMinutes($this->retryDelayMinutes));

                return;
            }

            // Max retries reached — cancel the order and notify the owner.
            $this->order->transitionToStatus(
                $this->mechanic,
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            );
            $this->order->fulfillCancelled();
            AssistanceMechanicNotFoundEvent::dispatch($this->order);

            $this->order->user->notify(new RoadsideAssistanceStatusNotification(
                $this->order,
                'No mechanics available',
                'We could not find an available mechanic. Your order has been cancelled.',
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ));
        }
    }
}
