<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Models\Payment;

/**
 * A bill someone marked paid whose money never reached the bank: recorded, still unreconciled, older
 * than the grace window. Each one gets one `accounting.payment.unconfirmed_by_bank` ledger event for the
 * digest, and `unconfirmed_flagged_at` is stamped so it is not flagged again.
 *
 * Only judged where the bank data reaches past the grace window. Without statements covering those days
 * the payment is not missing, we simply cannot see it yet, and flagging it would be noise.
 */
class FlagUnconfirmedBillPaymentsAction
{
    public const int DEFAULT_GRACE_DAYS = 10;

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly int $graceDays = self::DEFAULT_GRACE_DAYS,
        protected readonly BankStatementCoverageService $coverage = new BankStatementCoverageService(),
    ) {
    }

    /**
     * @return list<int> ids of the payments flagged on this run
     */
    public function execute(): array
    {
        $cutoff = Carbon::today()->subDays($this->graceDays);
        $coverage = $this->coverageByAccount();

        if ($coverage === []) {
            return [];
        }

        $flagged = [];

        $payments = Payment::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('direction', PaymentDirectionEnum::OUTBOUND->value)
            ->whereNull('reconciled_at')
            ->whereNotNull('recorded_via')
            ->whereNull('unconfirmed_flagged_at')
            ->whereDate('payment_date', '<=', $cutoff)
            ->get();

        foreach ($payments as $payment) {
            $coveredThrough = $payment->bank_account_id !== null
                ? ($coverage[$payment->bank_account_id] ?? null)
                : $this->latest($coverage);

            if ($coveredThrough === null || $coveredThrough->lt($payment->payment_date->copy()->addDays($this->graceDays))) {
                continue;
            }

            $this->flag($payment, $coveredThrough);
            $flagged[] = $payment->getId();
        }

        return $flagged;
    }

    private function flag(Payment $payment, Carbon $coveredThrough): void
    {
        $payment->unconfirmed_flagged_at = Carbon::now();
        $payment->save();

        new AppendEventAction(
            new EventData(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'Scribe',
                eventType: 'accounting.payment.unconfirmed_by_bank',
                status: EventStatusEnum::ERROR,
                sourceEntityType: Payment::class,
                sourceEntityId: $payment->getId(),
                actorType: 'System',
                payload: [
                    'payment_id' => $payment->getId(),
                    'reference' => $payment->reference,
                    'amount_native' => $payment->amount_native,
                    'currency' => $payment->currency,
                    'payment_date' => $payment->payment_date->toDateString(),
                    'bank_covered_through' => $coveredThrough->toDateString(),
                    'recorded_via' => $payment->recorded_via?->value,
                    'grace_days' => $this->graceDays,
                ],
            ),
        )->execute();
    }

    /**
     * @return array<int, Carbon>
     */
    private function coverageByAccount(): array
    {
        $coverage = [];

        /** @var BankAccount $account */
        foreach ($this->coverage->activeAccounts($this->app, $this->company) as $account) {
            $through = $this->coverage->coveredThrough($account);

            if ($through !== null) {
                $coverage[$account->getId()] = $through;
            }
        }

        return $coverage;
    }

    /**
     * @param array<int, Carbon> $coverage
     */
    private function latest(array $coverage): Carbon
    {
        return collect($coverage)->sortDesc()->first();
    }
}
