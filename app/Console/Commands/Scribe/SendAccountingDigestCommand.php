<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Digest\Actions\SendAccountingDigestAction;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Throwable;

/**
 * Fans the AR/AP digest out over every (app, company) with an active AP or AR agent. Discovers tenants from
 * the agents, so a company without accounting agents costs nothing.
 */
class SendAccountingDigestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:scribe:send-accounting-digest
        {--app= : Restrict to a single apps_id}
        {--company= : Restrict to a single companies_id}
        {--date= : ISO date (Y-m-d) the digest covers. Defaults to yesterday.}';

    protected $description = 'Email the daily AR/AP digest to the people each company\'s accounting agents report to.';

    public function handle(): int
    {
        $day = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::yesterday();

        $tuples = $this->tenants();

        if ($tuples->isEmpty()) {
            $this->info('No company has an active AP or AR agent — nothing to digest.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($tuples as $row) {
            try {
                /** @var Apps $app */
                $app = Apps::getById((int) $row->apps_id);
                // Per tuple: the worker is long-lived and Bouncer's scope leaks from whatever ran last.
                $this->overwriteAppService($app);
                /** @var Companies $company */
                $company = Companies::getById((int) $row->companies_id);

                $recipients = new SendAccountingDigestAction($app, $company, $day)->execute();
                $sent += $recipients;

                $this->line(sprintf('  app=%-3d company=%-4d recipients=%d', $row->apps_id, $row->companies_id, $recipients));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('  app=%d company=%d failed: %s', $row->apps_id, $row->companies_id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Accounting digest for %s: %d email(s) across %d tenant(s), %d failed.',
            $day->toDateString(),
            $sent,
            $tuples->count(),
            $failed
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, Agent>
     */
    private function tenants(): Collection
    {
        $query = Agent::query()
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->whereHas('type', fn ($type) => $type->whereIn('handler', AccountingAgentLocatorService::HANDLERS));

        if ($this->option('app') !== null) {
            $query->where('apps_id', (int) $this->option('app'));
        }

        if ($this->option('company') !== null) {
            $query->where('companies_id', (int) $this->option('company'));
        }

        return $query->select('apps_id', 'companies_id')->distinct()->get();
    }
}
