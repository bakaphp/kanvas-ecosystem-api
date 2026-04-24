<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

class CancelStalePaymentsAction
{
    private const DEFAULT_TTL_MINUTES = 5;

    private const STALE_STATUSES = [
        PaymentStatusEnum::PENDING,
        PaymentStatusEnum::WAITING_DEVICE_DATA,
        PaymentStatusEnum::PENDING_AUTHORIZATION,
        PaymentStatusEnum::PROCESSING,
    ];

    private const AUTHORIZED_LOG_EVENTS = [
        'payment_authorized',
        'authorize_success',
        'hold_success',
    ];

    public function __construct(
        protected AppInterface $app,
    ) {
    }

    public function execute(): Collection
    {
        $ttlMinutes = (int) ($this->app->get(ConfigurationEnum::STALE_PAYMENT_TTL_MINUTES->value) ?: self::DEFAULT_TTL_MINUTES);
        $cutoff = Carbon::now()->subMinutes($ttlMinutes);

        $stalePayments = Payments::where('apps_id', $this->app->getId())
            ->whereIn('status', array_map(fn ($s) => $s->value, self::STALE_STATUSES))
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('paymentLogs', function ($q) use ($cutoff) {
                $q->where('created_at', '>=', $cutoff);
            })
            ->whereDoesntHave('paymentLogs', function ($q) {
                $q->whereIn('status', self::AUTHORIZED_LOG_EVENTS);
            })
            ->get();

        foreach ($stalePayments as $payment) {
            $ageMinutes = (int) Carbon::parse($payment->updated_at)->diffInMinutes(now());

            $payment->status = PaymentStatusEnum::CANCELLED->value;
            $payment->save();

            $payment->addLog('cancelled_stale', [
                'reason' => "Payment stuck in transitional state for {$ageMinutes} minutes (TTL: {$ttlMinutes}m)",
                'previous_status' => $payment->getOriginal('status'),
                'age_minutes' => $ageMinutes,
            ]);
        }

        return $stalePayments;
    }
}
