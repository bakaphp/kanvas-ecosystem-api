<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
        // Mercury's v1 statements endpoint 404s for credit accounts — cards are v2-only. Asking anyway warns
        // on every run for every tenant with a card.
        if ($this->isCreditCard()) {
            return [];
        }

        $service = $this->statementService ?? new MercuryStatementService($this->app(), $this->company());
        $statements = $service->listForAccount($this->mercuryAccountId());

        $metadata = $this->bankAccount->metadata ?? [];
        $alreadyAttached = (array) ($metadata['statement_ids'] ?? []);

        $attached = $alreadyAttached;
        foreach ($statements as $statement) {
            if (in_array($statement['statement_id'], $alreadyAttached, true)) {
                continue;
            }

            if ($this->attachPdf($service, $statement)) {
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
     * @param array<string, mixed> $statement
     */
    private function attachPdf(MercuryStatementService $service, array $statement): bool
    {
        $statementId = (string) ($statement['statement_id'] ?? '');

        if ($statementId === '') {
            return false;
        }

        $tempFilePath = sys_get_temp_dir() . '/mercury-statement-' . $statementId . '.pdf';

        try {
            file_put_contents($tempFilePath, $service->downloadStatementPdf($statementId));

            $file = new FilesystemServices($this->app())->upload(
                new UploadedFile(
                    $tempFilePath,
                    "statement-{$statementId}.pdf",
                    'application/pdf',
                    null,
                    true
                ),
                $this->owner(),
            );

            return $this->bankAccount->addFile($file, 'statement-' . $statementId);
        } catch (Throwable $e) {
            Log::error('Failed to download Mercury statement PDF', [
                'bank_account_id' => $this->bankAccount->getId(),
                'statement_id' => $statementId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if (file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }
}
