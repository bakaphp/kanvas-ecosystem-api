<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Wallet\Transfer as SoukTransfer;

class ReleaseCheckoutHoldsAction
{
    public function __construct(private readonly OrderTaxSnapshot $snapshot)
    {
    }

    public function execute(): void
    {
        DB::connection('commerce')->transaction(function (): void {
            $payload = is_array($this->snapshot->checkout_payload) ? $this->snapshot->checkout_payload : [];

            $this->releaseWalletHold($payload);
            CreditReservation::settleHeldFromPayload($payload, 'released');

            OrderTaxSnapshot::query()
                ->where('id', $this->snapshot->id)
                ->update(['gateway_attempt_status' => GatewayAttemptStatusEnum::VOIDED->value]);
        });
    }

    private function releaseWalletHold(array $payload): void
    {
        $walletHoldTransferId = $payload['wallet_hold_transfer_id'] ?? null;

        if ($walletHoldTransferId === null) {
            return;
        }

        $transfer = SoukTransfer::query()->with(['from', 'to', 'deposit'])->find((int) $walletHoldTransferId);

        if ($transfer === null) {
            return;
        }

        $holdWallet = $transfer->to;
        $mainWallet = $transfer->from;

        if ($holdWallet === null || $mainWallet === null) {
            return;
        }

        $alreadyReleased = $mainWallet->transactions()
            ->where('meta->snapshot_uuid', $this->snapshot->uuid)
            ->where('meta->type', 'hold_release')
            ->exists();

        if ($alreadyReleased) {
            return;
        }

        $releaseAmount = (float) ($transfer->deposit?->amountFloat ?? 0.0);

        if ($releaseAmount <= 0.0) {
            return;
        }

        $holdWallet->transferFloat($mainWallet, $releaseAmount, [
            'snapshot_uuid' => $this->snapshot->uuid,
            'type' => 'hold_release',
            'original_transfer_id' => $walletHoldTransferId,
        ]);
    }
}
