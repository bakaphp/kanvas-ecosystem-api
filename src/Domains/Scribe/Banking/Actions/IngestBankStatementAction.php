<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementIngestResult;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementLine;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementIngestOutcomeEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementStatusEnum;
use Kanvas\Scribe\Banking\Exceptions\BankStatementRejectedException;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankStatement;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\BankStatementBalanceValidatorService;
use Throwable;

/**
 * The one landing path for a statement, whichever parser produced it (CSV mapper, PDF extraction,
 * webhook rows). Order matters:
 *
 *   account match → balance check → statement row → each row through CreateBankTransactionAction
 *   → each new row through MatchBankTransactionAction
 *
 * The first two are gates: either failing records a REJECTED statement and lands no transaction.
 * Rows land under one transaction source with an external_id that survives overlap (bank reference,
 * else a hash of account + date + amount + description + running balance), so Nov 1–15 then Nov 1–30
 * never doubles a movement. Matching runs after the rows are committed and per row: a row the matcher
 * cannot post (a closed period, say) stays unmatched for the agent instead of undoing the statement.
 *
 * `app`/`company` are passed because the account may not be known yet — a forwarded PDF is matched to
 * its account from the header's last 4 digits and currency.
 */
class IngestBankStatementAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ParsedBankStatement $statement,
        public readonly BankStatementSourceEnum $source,
        public readonly ?BankAccount $bankAccount = null,
        public readonly ?Filesystem $file = null,
        public readonly ?UserInterface $user = null,
        public readonly ?BankStatementRejectedException $parseFailure = null,
        protected readonly BankStatementBalanceValidatorService $validator = new BankStatementBalanceValidatorService(),
    ) {
    }

    public function execute(): BankStatementIngestResult
    {
        $account = $this->bankAccount;

        try {
            if ($this->parseFailure !== null) {
                throw $this->parseFailure;
            }

            $account = $this->resolveAccount();
            $validated = $this->validator->validate($this->statement);
        } catch (BankStatementRejectedException $e) {
            return BankStatementIngestResult::rejected($this->recordRejection($account, $e));
        }

        $fingerprint = $this->fingerprint($account, $validated);
        $alreadyPosted = $this->postedWithFingerprint($fingerprint);

        if ($alreadyPosted !== null) {
            return new BankStatementIngestResult(
                outcome: BankStatementIngestOutcomeEnum::DUPLICATE,
                statement: $alreadyPosted,
                duplicateRows: $alreadyPosted->row_count,
            );
        }

        [$statement, $landed] = $this->land($account, $validated, $fingerprint);

        $newRows = array_values(array_filter($landed, fn (BankTransaction $t): bool => $t->wasRecentlyCreated));
        $matchOutcomes = $this->match($landed);

        $statement->emitLedgerEvent('accounting.bank_statement.ingested', payload: [
            'bank_account_id' => $account->getId(),
            'source' => $this->source->value,
            'period_start' => $statement->period_start?->toDateString(),
            'period_end' => $statement->period_end?->toDateString(),
            'row_count' => $statement->row_count,
            'new_rows' => count($newRows),
            'match_outcomes' => $matchOutcomes,
        ]);

        return new BankStatementIngestResult(
            outcome: BankStatementIngestOutcomeEnum::POSTED,
            statement: $statement,
            newRows: count($newRows),
            duplicateRows: count($landed) - count($newRows),
            matchOutcomes: $matchOutcomes,
        );
    }

    private function resolveAccount(): BankAccount
    {
        $last4 = $this->statement->accountLast4();
        $currency = $this->statementCurrency();

        if ($this->bankAccount !== null) {
            $this->assertStatementBelongsTo($this->bankAccount, $last4, $currency);

            return $this->bankAccount;
        }

        $candidates = $last4 === null ? collect() : BankAccount::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->where('account_number_last4', $last4)
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->get();

        if ($candidates->count() !== 1) {
            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::ACCOUNT_NOT_FOUND,
                $candidates->isEmpty()
                    ? sprintf('No active bank account ends in %s%s.', $last4 ?? '(no account number on the statement)', $currency ? " ({$currency})" : '')
                    : sprintf('%d bank accounts end in %s; say which one this statement is for.', $candidates->count(), $last4),
            );
        }

        return $candidates->first();
    }

    private function assertStatementBelongsTo(BankAccount $account, ?string $last4, ?string $currency): void
    {
        if ($last4 !== null && $account->account_number_last4 !== null && $account->account_number_last4 !== $last4) {
            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::ACCOUNT_MISMATCH,
                "The statement is for an account ending in {$last4}, but {$account->account_name} ends in {$account->account_number_last4}.",
            );
        }

        if ($currency !== null && strtoupper($account->currency) !== $currency) {
            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::CURRENCY_MISMATCH,
                "The statement is in {$currency}, but {$account->account_name} is a {$account->currency} account.",
            );
        }
    }

    private function statementCurrency(): ?string
    {
        $currency = Str::trimToNull($this->statement->currency);

        return $currency === null ? null : strtoupper($currency);
    }

    /**
     * @return array{0: BankStatement, 1: list<BankTransaction>}
     */
    private function land(BankAccount $account, ParsedBankStatement $validated, string $fingerprint): array
    {
        return DB::connection('accounting')->transaction(function () use ($account, $validated, $fingerprint): array {
            $statement = $this->newStatement($account);
            $statement->status = BankStatementStatusEnum::POSTED;
            $statement->period_start = $validated->periodStart ?? $validated->lines[0]->date;
            $statement->period_end = $validated->periodEnd ?? $validated->lines[array_key_last($validated->lines)]->date;
            $statement->opening_balance = $validated->openingBalance;
            $statement->closing_balance = $validated->closingBalance;
            $statement->row_count = count($validated->lines);
            $statement->fingerprint = $fingerprint;
            $statement->save();

            $landed = [];
            $occurrences = [];

            foreach ($validated->lines as $line) {
                $landed[] = new CreateBankTransactionAction(
                    data: $this->transactionData(
                        $account,
                        $statement,
                        $line,
                        $this->externalId($account, $line, $occurrences)
                    ),
                    user: $this->user,
                )->execute();
            }

            $statement->new_row_count = count(array_filter($landed, fn (BankTransaction $t): bool => $t->wasRecentlyCreated));
            $statement->save();

            return [$statement, $landed];
        });
    }

    private function transactionData(
        BankAccount $account,
        BankStatement $statement,
        BankStatementLine $line,
        string $externalId
    ): BankTransactionData {
        $amount = round(abs($line->amount), 4);

        return new BankTransactionData(
            app: $this->app,
            company: $this->company,
            bankAccount: $account,
            postedAt: $line->date->copy()->startOfDay(),
            transactionDate: $line->date->copy()->startOfDay(),
            direction: $line->direction(),
            amountNative: $amount,
            currency: $account->currency,
            // No FX source for statement accounts yet (plan §12): base = native until one exists.
            amountBase: $amount,
            // Statement exports rarely have a payee column; their description ("PAGO CLARO DOMINICANA") is
            // where the counterparty is, and the matcher needs one to settle anything.
            counterpartyName: $line->counterparty ?? ($line->description === null ? null : mb_substr($line->description, 0, 191)),
            memo: $line->description,
            rawPayload: $line->raw === [] ? null : $line->raw,
            source: BankStatement::TRANSACTION_SOURCE,
            externalId: $externalId,
            metadata: array_filter([
                'bank_reference' => $line->reference,
                'running_balance' => $line->runningBalance,
            ], fn (mixed $value): bool => $value !== null),
            bankStatementId: $statement->getId(),
        );
    }

    /**
     * Two identical rows on one day (two $5 fees, no running balance to tell them apart) are two
     * movements, so a per-statement occurrence counter joins the key. It stays stable across an
     * overlapping statement as long as both contain the whole day.
     *
     * @param array<string, int> $occurrences
     */
    private function externalId(BankAccount $account, BankStatementLine $line, array &$occurrences): string
    {
        $reference = Str::trimToNull($line->reference);

        $base = $reference !== null
            ? 'ref:' . (strlen($reference) > 80 ? sha1($reference) : $reference)
            : 'h:' . sha1(implode('|', [
                $account->getId(),
                $line->date->toDateString(),
                number_format($line->amount, 2, '.', ''),
                Str::lowerTrim(preg_replace('/\s+/', ' ', (string) $line->description)),
                $line->runningBalance === null ? '' : number_format($line->runningBalance, 2, '.', ''),
            ]));

        $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;

        return 'stmt:' . $account->getId() . ':' . $base . ($occurrences[$base] > 1 ? '#' . $occurrences[$base] : '');
    }

    /**
     * Runs the matcher on every row this statement delivered that nothing has explained yet — new rows,
     * and overlap rows an earlier run could not match. One row failing never stops the rest.
     *
     * @param list<BankTransaction> $transactions
     * @return array<string, int>
     */
    private function match(array $transactions): array
    {
        $outcomes = [];

        foreach ($transactions as $transaction) {
            if ($transaction->isAccountedFor()) {
                continue;
            }

            try {
                $outcome = new MatchBankTransactionAction($transaction, $this->user)->execute()->value;
            } catch (Throwable $e) {
                report($e);
                $outcome = 'match_failed';
            }

            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        return $outcomes;
    }

    private function recordRejection(?BankAccount $account, BankStatementRejectedException $e): BankStatement
    {
        $statement = $this->newStatement($account);
        $statement->status = BankStatementStatusEnum::REJECTED;
        $statement->rejected_reason_code = $e->reason;
        $statement->rejected_reason = $e->getMessage();
        $statement->row_count = count($this->statement->lines);
        $statement->opening_balance = $this->statement->openingBalance;
        $statement->closing_balance = $this->statement->closingBalance;
        $statement->period_start = $this->statement->periodStart;
        $statement->period_end = $this->statement->periodEnd;
        $statement->save();

        $statement->emitLedgerEvent(
            'accounting.bank_statement.rejected',
            status: EventStatusEnum::ERROR,
            payload: [
                'bank_account_id' => $account?->getId(),
                'source' => $this->source->value,
                'reason_code' => $e->reason->value,
                'reason' => $e->getMessage(),
            ],
        );

        return $statement;
    }

    private function newStatement(?BankAccount $account): BankStatement
    {
        $statement = new BankStatement();
        $statement->apps_id = $this->app->getId();
        $statement->companies_id = $this->company->getId();
        $statement->users_id = $this->user?->getId();
        $statement->bank_account_id = $account?->getId();
        $statement->currency = $account?->currency ?? $this->statementCurrency();
        $statement->account_number_last4 = $this->statement->accountLast4() ?? $account?->account_number_last4;
        $statement->filesystem_id = $this->file?->getKey();
        $statement->source = $this->source;

        return $statement;
    }

    private function fingerprint(BankAccount $account, ParsedBankStatement $validated): string
    {
        $rows = array_map(fn (BankStatementLine $line): string => implode('|', [
            $line->date->toDateString(),
            number_format($line->amount, 2, '.', ''),
            (string) $line->reference,
            $line->runningBalance === null ? '' : number_format($line->runningBalance, 2, '.', ''),
        ]), $validated->lines);

        return sha1($account->getId() . "\n" . implode("\n", $rows));
    }

    private function postedWithFingerprint(string $fingerprint): ?BankStatement
    {
        return BankStatement::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('fingerprint', $fingerprint)
            ->where('status', BankStatementStatusEnum::POSTED->value)
            ->where('is_deleted', false)
            ->first();
    }
}
