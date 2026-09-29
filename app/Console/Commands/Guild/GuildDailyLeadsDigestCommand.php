<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Actions\GenerateDailyLeadsDigestAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class GuildDailyLeadsDigestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-guild:daily-leads-digest {--app_id=} {--company_id=} {--hours=} {--dry-run}';

    protected $description = 'Generate and send configured daily Guild leads digests';

    public function handle(): int
    {
        $appId = $this->option('app_id');
        $companyId = $this->option('company_id');
        $hoursOption = $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');
        $failures = 0;
        $processed = 0;

        $apps = Apps::query()
            ->where('is_actived', 1)
            ->where('is_deleted', 0)
            ->when($appId !== null && $appId !== '', fn ($query) => $query->where('id', (int) $appId))
            ->orderBy('id')
            ->get();

        foreach ($apps as $app) {
            $companyLinks = UserCompanyApps::query()
                ->where('apps_id', $app->getId())
                ->where('is_deleted', 0)
                ->when($companyId !== null && $companyId !== '', fn ($query) => $query->where('companies_id', (int) $companyId))
                ->with('company')
                ->get();

            foreach ($companyLinks as $companyLink) {
                $company = $companyLink->company;
                if (! $company instanceof Companies || ! $company->is_active || $company->is_deleted) {
                    continue;
                }

                $enabled = $company->get(ConfigurationEnum::DAILY_LEADS_DIGEST_ENABLED->value)
                    ?? $app->get(ConfigurationEnum::DAILY_LEADS_DIGEST_ENABLED->value);
                if (! $this->isDigestEnabled($enabled)) {
                    continue;
                }

                $configuredHours = $hoursOption !== null && $hoursOption !== ''
                    ? (int) $hoursOption
                    : (int) ($company->get(ConfigurationEnum::DAILY_LEADS_DIGEST_HOURS->value)
                        ?? $app->get(ConfigurationEnum::DAILY_LEADS_DIGEST_HOURS->value)
                        ?? 24);
                $hours = max(1, $configuredHours);

                try {
                    $this->overwriteAppService($app);
                    $digest = new GenerateDailyLeadsDigestAction($app, $company, $hours);
                    $result = $digest->execute($dryRun);
                    $processed++;
                    $deliveryStatus = $result['total'] === 0
                        ? ' not sent (0 leads)'
                        : ($dryRun
                            ? ' dry-run (delivery skipped)'
                            : ($result['sent'] ? ' sent' : ' not sent (no recipients configured)'));
                    $this->info(sprintf(
                        '%s app=%d company=%d leads=%d suspicious_emails=%d%s',
                        $dryRun ? 'Would send' : 'Processed',
                        $app->getId(),
                        $company->getId(),
                        $result['total'],
                        $result['suspicious_emails_count'],
                        $deliveryStatus
                    ));
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error(sprintf(
                        'Failed app=%d company=%d: %s',
                        $app->getId(),
                        $company->getId(),
                        $exception->getMessage()
                    ));
                    report($exception);
                }
            }
        }

        $this->line(sprintf('Daily leads digest: %d processed, %d failed.', $processed, $failures));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isDigestEnabled(mixed $value): bool
    {
        return is_scalar($value) && filter_var((string) $value, FILTER_VALIDATE_BOOLEAN);
    }
}
