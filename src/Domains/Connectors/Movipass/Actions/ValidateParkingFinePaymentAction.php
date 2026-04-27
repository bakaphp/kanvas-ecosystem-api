<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\ParkingGateCodeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class ValidateParkingFinePaymentAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly string $token,
        protected readonly ?string $lotId = null,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     code: string,
     *     order_id: ?int,
     *     transaction_id: ?string,
     *     amount_charged: ?float,
     * }
     */
    public function execute(): array
    {
        $order = Order::fromApp($this->app)
            ->notDeleted()
            ->where('token', $this->token)
            ->first();

        if ($order === null) {
            return $this->response(
                false,
                ParkingGateCodeEnum::NOT_FOUND,
                'No order found for the provided token.'
            );
        }

        $alreadyOpenedAt = $order->get('parking_gate_opened_at');
        if ($alreadyOpenedAt !== null) {
            $this->stamp($order, ParkingGateCodeEnum::ALREADY_USED, 'Gate already opened at ' . (string) $alreadyOpenedAt);

            return $this->response(
                false,
                ParkingGateCodeEnum::ALREADY_USED,
                'This QR has already been used to open the gate.',
                $order->getId()
            );
        }

        $isPaid = $order->payment_status === PaymentStatusEnum::PAID->value || $order->isPaid();
        if (! $isPaid) {
            $this->stamp($order, ParkingGateCodeEnum::NOT_PAID, 'Order is not paid.');

            return $this->response(
                false,
                ParkingGateCodeEnum::NOT_PAID,
                'Order has not been paid yet.',
                $order->getId()
            );
        }

        $graceHours = (int) ($this->app->get(ConfigurationEnum::PARKING_FINE_GATE_GRACE_HOURS->value) ?? 24);
        $paidAt = $order->updated_at ?? $order->created_at;
        if ($paidAt !== null && Carbon::parse($paidAt)->addHours($graceHours)->isPast()) {
            $this->stamp($order, ParkingGateCodeEnum::EXPIRED, 'Grace window of ' . $graceHours . 'h has elapsed.');

            return $this->response(
                false,
                ParkingGateCodeEnum::EXPIRED,
                'Payment was made too long ago to open the gate. Please contact support.',
                $order->getId()
            );
        }

        $this->stamp($order, ParkingGateCodeEnum::PAID, 'Gate opened.');

        return $this->response(
            true,
            ParkingGateCodeEnum::PAID,
            'Payment validated. Gate is opening.',
            $order->getId()
        );
    }

    protected function stamp(Order $order, ParkingGateCodeEnum $code, string $reason): void
    {
        $now = Carbon::now()->toIso8601String();
        /** @var array<string, mixed> $metadata */
        $metadata = $order->metadata ?? [];
        /** @var array<string, mixed> $parking */
        $parking = $metadata['parking_gate'] ?? [];

        if ($code === ParkingGateCodeEnum::PAID) {
            $parking['opened_at'] = $now;
        }

        $parking['result'] = $code->value;
        $parking['reason'] = $reason;
        $parking['last_scan_at'] = $now;

        if ($this->lotId !== null) {
            $parking['lot_id'] = $this->lotId;
        }

        /** @var list<array<string, mixed>> $history */
        $history = $parking['history'] ?? [];
        $history[] = [
            'at' => $now,
            'code' => $code->value,
            'reason' => $reason,
            'lot_id' => $this->lotId,
        ];
        $parking['history'] = $history;

        $metadata['parking_gate'] = $parking;
        $order->metadata = $metadata;
        $order->saveQuietly();
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     code: string,
     *     order_id: ?int,
     *     transaction_id: ?string,
     *     amount_charged: ?float,
     * }
     */
    protected function response(
        bool $success,
        ParkingGateCodeEnum $code,
        string $message,
        ?int $orderId = null,
    ): array {
        return [
            'success' => $success,
            'message' => $message,
            'code' => $code->value,
            'order_id' => $orderId,
            'transaction_id' => null,
            'amount_charged' => null,
        ];
    }
}
