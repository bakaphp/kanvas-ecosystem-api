<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentStatusEnum;
use Kanvas\Scribe\Payments\Models\Payment;

/**
 * Creates a Scribe.Payment row — the canonical money-movement record on the accounting side.
 * Distinct from Souk.Payments (cart commerce). Used by AllocateInvoice/Bill payment Actions,
 * Mercury reconciliation, ADM Cloud sync, Stripe Billing webhooks, and manual operator entries.
 */
class CreateScribePaymentAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly float $amountNative,
        public readonly string $currency,
        public readonly PaymentDirectionEnum $direction,
        public readonly PaymentMethodEnum $method,
        public readonly ?UserInterface $user = null,
        public readonly float $fxRateToBase = 1.0,
        public readonly ?Carbon $paymentDate = null,
        public readonly ?int $bankAccountId = null,
        public readonly ?string $reference = null,
        public readonly ?string $notes = null,
        public readonly string $source = 'kanvas',
        public readonly ?string $externalId = null,
        public readonly ?string $externalUrl = null,
        public readonly PaymentStatusEnum $status = PaymentStatusEnum::CLEARED,
        public readonly ?array $metadata = null,
    ) {
    }

    public function execute(): Payment
    {
        return DB::connection('accounting')->transaction(function (): Payment {
            $payment = new Payment();
            $payment->apps_id = $this->app->getId();
            $payment->companies_id = $this->company->getId();
            $payment->users_id = $this->user?->getId();
            $payment->amount_native = $this->amountNative;
            $payment->amount_base = $this->amountNative * $this->fxRateToBase;
            $payment->currency = $this->currency;
            $payment->fx_rate_to_base = $this->fxRateToBase;
            $payment->fx_rate_at = $this->paymentDate ?? Carbon::now();
            $payment->payment_date = $this->paymentDate ?? Carbon::today();
            $payment->cleared_at = $this->status === PaymentStatusEnum::CLEARED ? Carbon::now() : null;
            $payment->direction = $this->direction;
            $payment->method = $this->method;
            $payment->status = $this->status;
            $payment->bank_account_id = $this->bankAccountId;
            $payment->reference = $this->reference;
            $payment->notes = $this->notes;
            $payment->source = $this->source;
            $payment->external_id = $this->externalId;
            $payment->external_url = $this->externalUrl;
            $payment->metadata = $this->metadata;
            $payment->save();

            return $payment->refresh();
        });
    }
}
