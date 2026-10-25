<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\Movipass\Actions\NotifyAvailableMechanicsAction;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Events\AssistanceMechanicNotFoundEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class RetryNotifyMechanicsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithAssistanceCase;
    use InteractsWithQueue;
    use KanvasJobsTrait;
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
        $app = $this->order->app;
        $this->overwriteAppService($app);

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

            $this->saveAssistanceCase(
                $this->order,
                $this->markStatusNotified(
                    $this->assistanceCaseFrom($this->order),
                    MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
                ),
            );

            $this->order->transitionToStatus(
                $this->mechanic,
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            );
            $this->order->fulfillCancelled();
            AssistanceMechanicNotFoundEvent::dispatch($this->order);

            $this->notifySafely(
                $this->resolveCaseUser($this->order->users_id),
                new RoadsideAssistanceStatusNotification(
                    $this->order,
                    'No mechanics available',
                    'We could not find an available mechanic. Your order has been cancelled.',
                    MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
                ),
            );
        }
    }
}
