<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mercury\Services\MercuryStatementService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Throwable;

/**
 * Records each monthly statement on the bank account and attaches its PDF.
 *
 * The statement's own `transactions` array is dropped — every transaction already exists as a first-class
 * `bank_transactions` row, and a second copy would be a second source of truth. What the statement adds is
 * the bank's own signed closing balance, which is what a future reconciliation pass checks our books
 * against.
 *
 * PDFs go through `addFileFromUrl`, which routes to the SSRF-guarded FilesystemServices download — the URL
 * comes from an API response, so it is never fetched raw.
 */
class PullMercuryStatementsAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly BankAccount $bankAccount,
        protected readonly ?MercuryStatementService $statementService = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $mercuryAccountId = $this->bankAccount->external_id;

        if ($mercuryAccountId === null || $this->bankAccount->source !== 'mercury') {
            throw new RuntimeException(
                "BankAccount {$this->bankAccount->getId()} is not a Mercury account — refusing to sync statements."
            );
        }

        $service = $this->statementService ?? new MercuryStatementService($this->app, $this->company);
        $statements = $service->listForAccount($mercuryAccountId);

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
     * document, not a pointer to it.
     *
     * A statement we can't fetch is not worth failing the whole pull over — the balances and transactions are
     * already in. Skip it; the next run retries, because only successes are recorded.
     */
    private function attachPdf(array $statement): bool
    {
        $url = (string) ($statement['download_url'] ?? '');

        if ($url === '') {
            return false;
        }

        try {
            $app = $this->app instanceof Apps ? $this->app : app(Apps::class);
            $user = Users::getById($this->bankAccount->users_id ?? 0);

            $file = new FilesystemServices($app)->uploadFileFromUrl($url, $user);

            return $this->bankAccount->addFile($file, 'statement-' . (string) $statement['statement_id']);
        } catch (Throwable) {
            return false;
        }
    }
}
