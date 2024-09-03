<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\WorkflowEnum;

class SyncAllPeopleInCompanyCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:guild-apollo-people-sync {app_id} {company_id} {total=2000} {perPage=200}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all leads from Zoho to this branch';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $perPage = (int) $this->argument('perPage');
        $total = (int) $this->argument('total');
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $rateLimit = 400; // Maximum API calls per hour
        $batchSize = 100; // Number of people to process per batch
        $cacheKey = 'api_rate_limit';
        $resetKey = 'api_rate_limit_reset';
        $timeWindow = 60 * 60; // 1 hour in seconds

        // Check the current count of API calls
        $currentCount = Cache::get($cacheKey, 0);
        $resetTimestamp = Cache::get($resetKey);

        $this->line('Syncing ' . $currentCount . ' all people in company ' . $company->name . ' from app ' . $app->name . ' total ' . $total . ' per page ' . $perPage);

        if ($resetTimestamp) {
            // Ensure $resetTimestamp is a Carbon instance
            $resetTime = Carbon::parse($resetTimestamp);
            $currentTimestamp = now()->timestamp;
            $waitTime = $resetTime->timestamp - $currentTimestamp;

            if ($currentCount >= $rateLimit && $waitTime > 0) {
                // If the limit is reached, calculate the remaining cooldown period
                $this->line("Rate limit reached. Please wait $waitTime seconds to run the process again.");

                return;
            }
        }

        People::fromApp($app)
            ->fromCompany($company)
            ->leftJoinSub(
                AppsCustomFields::select('entity_id')
                    ->where('name', ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value),
                'acf',
                'peoples.id',
                '=',
                'acf.entity_id'
            )
            ->whereNull('acf.entity_id') // Ensure only people without the custom field are listed
            ->orderBy('peoples.id', 'asc')
            ->chunk($batchSize, function ($peoples) use (&$currentCount, $rateLimit, $cacheKey, $resetKey, $timeWindow) {
                foreach ($peoples as $people) {
                    if ($currentCount >= $rateLimit) {
                        // If the rate limit is reached, stop the operation and set the cooldown period
                        Cache::put($resetKey, now()->addSeconds($timeWindow), $timeWindow);
                        echo "Rate limit reached. Please wait $timeWindow seconds to run the process again.";

                        return false; // Stop chunk processing
                    }

                    $this->line('Syncing people ' . $people->id . ' ' . $people->firstname . ' ' . $people->lastname);

                    //sync people
                    $people->fireWorkflow(
                        WorkflowEnum::UPDATED->value,
                        true,
                        [
                            'app' => $people->app,
                        ]
                    );
                    $people->clearLightHouseCache();

                    // Increment the API call counter for each person processed
                    $currentCount++;
                    Cache::put($cacheKey, $currentCount, $timeWindow);

                    // Optional: Add a small delay between requests to avoid bursts
                    usleep(100000); // 100ms delay between each request
                }
            });

        $this->line('All people in company ' . $company->name . ' from app ' . $app->name . ' synced');

        return;
    }
}
