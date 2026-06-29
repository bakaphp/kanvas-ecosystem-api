<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Closure;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

abstract class BaseImpoundCorrectionAction
{
    public function __construct(
        protected Order $order,
        protected Users $user,
    ) {
    }

    abstract public function execute(): Order;

    protected function transact(Closure $callback): mixed
    {
        return DB::connection('commerce')->transaction(function () use ($callback) {
            $this->order = $this->order->lockForUpdate()->fresh();

            return $callback();
        });
    }

    protected function guardNotFinal(): void
    {
        $slug = $this->order->orderStatus?->slug ?? '';

        if (in_array($slug, [MovipassOrderStatusEnum::RELEASED->value, MovipassOrderStatusEnum::CANCELLED->value], true)) {
            throw new ValidationException("Cannot correct an impound lot order in final status: {$slug}");
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

    // Does NOT call saveOrFail() — the concrete action owns the save within transact().
    protected function appendEvidenceImages(array $urls): void
    {
        if (empty($urls)) {
            return;
        }

        $metadata = is_array($this->order->metadata) ? $this->order->metadata : [];
        $existing = $metadata['data']['images'] ?? [];
        $metadata['data']['images'] = array_values(array_unique(array_merge($existing, $urls)));
        $this->order->metadata = $metadata;
    }
}
