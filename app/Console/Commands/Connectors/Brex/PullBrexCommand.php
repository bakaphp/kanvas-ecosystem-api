<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Brex;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Brex\Actions\PullBrexAccountsAction;
use Kanvas\Connectors\Brex\Actions\PullBrexTransactionsAction;
use Kanvas\Connectors\Brex\DataTransferObject\BrexAccount;
use Kanvas\Connectors\Brex\Enums\ConfigurationEnum;
use Kanvas\Connectors\Brex\Services\BrexService;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\PostOpeningBalanceAction;
use Kanvas\Scribe\Ledger\Services\GlOwnershipService;
use Throwable;

/**
 * The Brex bank feed. Unlike Mercury there is no transaction webhook wired, so this pull IS the feed, not a
 * recovery sweep. Every step is idempotent, so running it twice is free.
 */
class PullBrexCommand extends Command
{
    use KanvasJobsTrait;

    private const int LOOKBACK_DAYS = 7;

    protected $signature = 'kanvas:brex-pull
                            {--company_id= : Pull one company instead of every enabled tenant}
                            {--lookback= : Override the first-pull lookback window in days}';

    protected $description = 'Pull the Brex cash and card feed into Scribe, match it, refresh balances.';

    public function handle(): int
    {
        $lookback = (int) ($this->option('lookback') ?: self::LOOKBACK_DAYS);
        $companies = $this->targetCompanies();

        if ($companies === []) {
            $this->info('No companies have Brex enabled.');

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
        $this->overwriteAppService($app);

        if (! new GlOwnershipService()->kanvasOwnsGl($company)) {
            $this->warn("company {$company->getId()}: an ERP owns the GL; skipping.");

            return;
        }

        $user = $company->user;
        $accounts = new PullBrexAccountsAction($app, $company, $user)->execute();

        $landed = [];
        foreach ($accounts as $bankAccount) {
            $landed = [
                ...$landed,
                ...new PullBrexTransactionsAction(
                    bankAccount: $bankAccount,
                    user: $user,
                    initialLookbackDays: $lookback,
                )->execute(),
            ];
        }

        $matched = 0;
        foreach ($landed as $transaction) {
            if ($transaction->isAccountedFor()) {
                continue;
            }

            new MatchBankTransactionAction($transaction, $user)->execute();
            $matched++;
        }

        // Anchors cash GL to what Brex reports. Not the card row: the sign Brex uses for a card balance owed is
        // not documented, and guessing it wrong would post a phantom opening balance to the liability.
        foreach ($accounts as $bankAccount) {
            if ($bankAccount->external_id === BrexAccount::CARD_EXTERNAL_ID) {
                continue;
            }

            new PostOpeningBalanceAction(
                bankAccount: $bankAccount,
                bankBalance: (float) $bankAccount->current_balance_native,
                user: $user,
            )->execute();
        }

        $this->info(sprintf(
            'company %d: %d accounts, %d transactions landed, %d sent to the matcher.',
            $company->getId(),
            count($accounts),
            count($landed),
            $matched,
        ));
    }

    /**
     * @return list<array{0: Apps, 1: Companies}>
     */
    private function targetCompanies(): array
    {
        $query = Companies::getByCustomFieldBuilder(ConfigurationEnum::SYNC_ENABLED->value, '1');

        if ($this->option('company_id')) {
            $query->where('companies.id', (int) $this->option('company_id'));
        }

        $targets = [];

        foreach ($query->get() as $company) {
            if (! BrexService::isEnabled($company)) {
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
