<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Mercury;

use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Closure;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mercury\Actions\PullMercuryAccountsAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryCardsAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryCustomersAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryInvoicesAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryReceiptsAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryStatementsAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryTransactionsAction;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mercury\Services\MercuryService;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\PostOpeningBalanceAction;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Ledger\Services\GlOwnershipService;
use Throwable;

/**
 * The nightly recovery pull. Webhooks carry the day-to-day feed; this exists because Mercury has NO replay or
 * backfill API — it retries a delivery for ~a day, and a 4xx gets no retry at all. Anything dropped past that
 * is gone unless something goes looking, which is why the lookback is wider than Mercury's retry window.
 *
 * Every step is idempotent, so running it twice is free.
 */
class PullMercuryCommand extends Command
{
    use KanvasJobsTrait;

    /** Wider than Mercury's ~1-day retry window, so a permanently-dropped event still gets picked up. */
    private const int LOOKBACK_DAYS = 7;

    protected $signature = 'kanvas:mercury-pull
                            {--company_id= : Pull one company instead of every enabled tenant}
                            {--lookback= : Override the lookback window in days}
                            {--with-ar : Also pull Mercury AR customers and invoices}';

    protected $description = 'Recovery sweep for the Mercury bank feed: re-pull, re-match, refresh balances.';

    public function handle(): int
    {
        $lookback = (int) ($this->option('lookback') ?: self::LOOKBACK_DAYS);
        $companies = $this->targetCompanies();

        if ($companies === []) {
            $this->info('No companies have Mercury enabled.');

            return self::SUCCESS;
        }

        foreach ($companies as [$app, $company]) {
            try {
                $this->pullCompany($app, $company, $lookback);
            } catch (Throwable $e) {
                // One tenant's broken credentials must not stop every other tenant's books being reconciled.
                report($e);
                $this->error("company {$company->getId()}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function pullCompany(Apps $app, Companies $company, int $lookback): void
    {
        // Bouncer's scope is process-global and the worker is long-lived: without this, the previous tenant's
        // scope leaks into this one's queries.
        $this->overwriteAppService($app);

        if (! new GlOwnershipService()->kanvasOwnsGl($company)) {
            $this->warn("company {$company->getId()}: an ERP owns the GL; skipping.");

            return;
        }

        $user = $company->user;
        $accounts = new PullMercuryAccountsAction($app, $company, $user)->execute();

        $landed = 0;
        foreach ($accounts as $bankAccount) {
            $landed += count(
                new PullMercuryTransactionsAction(
                    bankAccount: $bankAccount,
                    user: $user,
                    initialLookbackDays: $lookback,
                )->execute()
            );

            $this->attempt($company, 'receipts', fn () => new PullMercuryReceiptsAction($bankAccount)->execute());
            $this->attempt($company, 'cards', fn () => new PullMercuryCardsAction($bankAccount)->execute());
            $this->attempt($company, 'statements', fn () => new PullMercuryStatementsAction($bankAccount)->execute());
        }

        if ($this->option('with-ar')) {
            $this->pullAr($app, $company, $user);
        }

        $matched = $this->matchOutstanding($app, $company);

        // Anchors the GL to what the bank actually says. A no-op when nothing has drifted.
        foreach ($accounts as $bankAccount) {
            new PostOpeningBalanceAction(
                bankAccount: $bankAccount,
                bankBalance: (float) $bankAccount->current_balance_native,
                user: $user,
            )->execute();
        }

        $this->info(sprintf(
            'company %d: %d accounts, %d transactions landed, %d matched.',
            $company->getId(),
            count($accounts),
            $landed,
            $matched,
        ));
    }

    /**
     * Opt-in, because it does more than mirror: an invoice raised in the Mercury UI gets ISSUED here, posting
     * DR AR / CR Revenue. Correct, but a nightly job shouldn't start writing to a tenant's ledger unasked.
     */
    private function pullAr(Apps $app, Companies $company, ?UserInterface $user): void
    {
        $this->attempt($company, 'ar customers', fn () => new PullMercuryCustomersAction($app, $company, $user)->execute());
        $this->attempt($company, 'ar invoices', fn () => new PullMercuryInvoicesAction($app, $company, $user)->execute());
    }

    /**
     * Supporting documents must never sink the pull — an unset S3 key once aborted a whole company and took
     * its transactions, matching and balances with it. The books are the point.
     */
    private function attempt(Companies $company, string $what, Closure $pull): void
    {
        try {
            $pull();
        } catch (Throwable $e) {
            report($e);
            $this->warn("company {$company->getId()}: {$what} skipped ({$e->getMessage()})");
        }
    }

    /** A bill entered today can settle a payment the bank reported last week; nothing else revisits those. */
    private function matchOutstanding(Apps $app, Companies $company): int
    {
        $outstanding = BankTransaction::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('match_status', BankTransactionMatchStatusEnum::UNMATCHED->value)
            ->whereNull('journal_entry_id')
            ->get();

        $matched = 0;

        foreach ($outstanding as $transaction) {
            new MatchBankTransactionAction($transaction, $company->user)->execute();
            $matched++;
        }

        return $matched;
    }

    /**
     * @return list<array{0: Apps, 1: Companies}>
     */
    private function targetCompanies(): array
    {
        $query = Companies::query()->where('is_deleted', false);

        if ($this->option('company_id')) {
            $query->where('id', (int) $this->option('company_id'));
        }

        $targets = [];

        foreach ($query->get() as $company) {
            if (! MercuryService::isEnabled($company)) {
                continue;
            }

            $appId = (int) $company->get(ConfigurationEnum::SYNC_APP_ID->value);

            if ($appId <= 0) {
                continue;
            }

            $targets[] = [Apps::getById($appId), $company];
        }

        return $targets;
    }
}
