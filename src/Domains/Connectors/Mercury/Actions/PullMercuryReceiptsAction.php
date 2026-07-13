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
 * The source documents that turn "a $2,400 debit to a hosting provider" into an auditable expense.
 *
 * Attachment URLs are presigned with a ~12h expiry, so we fetch the bytes now. `addFileFromUrl` would store
 * the link and download nothing (size=0), pointing at a URL that dies before anyone clicks it.
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
                $file = $filesystemService->uploadFileFromUrl($url, $owner);
                $transaction->addFile($file, 'receipt');
                $stored++;
            } catch (Throwable $e) {
                // One unreachable receipt must not sink the pull; only successes are marked, so the next run
                // retries it.
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
