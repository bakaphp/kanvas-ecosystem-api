<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Webhooks;

use Baka\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Services\FilesystemRowMapper;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Payments\Actions\RecordBillPaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentRecordedViaEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Concerns\AuthenticatesWithSharedSecret;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use RuntimeException;
use Throwable;

/**
 * A payment provider telling us a vendor bill was paid. Any provider fits without code: the receiver's
 * `mapping` says where each field sits in the provider's payload, in the FilesystemMapper value language
 * over the payload flattened to dot paths (`"amount": "data.object.amount"`, `"reference": {"$coalesce":
 * ["data.id", "id"]}`). Unmapped fields are read from a top-level key of the same name.
 *
 * Lands on RecordBillPaymentAction, so a provider's retry with the same reference returns the payment
 * already recorded. A payload without a reference is refused for that reason.
 */
#[WorkflowAction(
    name: 'Bill Payment Receiver',
    description: 'Receives a payment provider\'s "paid" notification for a vendor bill and records the payment '
        . 'against that bill, once per payment reference. The bank statement later confirms it. Requires '
        . 'shared_secret (sent in X-Webhook-Secret); optional mapping says where bill_id / bill_number / amount / '
        . 'reference / paid_at / currency / method sit in the provider payload.',
    integration: IntegrationsEnum::KANVAS,
    requiredParams: ['shared_secret'],
    params: [
        'shared_secret' => 'Secret the provider puts in the X-Webhook-Secret header.',
        'mapping' => 'Optional. Field => dot path (or mapper expression) into the payload.',
        'bank_account_id' => 'Optional. The bank account the provider pays from.',
    ],
)]
class BillPaymentWebhookJob extends ProcessWebhookJob
{
    use AuthenticatesWithSharedSecret;

    private const array FIELDS = ['bill_id', 'bill_number', 'amount', 'reference', 'paid_at', 'currency', 'method'];

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return self::hasValidSharedSecret($request, $receiver);
    }

    #[Override]
    public function execute(): array
    {
        $fields = $this->fields(is_array($this->webhookRequest->payload) ? $this->webhookRequest->payload : []);

        $bill = $this->bill($fields);
        if ($bill === null) {
            return $this->refuse('bill_not_found', 'No bill of this company matches bill_id / bill_number.');
        }

        $reference = is_scalar($fields['reference']) ? Str::trimToNull((string) $fields['reference']) : null;
        if ($reference === null) {
            return $this->refuse('missing_reference', 'A payment reference is required so a retry is not recorded twice.');
        }

        if (! is_numeric($fields['amount']) || (float) $fields['amount'] <= 0) {
            return $this->refuse('invalid_amount', 'amount must be a positive number.');
        }

        $currency = Str::trimmedStringOrNull($fields['currency']);
        if ($currency !== null && strtoupper($currency) !== strtoupper($bill->currency)) {
            return $this->refuse('currency_mismatch', "The payment is in {$currency}; bill {$bill->bill_number} is in {$bill->currency}.");
        }

        try {
            $recorded = new RecordBillPaymentAction(
                bill: $bill,
                amountNative: (float) $fields['amount'],
                recordedVia: PaymentRecordedViaEnum::WEBHOOK,
                reference: $reference,
                paidAt: $this->date($fields['paid_at']),
                method: PaymentMethodEnum::tryFrom(Str::lowerTrim(Str::trimmedStringOrNull($fields['method']))) ?? PaymentMethodEnum::WIRE,
                bankAccount: $this->bankAccount(),
                user: $this->receiver->user,
                metadata: ['webhook_request_id' => $this->webhookRequest->getId()],
            )->execute();
        } catch (RuntimeException $e) {
            return $this->refuse('allocation_failed', $e->getMessage());
        }

        return [
            'recorded' => true,
            'already_recorded' => $recorded->alreadyRecorded,
            'bill_id' => $bill->getId(),
            'payment_id' => $recorded->payment->getId(),
            'bill_status' => $bill->fresh()->document_status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(array $payload): array
    {
        $mapping = (array) (($this->receiver->configuration ?? [])['mapping'] ?? []);
        $row = [...Arr::dot($payload), ...array_filter($payload, 'is_scalar')];
        $fields = [];

        foreach (self::FIELDS as $field) {
            $fields[$field] = FilesystemRowMapper::resolve($mapping[$field] ?? $field, $row);
        }

        return $fields;
    }

    private function bill(array $fields): ?Bill
    {
        $query = Bill::query()
            ->where('apps_id', $this->receiver->apps_id)
            ->where('companies_id', $this->receiver->companies_id)
            ->where('is_deleted', false);

        if (is_numeric($fields['bill_id'])) {
            return $query->where('id', (int) $fields['bill_id'])->first();
        }

        $number = is_scalar($fields['bill_number']) ? Str::trimToNull((string) $fields['bill_number']) : null;

        return $number === null ? null : $query->where('bill_number', $number)->first();
    }

    private function bankAccount(): ?BankAccount
    {
        $accountId = ($this->receiver->configuration ?? [])['bank_account_id'] ?? null;

        return $accountId === null ? null : BankAccount::query()
            ->where('id', (int) $accountId)
            ->where('apps_id', $this->receiver->apps_id)
            ->where('companies_id', $this->receiver->companies_id)
            ->first();
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function refuse(string $reason, string $message): array
    {
        return ['recorded' => false, 'reason' => $reason, 'message' => $message];
    }
}
