<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Traits\MercuryBankAccountTrait;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Throwable;

/**
 * Downloads the receipts people attached to their Mercury transactions and stores them against the matching
 * bank_transaction row.
 *
 * These are the actual source documents — invoices, fiscal receipts, card slips. They're what turns "a $2,400
 * debit to a hosting provider" into an auditable expense.
 *
 * The attachment URLs are PRESIGNED S3 links with a ~12 hour expiry, so we fetch the bytes now rather than
 * storing the link. `addFileFromUrl` would have kept the URL and downloaded nothing, leaving a Filesystem row
 * with size=0 pointing at a link that dies before anyone clicks it.
 *
 * Idempotent: a transaction whose receipts we already stored is skipped.
 */
class PullMercuryReceiptsAction
{
    use MercuryBankAccountTrait;

    public function __construct(
        public readonly BankAccount $bankAccount,
    ) {
    }

    /**
     * @return int Number of receipt files stored.
     */
    public function execute(): int
    {
        $this->mercuryAccountId();

        $transactions = BankTransaction::query()
            ->fromApp($this->app())
            ->fromCompany($this->company())
            ->notDeleted()
            ->where('bank_account_id', $this->bankAccount->getId())
            ->where('source', 'mercury')
            ->get();

        $stored = 0;

        foreach ($transactions as $transaction) {
            $stored += $this->storeReceiptsFor($transaction);
        }

        return $stored;
    }

    private function storeReceiptsFor(BankTransaction $transaction): int
    {
        $attachments = (array) ($transaction->raw_payload['attachments'] ?? []);

        if ($attachments === [] || ($transaction->metadata['receipts_stored'] ?? false) === true) {
            return 0;
        }

        $filesystemService = new FilesystemServices($this->app());
        $owner = $this->owner();
        $stored = 0;

        foreach ($attachments as $attachment) {
            $url = (string) (((array) $attachment)['url'] ?? '');

            if ($url === '') {
                continue;
            }

            try {
                // uploadFileFromUrl fetches the bytes into our own storage (SSRF-guarded inside), unlike
                // addFileFromUrl which would only record the soon-to-expire link.
                $file = $filesystemService->uploadFileFromUrl($url, $owner);
                $transaction->addFile($file, 'receipt');
                $stored++;
            } catch (Throwable $e) {
                // A single unreachable receipt must not sink the whole pull — the transaction itself is
                // already on the books. The next run retries, since we only mark success below.
                report($e);

                continue;
            }
        }

        if ($stored > 0) {
            $metadata = $transaction->metadata ?? [];
            $metadata['receipts_stored'] = true;
            $metadata['receipts_count'] = $stored;
            $metadata['receipts_stored_at'] = Carbon::now()->toIso8601String();
            $transaction->metadata = $metadata;
            $transaction->save();
        }

        return $stored;
    }
}
