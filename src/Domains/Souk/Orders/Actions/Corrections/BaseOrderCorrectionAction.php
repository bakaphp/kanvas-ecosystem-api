<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions\Corrections;

use Closure;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

abstract class BaseOrderCorrectionAction
{
    public function __construct(
        protected Order $order,
        protected Users $user,
    ) {
    }

    abstract public function execute(): Order;

    protected function transact(Closure $callback): Order
    {
        return DB::connection('commerce')->transaction(function () use ($callback) {
            $this->order = $this->order->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->order->id);

            $callback();

            return $this->order->refresh();
        });
    }

    protected function guardNotFinalStatus(): void
    {
        if ((bool) $this->order->app->get(ConfigurationEnum::ALLOW_ORDER_CORRECTION_ON_FINAL_STATUS->value)) {
            return;
        }

        if ($this->order->orderStatus?->is_final) {
            $slug = $this->order->orderStatus->slug;

            throw new ValidationException("Cannot correct an order in a final status: {$slug}");
        }
    }

    protected function logCorrection(
        string $correctionType,
        array $changes,
        string $reason,
        array $evidenceUrls = []
    ): void {
        activity()
            ->causedBy($this->user)
            ->performedOn($this->order)
            ->withProperties([
                'changes' => $changes,
                'reason' => $reason,
                'evidence' => $evidenceUrls,
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ])
            ->log($correctionType);
    }
}
