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
 * The statement's own `transactions` array is dropped — we already hold each one as a `bank_transactions`
 * row, and a second copy is a second source of truth. What it adds is the bank's signed closing balance.
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
     * NOT addFileFromUrl — that stores the URL and downloads nothing (size=0), and Mercury's links are
     * presigned and expire. A statement we can't fetch is skipped, not fatal; only successes are recorded, so
     * the next run retries it.
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
