<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Connectors\Apollo\Services\ApolloRateLimitService;
use Kanvas\Guild\Customers\Models\People;

class SyncAllPeopleInCompanyCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:guild-apollo-people-sync {app_id} {company_id} {total=150} {perPage=50} {--order=desc : Sort people by id (asc|desc)} {--cooldown=3 : Days to wait before retrying a person Apollo had no data for} {--force : Ignore the revalidation window and no-data cooldown; re-enrich everyone}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Enrich all people in a company directly from Apollo (no workflow/integration setup required)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $perPage = (int) $this->argument('perPage');
        $total = (int) $this->argument('total');
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $order = strtolower((string) $this->option('order')) === 'asc' ? 'ASC' : 'DESC';
        $force = (bool) $this->option('force');
        $cooldownDays = max(0, (int) $this->option('cooldown'));

        $rateLimit = new ApolloRateLimitService();

        // Hourly pacing is the command's own concern — a bulk loop must not hammer Apollo
        // even while we're under the daily cap. The daily cap itself lives in the shared
        // service (counted from the company report), so it agrees with the workflow path.
        $hourlyRateLimit = 400;
        $hourlyCacheKey = 'api_hourly_rate_limit_' . $app->getId();
        $resetHourlyKey = 'api_hourly_rate_limit_reset_' . $app->getId();
        $hourlyTimeWindow = 60 * 60;

        $resetHourlyTimestamp = Cache::get($resetHourlyKey, 0) instanceof Carbon ? Cache::get($resetHourlyKey, 0)->timestamp : 0;

        if (now()->timestamp >= $resetHourlyTimestamp) {
            Cache::put($hourlyCacheKey, 0, $hourlyTimeWindow);
        }

        $currentHourlyCount = Cache::get($hourlyCacheKey, 0);

        $this->line("Syncing people for company {$company->name} from app {$app->name}, total {$total}, per page {$perPage}, order {$order}");

        People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted(0)
            ->orderBy('peoples.id', $order)
            ->limit($total)
            ->chunk($perPage, function ($peoples) use ($app, $company, $rateLimit, $force, $cooldownDays, &$currentHourlyCount, $hourlyRateLimit, $hourlyCacheKey, $resetHourlyKey, $hourlyTimeWindow) {
                foreach ($peoples as $people) {
                    // Shared daily cap — once today's company total is reached, stop the run
                    // entirely (returning false halts further chunks).
                    if ($rateLimit->hasReachedDailyLimit($company)) {
                        $this->line('Daily Apollo enrichment limit reached. Stopping.');

                        return false;
                    }

                    // Already enriched inside the revalidation window — same gate the workflow uses.
                    if (! $force && $rateLimit->hasBeenScreenedRecently($people)) {
                        $this->line("Skipping people {$people->id}: enriched recently, within revalidation window");

                        continue;
                    }

                    // Apollo had no data on the last try — don't burn another credit until
                    // the cooldown lapses, unless --force overrides it.
                    if (! $force && EnrichPeopleFromApolloAction::isWithinNoDataCooldown($people, $cooldownDays)) {
                        $this->line("Skipping people {$people->id}: no Apollo data on last try, within {$cooldownDays}-day cooldown");

                        continue;
                    }

                    if ($currentHourlyCount >= $hourlyRateLimit) {
                        Cache::put($resetHourlyKey, now()->addSeconds($hourlyTimeWindow), $hourlyTimeWindow);
                        $this->line('Hourly rate limit reached. Waiting for reset...');
                        sleep($hourlyTimeWindow);

                        continue;
                    }

                    $this->line("Syncing people {$people->id}: {$people->firstname} {$people->lastname}");

                    // Enrich directly (same path as kanvas:guild-apollo-enrich-person) instead of
                    // firing the UPDATED workflow — the workflow route goes through executeIntegration
                    // which silently no-ops unless the company has the Apollo integration + a region
                    // configured. This backfill runs without any of that setup.
                    $result = new EnrichPeopleFromApolloAction($people, $app)->execute();
                    $this->line("  [{$result['status']}] {$result['message']}");

                    $currentHourlyCount++;
                    Cache::put($hourlyCacheKey, $currentHourlyCount, $hourlyTimeWindow);

                    // Dynamic delay based on remaining rate limit
                    $delay = $this->calculateDelay($currentHourlyCount, $hourlyRateLimit);
                    sleep($delay);
                }
            });

        $this->line("All people for company {$company->name} from app {$app->name} synced");
    }

    private function calculateDelay(int $currentCount, int $rateLimit): int
    {
        // Adjust delay dynamically to distribute requests evenly
        $remainingRequests = $rateLimit - $currentCount;
        $remainingTime = 60 * 60; // 1 hour in seconds

        return $remainingRequests > 0 ? intdiv($remainingTime, $remainingRequests) : 2;
    }
}
