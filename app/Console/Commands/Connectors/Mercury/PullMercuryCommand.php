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
 * The nightly recovery pull. Webhooks carry the day-to-day feed; this exists because they can't be trusted
 * alone.
 *
 * Mercury retries a failed delivery 10 times over ~a day, so anything transient self-heals. But there is NO
 * replay or backfill API: if our endpoint is down longer than that (bad deploy, expired cert, DNS), those
 * events are gone permanently. Worse, a 4xx — say a bug in signature verification returning 401 — gets no
 * retry at all. This nightly sweep is the only thing that can find what was silently dropped, which is why
 * its lookback (7 days) is comfortably wider than Mercury's retry window.
 *
 * It also re-runs the matcher over anything still unmatched, so a bill entered today can settle a payment the
 * bank reported last week.
 *
 * Cheap to run twice if you'd rather: every step is idempotent.
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
        // The worker is long-lived and Bouncer's scope is process-global — without this, the previous
        // tenant's scope leaks into this one's queries.
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

            // Receipts, cards and statements are supporting documents, not accounting. A storage
            // misconfiguration must never stop the books being reconciled — which is exactly what happened
            // before this guard: an unset S3 key aborted the whole company and skipped transactions,
            // matching and balances with it.
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
     * Mirrors Mercury's AR side. Opt-in, because it does more than mirror: an invoice raised in the Mercury
     * UI doesn't exist on our books at all, so importing it ISSUES it here and posts DR AR / CR Revenue. That
     * is correct — a real invoice went to a real customer — but it is a write to the ledger, and a nightly job
     * should not start making those on a tenant's behalf without someone asking for it.
     */
    private function pullAr(Apps $app, Companies $company, ?UserInterface $user): void
    {
        $this->attempt($company, 'ar customers', fn () => new PullMercuryCustomersAction($app, $company, $user)->execute());
        $this->attempt($company, 'ar invoices', fn () => new PullMercuryInvoicesAction($app, $company, $user)->execute());
    }

    /**
     * Supporting data must never sink the pull. The books are the point; a card list that failed to refresh is
     * an inconvenience, an aborted reconciliation is an outage.
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

    /**
     * Re-runs the matcher over everything still unmatched — a bill entered today can settle a payment the
     * bank reported last week, and nothing else would ever revisit it.
     */
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
