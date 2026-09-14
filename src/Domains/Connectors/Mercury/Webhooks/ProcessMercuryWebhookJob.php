<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Actions\LandMercuryTransactionAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryAccountsAction;
use Kanvas\Connectors\Mercury\Actions\VerifyMercuryWebhookSignatureAction;
use Kanvas\Connectors\Mercury\Services\MercuryTransactionService;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * The delivery is a NUDGE, not data — the envelope carries a resourceId and a merge-patch, never the
 * transaction. We re-fetch by id and reuse the nightly pull's ingest path, which makes Mercury's no-ordering
 * and at-least-once guarantees free: we always read current state, and re-ingest is idempotent.
 *
 * The subtlety: we only book `sent` transactions, but a card auth is CREATED `pending` and later UPDATED to
 * `sent` — so the settlement arrives as `transaction.updated`, and trusting the create payload would miss it.
 */
#[WorkflowAction(
    name: 'Mercury Banking Webhook',
    description: 'Receiver for Mercury bank events: on any nudge it re-fetches the transaction by id and books '
        . 'it, then tries to match it to an open invoice or bill. Inbound only. The delivery is treated '
        . 'as a nudge rather than data because a card authorization arrives first as pending and only '
        . 'settles later, so trusting the payload would miss the settlement. Re-delivery is harmless.',
    integration: IntegrationsEnum::MERCURY,
)]
class ProcessMercuryWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $resourceType = (string) ($payload['resourceType'] ?? '');
        $resourceId = (string) ($payload['resourceId'] ?? '');

        if ($resourceId === '') {
            return ['message' => 'No resourceId in payload; nothing to do.'];
        }

        return match ($resourceType) {
            'transaction' => $this->ingestTransaction($resourceId),
            default => $this->refreshBalances($resourceType),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function ingestTransaction(string $transactionId): array
    {
        $app = $this->receiver->app;
        $company = $this->receiver->company;

        $transaction = new MercuryTransactionService($app, $company)->find($transactionId);

        if ($transaction === null) {
            return ['message' => "Mercury transaction {$transactionId} not found."];
        }

        // A pending authorization can still be cancelled or settle at a different amount. Booking it would put
        // cash on the ledger the bank never moved; the settlement arrives later as transaction.updated.
        if (! $transaction->isSettled()) {
            return [
                'message' => 'Transaction not settled yet; waiting for the update event.',
                'status' => $transaction->status->value,
            ];
        }

        $bankAccount = $this->bankAccountFor($transaction->accountId);

        if ($bankAccount === null) {
            return ['message' => "No bank account for Mercury account {$transaction->accountId}."];
        }

        $landed = new LandMercuryTransactionAction(
            bankAccount: $bankAccount,
            transaction: $transaction,
            user: $this->receiver->user,
        )->execute();

        $outcome = new MatchBankTransactionAction(
            bankTransaction: $landed,
            user: $this->receiver->user,
        )->execute();

        return [
            'message' => 'Transaction ingested.',
            'bank_transaction_id' => $landed->getId(),
            'outcome' => $outcome->value,
        ];
    }

    /**
     * A balance event just means "the number moved" — re-pull the accounts to refresh the snapshot the
     * opening-balance tie-out reads.
     *
     * @return array<string, mixed>
     */
    private function refreshBalances(string $resourceType): array
    {
        $accounts = new PullMercuryAccountsAction(
            app: $this->receiver->app,
            company: $this->receiver->company,
            user: $this->receiver->user,
        )->execute();

        return [
            'message' => 'Balances refreshed.',
            'resource_type' => $resourceType,
            'accounts' => count($accounts),
        ];
    }

    private function bankAccountFor(string $mercuryAccountId): ?BankAccount
    {
        return BankAccount::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->where('source', 'mercury')
            ->where('external_id', $mercuryAccountId)
            ->first();
    }

    /**
     * A false here returns 401 — and Mercury does NOT retry 4xx. A bug in verification doesn't delay the
     * event, it destroys it. The nightly poll is the only thing that can recover from that.
     */
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return new VerifyMercuryWebhookSignatureAction(
            company: $receiver->company,
            signatureHeader: (string) $request->headers->get('Mercury-Signature', ''),
            rawBody: $request->getContent(),
            now: Carbon::now()->getTimestamp(),
        )->execute();
    }
}
