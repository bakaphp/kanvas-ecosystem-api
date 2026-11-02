<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Bavix\Wallet\Exceptions\BalanceIsEmpty;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;

class FinalizeCheckoutTaxAction
{
    use HasWalletHolderTrait;

    public function __construct(
        private readonly Order $order,
        private readonly Apps $app,
        private readonly bool $reservesCredit = true,
    ) {
    }

    public function execute(): array
    {
        if (! $this->app->get(ConfigurationEnum::TAX_ENGINE_ENABLED->value)) {
            return $this->untaxedResult(taxEnabled: false);
        }

        $context = new BuildTaxContextAction($this->order, $this->app)->execute();
        $provider = new TaxProviderResolver($this->app, $this->order->company)->resolve($context);
        $result = $provider->calculate($context);

        if ($result->treatment === TaxTreatmentEnum::NOT_COLLECTING) {
            if (! $this->app->get(ConfigurationEnum::TAX_FAIL_OPEN->value)) {
                throw new ValidationException('tax_not_configured_for_destination');
            }

            return $this->untaxedResult(taxEnabled: true);
        }

        $this->order->tax_amount = (float) $result->totalTax;
        $this->order->tax_source = 'souk';
        $this->order->recomputeTotals(autoSave: false);

        $finalized = DB::connection('commerce')->transaction(function () use ($context, $result, $provider): array {
            $this->order->saveOrFail();

            $snapshot = new PersistTaxSnapshotAction(
                context: $context,
                result: $result,
                app: $this->app,
                provider: TaxProviderEnum::of($provider)->value,
                orderId: $this->order->id,
                cartSessionId: $this->order->checkout_token,
            )->execute();

            $snapshot->gateway_attempt_key = hash('sha256', $this->order->uuid . microtime(true));
            $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;

            $walletHoldTransferId = $this->holdWalletTender($snapshot);
            $creditReservationId = $this->reservesCredit ? $this->reserveCredit($snapshot) : null;

            $snapshot->checkout_payload = $this->buildCheckoutPayload(
                walletHoldTransferId: $walletHoldTransferId,
                creditReservationId: $creditReservationId,
            );

            $payable = (float) $this->order->total_payable_amount;
            $walletTender = (float) ($this->order->wallet_tender ?? 0.0);
            $gatewayDue = round($payable - $walletTender, 2);

            if (abs($gatewayDue - $this->order->getTotalDueAmount()) > 0.01) {
                throw new InvalidArgumentException(
                    'gatewayDue invariant violated: payable − walletTender must equal total_net_amount'
                );
            }

            $snapshot->saveOrFail();

            return [
                'snapshot' => $snapshot,
                'payable' => $payable,
                'gatewayDue' => $gatewayDue,
            ];
        });

        return [
            'snapshotUuid' => $finalized['snapshot']->uuid,
            'inputHash' => $finalized['snapshot']->input_hash,
            'payable' => $finalized['payable'],
            'gatewayDue' => $finalized['gatewayDue'],
            'taxEnabled' => true,
        ];
    }

    private function untaxedResult(bool $taxEnabled): array
    {
        return [
            'snapshotUuid' => null,
            'inputHash' => null,
            'payable' => $this->order->getTotalDueAmount(),
            'gatewayDue' => $this->order->getTotalDueAmount(),
            'taxEnabled' => $taxEnabled,
        ];
    }

    private function holdWalletTender(OrderTaxSnapshot $snapshot): ?int
    {
        $walletTender = (float) ($this->order->wallet_tender ?? 0.0);

        if ($walletTender < 0.01) {
            return null;
        }

        $walletHolder = $this->getWalletHolder($this->app, $this->order->user);
        $mainWallet = $walletHolder->createAppWallet($this->app, ['name' => WalletConfigurationEnum::WALLET_DEFAULT_NAME->value]);
        $holdWallet = $walletHolder->createAppWallet($this->app, ['name' => WalletConfigurationEnum::WALLET_CHECKOUT_HOLD_NAME->value]);

        try {
            $transfer = $mainWallet->transferFloat(
                wallet: $holdWallet,
                amount: $walletTender,
                meta: [
                    'snapshot_uuid' => $snapshot->uuid,
                    'type' => 'checkout_hold',
                    'order_uuid' => $this->order->uuid,
                ]
            );
        } catch (BalanceIsEmpty|InsufficientFunds) {
            throw new ValidationException(
                'Insufficient wallet balance to hold for checkout.',
                'wallet_balance_insufficient'
            );
        }

        return $transfer->getId();
    }

    private function reserveCredit(OrderTaxSnapshot $snapshot): ?int
    {
        $applicable = $this->resolveApplicableCredit();

        if ($applicable === null) {
            return null;
        }

        $credit = Discount::query()->lockForUpdate()->find($applicable->getId());

        if ($credit === null || ! $credit->canBeUsed()) {
            return null;
        }

        $reservation = CreditReservation::create([
            'snapshot_id' => $snapshot->id,
            'companies_id' => $this->order->companies_id,
            'discount_id' => $credit->getId(),
            'amount' => round(min($credit->value, $this->order->remainingNetAmount()), 2),
            'status' => 'held',
        ]);

        return $reservation->getId();
    }

    private function resolveApplicableCredit(): ?Discount
    {
        return new DiscountService($this->app, $this->order->buyerCompany())
            ->getApplicableCredits()
            ->first();
    }

    private function buildCheckoutPayload(
        ?int $walletHoldTransferId,
        ?int $creditReservationId,
    ): array {
        $lines = [];
        foreach ($this->order->allItems as $item) {
            $lines[] = [
                'uuid' => $item->uuid,
                'variant_id' => $item->variant_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'quantity' => (int) $item->quantity,
                'unit_price_net_amount' => (float) $item->unit_price_net_amount,
                'tax_amount' => (float) ($item->unit_tax_amount ?? 0.0),
            ];
        }

        $discountAllocation = $this->order->orderDiscounts->map(fn ($od) => [
            'discount_id' => $od->discount_id,
            'amount' => (float) $od->amount,
        ])->toArray();

        return [
            'lines' => $lines,
            'shipping_address' => $this->addressPayload($this->order->shippingAddress),
            'billing_address' => $this->addressPayload($this->order->billingAddress),
            'discount_allocation' => $discountAllocation,
            'wallet_hold_transfer_id' => $walletHoldTransferId,
            'credit_reservation_id' => $creditReservationId,
            'order_context' => [
                'region_id' => $this->order->region_id,
                'people_id' => $this->order->people_id,
                'companies_id' => $this->order->companies_id,
            ],
            'totals' => [
                'subtotal' => (float) $this->order->subtotal_amount,
                'discount' => (float) ($this->order->discount_amount ?? 0.0),
                'tax' => (float) ($this->order->tax_amount ?? 0.0),
                'shipping' => (float) ($this->order->shipping_price_net_amount ?? 0.0),
                'payable' => (float) $this->order->total_payable_amount,
                'wallet_tender' => (float) ($this->order->wallet_tender ?? 0.0),
                'gateway_due' => (float) $this->order->getTotalDueAmount(),
            ],
        ];
    }

    private function addressPayload(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'address' => $address->address,
            'address_2' => $address->address_2 ?? null,
            'city' => $address->city,
            'state' => $address->state,
            'zip' => $address->zip,
            'country_code' => $address->country?->code,
        ];
    }
}
