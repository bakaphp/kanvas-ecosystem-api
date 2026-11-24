<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\SuspenseQueueService;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Payments\Actions\RecordBillPaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentRecordedViaEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use RuntimeException;

/**
 * "Paid Claro today" from a person. Without a reference one is derived from bill + date + amount, so
 * being told the same thing twice records one payment. When the money is already a parked statement
 * movement the tool refuses and points at settle_from_suspense: recording it here would credit cash a
 * second time.
 */
#[AgentTool(name: 'Record Bill Payment', category: 'accounting')]
class RecordBillPaymentTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use ResolvesFilesystemForTool;
    use TrackByInputs;

    protected string $name = 'record_bill_payment';

    protected ?string $description = 'Marks a vendor bill paid when a person tells you they paid it ("paid Claro and Edesur '
        . 'today"), with or without the receipt. You never move money — this only records a payment that already '
        . 'happened. Amount defaults to the bill\'s balance and the date to today. Telling it twice records one '
        . 'payment. The bank statement confirms it later. If the payment is already a movement on list_suspense, '
        . 'it refuses and you use settle_from_suspense.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The bill paid, from find_bill or list_open_bills.',
                required: true,
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'Amount paid. Omit for the full balance due.',
                required: false,
            ),
            new ToolProperty(
                name: 'paid_on',
                type: PropertyType::STRING,
                description: 'Date paid, YYYY-MM-DD. Omit for today.',
                required: false,
            ),
            new ToolProperty(
                name: 'reference',
                type: PropertyType::STRING,
                description: 'Transfer / check / confirmation number when the person gave one.',
                required: false,
            ),
            new ToolProperty(
                name: 'bank_account_id',
                type: PropertyType::INTEGER,
                description: 'The bank account it was paid from (bank_statement_coverage), when known.',
                required: false,
            ),
            new ToolProperty(
                name: 'receipt_filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The payment receipt from an `[Attached file...]` marker, when one was sent.',
                required: false,
            ),
            new ToolProperty(
                name: 'receipt_url',
                type: PropertyType::STRING,
                description: 'A link to the receipt, only when there is no receipt_filesystem_id.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $bill_id,
        ?float $amount = null,
        ?string $paid_on = null,
        ?string $reference = null,
        ?int $bank_account_id = null,
        ?int $receipt_filesystem_id = null,
        ?string $receipt_url = null,
    ): array {
        return $this->oncePerTurn(
            [
                'bill_id' => $bill_id,
                'amount' => $amount,
                'paid_on' => $paid_on,
                'reference' => $reference,
            ],
            fn (): array => $this->record(
                $bill_id,
                $amount,
                $paid_on,
                $reference,
                $bank_account_id,
                $receipt_filesystem_id,
                $receipt_url,
            ),
            note: 'This payment was already recorded earlier in this turn. Nothing was posted now; report the payment shown here.',
            rememberFailures: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        int $billId,
        ?float $amount,
        ?string $paidOn,
        ?string $reference,
        ?int $bankAccountId,
        ?int $receiptFilesystemId,
        ?string $receiptUrl
    ): array {
        $bill = $this->resolveBillOrError($billId);
        if (is_array($bill)) {
            return $bill;
        }

        $account = null;
        if ($bankAccountId !== null) {
            $account = $this->resolveBankAccountOrError($bankAccountId);
            if (is_array($account)) {
                return $account;
            }
        }

        $paidAt = $this->paidAt($paidOn);
        if ($paidAt === null) {
            return $this->invalidArgs("paid_on must be a date like 2026-06-15 (got \"{$paidOn}\").", ['recorded' => false]);
        }

        $amount ??= (float) $bill->balance_due_native;
        if ($amount <= 0) {
            return $this->noop(['recorded' => false, 'bill_status' => $bill->document_status->value], 'This bill has no balance left to pay.');
        }

        $parked = $this->parkedMovementFor(
            $bill,
            $amount,
            $paidAt,
            $account
        );
        if ($parked !== null) {
            return $this->denied(
                'This payment is already on the bank statement, parked in Suspense.',
                ['recorded' => false, 'bank_transaction_id' => $parked->getId()],
                "Call settle_from_suspense with bank_transaction_id {$parked->getId()} and bill_id {$bill->getId()} instead.",
            );
        }

        $receipt = $this->receipt($receiptFilesystemId, $receiptUrl);
        if (is_array($receipt)) {
            return $this->invalidArgs($receipt['message'], ['recorded' => false]);
        }

        try {
            $recorded = new RecordBillPaymentAction(
                bill: $bill,
                amountNative: $amount,
                recordedVia: PaymentRecordedViaEnum::AGENT,
                reference: Str::trimToNull($reference) ?? $this->derivedReference($bill, $paidAt, $amount),
                paidAt: $paidAt,
                method: PaymentMethodEnum::MANUAL,
                bankAccount: $account,
                user: $this->user,
                receipt: $receipt,
            )->execute();
        } catch (RuntimeException $e) {
            return $this->invalidArgs($e->getMessage(), ['recorded' => false], 'The payment was NOT recorded.');
        }

        $fresh = $bill->fresh();

        return $this->ok([
            'recorded' => true,
            'already_recorded' => $recorded->alreadyRecorded,
            'bill_id' => $bill->getId(),
            'bill_number' => $bill->bill_number,
            'payment_id' => $recorded->payment->getId(),
            'amount' => $recorded->allocation->amount_native,
            'reference' => $recorded->payment->reference,
            'bill_status' => $fresh->document_status->value,
            'balance_due' => (float) $fresh->balance_due_native,
        ]);
    }

    private function paidAt(?string $paidOn): ?Carbon
    {
        $text = Str::trimToNull($paidOn);

        if ($text === null) {
            return Carbon::today();
        }

        $date = DateHelper::parseYmd($text);

        return $date === null ? null : Carbon::instance($date);
    }

    private function derivedReference(Bill $bill, Carbon $paidAt, float $amount): string
    {
        return 'agent:' . $bill->getId() . ':' . $paidAt->toDateString() . ':' . number_format($amount, 2, '.', '');
    }

    private function parkedMovementFor(
        Bill $bill,
        float $amount,
        Carbon $paidAt,
        ?BankAccount $account
    ): ?BankTransaction {
        return new SuspenseQueueService()->parked($this->app, $this->company)
            ->where('direction', BankTransactionDirectionEnum::DEBIT->value)
            ->where('currency', $bill->currency)
            ->whereBetween('amount_native', [$amount - 0.005, $amount + 0.005])
            ->whereBetween('transaction_date', [$paidAt->copy()->subDays(5), $paidAt->copy()->addDays(45)])
            ->when($account !== null, fn ($q) => $q->where('bank_account_id', $account->getId()))
            ->orderBy('transaction_date')
            ->first();
    }

    /**
     * @return Filesystem|array{reason: string, message: string}|null
     */
    private function receipt(?int $filesystemId, ?string $url): Filesystem|array|null
    {
        if ($filesystemId === null && Str::trimToNull($url) === null) {
            return null;
        }

        return $this->resolveOrUploadTenantFile($filesystemId, $url);
    }
}
