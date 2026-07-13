<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Services\MercuryStatementService;
use Kanvas\Connectors\Mercury\Traits\MercuryBankAccountTrait;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Throwable;

/**
 * Records each monthly statement on the bank account and attaches its PDF.
 *
 * The statement's own `transactions` array is dropped — every transaction already exists as a first-class
 * `bank_transactions` row, and a second copy would be a second source of truth. What the statement adds is
 * the bank's own signed closing balance, which is what a future reconciliation pass checks our books
 * against.
 */
class PullMercuryStatementsAction
{
    use MercuryBankAccountTrait;

    public function __construct(
        public readonly BankAccount $bankAccount,
        protected readonly ?MercuryStatementService $statementService = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $service = $this->statementService ?? new MercuryStatementService($this->app(), $this->company());
        $statements = $service->listForAccount($this->mercuryAccountId());

        $metadata = $this->bankAccount->metadata ?? [];
        $alreadyAttached = (array) ($metadata['statement_ids'] ?? []);

        $attached = $alreadyAttached;
        foreach ($statements as $statement) {
            if (in_array($statement['statement_id'], $alreadyAttached, true)) {
                continue;
            }

            if ($this->attachPdf($statement)) {
                $attached[] = $statement['statement_id'];
            }
        }

        $metadata['statements'] = $statements;
        $metadata['statement_ids'] = array_values(array_unique($attached));
        $metadata['statements_synced_at'] = Carbon::now()->toIso8601String();

        $this->bankAccount->metadata = $metadata;
        $this->bankAccount->save();

        return $statements;
    }

    /**
     * Fetches the PDF bytes into our own storage.
     *
     * NOT addFileFromUrl — that records the URL and downloads nothing, leaving a Filesystem row with size=0.
     * Mercury's download links are presigned and expire, so the row would point at a dead link. We want the
     * document, not a pointer to it. (uploadFileFromUrl is SSRF-guarded internally.)
     *
     * A statement we can't fetch is not worth failing the whole pull over — the balances and transactions are
     * already in. Skip it; the next run retries, because only successes are recorded.
     *
     * @param array<string, mixed> $statement
     */
    private function attachPdf(array $statement): bool
    {
        $url = (string) ($statement['download_url'] ?? '');

        if ($url === '') {
            return false;
        }

        try {
            $file = new FilesystemServices($this->app())->uploadFileFromUrl($url, $this->owner());

            return $this->bankAccount->addFile($file, 'statement-' . (string) $statement['statement_id']);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
