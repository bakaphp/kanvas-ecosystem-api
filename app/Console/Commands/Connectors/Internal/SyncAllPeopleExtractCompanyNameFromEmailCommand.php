<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Internal;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Activities\ExtractCompanyNameFromPeopleEmailActivity;
use Kanvas\Connectors\Internal\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Models\StoredWorkflow;

class SyncAllPeopleExtractCompanyNameFromEmailCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:internal-people-email-extract-sync {app_id} {company_id} {total=150} {perPage=50}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all people in company and extract company name from email';

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

        $this->line("Syncing people for company {$company->name} from app {$app->name}, total {$total}, per page {$perPage}");

        // Fetch people count for progress bar
        $peopleCount = People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted(0)
            ->count();

        // Add a progress bar
        $this->output->progressStart($peopleCount);

        $exportActivity = new ExtractCompanyNameFromPeopleEmailActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted(0)
            ->orderBy('peoples.id', 'DESC')
            ->limit($total)
            ->chunk($perPage, function ($peoples) use ($exportActivity, $app) {
                foreach ($peoples as $people) {
                    $hasCustomField = $people->get(ConfigurationEnum::INTERNAL_EMAIL_DOMAIN_DATA_ENRICHMENT_CUSTOM_FIELDS->value);
                    if ($hasCustomField) {
                        $this->output->progressAdvance(); // Advance the progress bar for each person

                        continue;
                    }

                    //$this->line("Syncing people {$people->id}: {$people->firstname} {$people->lastname}");

                    $result = $exportActivity->execute(
                        people: $people,
                        app: $app,
                        params: []
                    );

                    //$this->line("Process people {$people->id}: {$people->firstname} {$people->lastname} result: " . json_encode($result));

                    $this->output->progressAdvance(); // Advance the progress bar for each person
                }
            });

        $this->output->progressFinish(); // Finish the progress bar after all people are processed

        $this->line("All people for company {$company->name} from app {$app->name} synced");
    }
}
