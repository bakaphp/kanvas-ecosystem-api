<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflow;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\Actions\EnsureIntegrationDefaultRulesAction;

/**
 * Backfill for tenants that connected an integration before handlers declared default rules —
 * the SalesAssist dealers and Zoho tenants whose pull/push the resolver used to hard-code.
 * `integration_companies` carries no apps_id, so the app is an argument and the companies are the
 * app's own.
 */
class SeedIntegrationDefaultRulesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:workflow-seed-default-rules {app_id} {--company_id= : only this company}';

    protected $description = 'Create the default pull/push rules every active integration declares for the companies of an app';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $created = 0;
        $rows = 0;
        $skipped = 0;
        $seed = function (array $companyIds) use ($app, &$created, &$rows, &$skipped): void {
            IntegrationsCompany::query()
                ->whereIn('companies_id', $companyIds)
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->with(['integration', 'company', 'region'])
                ->chunkById(200, function ($integrationCompanies) use ($app, &$created, &$rows, &$skipped) {
                    foreach ($integrationCompanies as $integrationCompany) {
                        // A rule records who created it; a company with no owner and no app owner has nobody.
                        $user = $integrationCompany->company?->user ?? $app->user;

                        if ($user === null) {
                            $skipped++;

                            continue;
                        }

                        $rows++;

                        $rules = new EnsureIntegrationDefaultRulesAction($integrationCompany, $app, $user)->execute();

                        foreach ($rules as $rule) {
                            $created++;
                            $this->line(sprintf(
                                '%s / company %d → rule #%d %s',
                                $integrationCompany->integration->name,
                                $integrationCompany->companies_id,
                                $rule->getId(),
                                $rule->name
                            ));
                        }
                    }
                });
        };

        if ($this->option('company_id') !== null) {
            $seed([(int) $this->option('company_id')]);
        } else {
            // A company belongs to an app through user_company_apps; `companies` carries no apps_id.
            // Chunked: a large app has more companies than one IN list can hold.
            UserCompanyApps::query()
                ->where('apps_id', $app->getId())
                ->where('is_deleted', 0)
                ->select('companies_id')
                ->distinct()
                ->orderBy('companies_id')
                ->chunk(1000, fn ($chunk) => $seed($chunk->pluck('companies_id')->all()));
        }

        $this->info("{$rows} active integrations visited, {$created} default rules ensured, {$skipped} skipped (no company or owner) for app {$app->getId()}");

        return self::SUCCESS;
    }
}
