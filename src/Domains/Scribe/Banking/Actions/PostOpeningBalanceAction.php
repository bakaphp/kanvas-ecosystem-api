<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntry as JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine as JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;
use Kanvas\Scribe\Ledger\Services\FiscalPeriodAutoOpenService;
use Spatie\LaravelData\DataCollection;

/**
 * Anchors a bank account's GL balance to what the bank actually says.
 *
 * A feed only ever imports a window of history — 90 days, 180 days, whatever the tenant asked for. So the GL
 * reflects the MOVEMENT inside that window, not the account's real balance. Without this, Cash — Checking
 * reads −8,020.32 while the bank says 17,761.88, and every balance sheet is wrong. The gap is simply the
 * money that was already sitting there before our first imported transaction.
 *
 *   opening = what the bank says today − what our imported transactions account for
 *
 * Posted as DR/CR against Opening Balance Equity, dated the day before the earliest imported transaction, so
 * a running balance reads correctly from day one.
 *
 * **Re-runnable, and it must be.** If someone later backfills more history, the true opening shifts. Rather
 * than editing the original entry (the ledger is immutable), this computes the difference against whatever
 * opening entries already exist and posts a top-up for the delta. Running it when nothing has changed posts
 * nothing.
 *
 * Works for credit cards too: Mercury reports a card's balance as negative when money is owed, which is
 * already the sign a liability account wants (a credit balance). No special-casing.
 */
class PostOpeningBalanceAction
{
    private const string SOURCE_TYPE = 'opening_balance';

    /** Anything under half a cent isn't a real discrepancy. */
    private const float TOLERANCE = 0.005;

    public function __construct(
        public readonly BankAccount $bankAccount,
        /** The account's true balance right now, as the bank reports it. */
        public readonly float $bankBalance,
        public readonly ?UserInterface $user = null,
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
        protected readonly FiscalPeriodAutoOpenService $periodAutoOpen = new FiscalPeriodAutoOpenService(),
    ) {
    }

    public function execute(): ?JournalEntry
    {
        $app = $this->bankAccount->app;
        $company = $this->bankAccount->company;
        $glAccountId = $this->bankAccount->gl_account_id;

        $adjustment = round($this->bankBalance - $this->currentGlBalance($glAccountId), 4);

        if (abs($adjustment) < self::TOLERANCE) {
            return null;
        }

        $postedAt = $this->openingDate();

        $this->periodAutoOpen->ensureOpenPeriodFor($app, $company, $postedAt, $this->user);

        $equityAccount = $this->accountResolver->bySubType(
            $app,
            $company,
            AccountSubTypeEnum::OPENING_BALANCE_EQUITY,
        );

        // A positive adjustment means the account holds more than our imported history explains, so it needs
        // a debit (asset up / liability down). Negative flips both sides.
        $isDebitToBank = $adjustment > 0;
        $magnitude = abs($adjustment);
        $currency = $this->bankAccount->currency;

        $memo = "Opening balance — {$this->bankAccount->account_name}";

        return DB::connection('accounting')->transaction(function () use (
            $app,
            $company,
            $glAccountId,
            $equityAccount,
            $isDebitToBank,
            $magnitude,
            $currency,
            $postedAt,
            $memo,
        ): JournalEntry {
            $lines = [
                new JournalEntryLineData(
                    account_id: $glAccountId,
                    debit_native: $isDebitToBank ? $magnitude : 0.0,
                    credit_native: $isDebitToBank ? 0.0 : $magnitude,
                    debit_base: $isDebitToBank ? $magnitude : 0.0,
                    credit_base: $isDebitToBank ? 0.0 : $magnitude,
                    currency: $currency,
                    fx_rate_to_base: 1.0,
                    sort_order: 0,
                    memo: $memo,
                ),
                new JournalEntryLineData(
                    account_id: $equityAccount->id,
                    debit_native: $isDebitToBank ? 0.0 : $magnitude,
                    credit_native: $isDebitToBank ? $magnitude : 0.0,
                    debit_base: $isDebitToBank ? 0.0 : $magnitude,
                    credit_base: $isDebitToBank ? $magnitude : 0.0,
                    currency: $currency,
                    fx_rate_to_base: 1.0,
                    sort_order: 1,
                    memo: $memo,
                ),
            ];

            return new PostJournalEntryAction(
                data: new JournalEntryData(
                    app: $app,
                    company: $company,
                    postedAt: $postedAt,
                    sourceType: self::SOURCE_TYPE,
                    lines: new DataCollection(JournalEntryLineData::class, $lines),
                    sourceId: $this->bankAccount->getId(),
                    memo: $memo,
                    source: $this->bankAccount->source,
                    origin: JournalEntryOriginEnum::EXTERNAL,
                    metadata: [
                        'bank_account_id' => $this->bankAccount->getId(),
                        'bank_balance' => $this->bankBalance,
                    ],
                ),
                postedByUser: $this->user,
            )->execute();
        });
    }

    /**
     * Net of everything already booked to this GL account — imported transactions AND any prior opening
     * entry. Comparing the bank's balance against this is what makes the action re-runnable: a second run
     * with unchanged history computes a zero adjustment and posts nothing.
     */
    private function currentGlBalance(int $glAccountId): float
    {
        $lines = JournalEntryLine::query()
            ->where('account_id', $glAccountId)
            ->whereIn(
                'journal_entry_id',
                JournalEntry::query()
                    ->where('apps_id', $this->bankAccount->apps_id)
                    ->where('companies_id', $this->bankAccount->companies_id)
                    ->select('id')
            )
            ->get();

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }

    /**
     * The day before our earliest imported transaction — the moment this balance was "already there".
     */
    private function openingDate(): Carbon
    {
        $earliest = BankTransaction::query()
            ->where('apps_id', $this->bankAccount->apps_id)
            ->where('companies_id', $this->bankAccount->companies_id)
            ->where('bank_account_id', $this->bankAccount->getId())
            ->min('posted_at');

        return $earliest !== null
            ? Carbon::parse((string) $earliest)->subDay()->startOfDay()
            : Carbon::now()->startOfDay();
    }
}
