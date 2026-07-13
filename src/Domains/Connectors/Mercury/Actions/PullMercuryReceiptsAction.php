<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Downloads the receipts people attached to their Mercury transactions and stores them against the matching
 * bank_transaction row.
 *
 * These are the actual source documents — invoices, fiscal receipts (one in this tenant is a Dominican NCF),
 * card slips. They're what turns "a $2,400 debit to AWS" into an auditable expense.
 *
 * The attachment URLs are PRESIGNED S3 links with a ~12 hour expiry, so we fetch the bytes now rather than
 * storing the link. `addFileFromUrl` would have kept the URL and downloaded nothing, leaving a Filesystem row
 * with size=0 pointing at a link that dies before anyone clicks it.
 *
 * Idempotent: a transaction whose receipts we already stored is skipped.
 */
class PullMercuryReceiptsAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly BankAccount $bankAccount,
        public readonly ?Users $user = null,
    ) {
    }

    /**
     * @return int Number of receipt files stored.
     */
    public function execute(): int
    {
        $transactions = BankTransaction::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
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

        $filesystemService = new FilesystemServices($this->resolveApp());
        $stored = 0;

        foreach ($attachments as $attachment) {
            $row = (array) $attachment;
            $url = (string) ($row['url'] ?? '');

            if ($url === '') {
                continue;
            }

            try {
                // Fetches the bytes into our own storage (SSRF-guarded inside), unlike addFileFromUrl which
                // would only record the soon-to-expire link.
                $file = $filesystemService->uploadFileFromUrl($url, $this->resolveUser());
                $transaction->addFile($file, 'receipt');
                $stored++;
            } catch (Throwable) {
                // A single unreachable receipt must not sink the whole pull — the transaction itself is
                // already on the books. The next run retries, since we only mark success below.
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

    private function resolveApp(): Apps
    {
        return $this->app instanceof Apps ? $this->app : app(Apps::class);
    }

    private function resolveUser(): Users
    {
        return $this->user ?? Users::getById($this->bankAccount->users_id ?? 0);
    }
}
