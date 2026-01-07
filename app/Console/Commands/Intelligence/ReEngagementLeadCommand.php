<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;

class ReEngagementLeadCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:re-engagement-lead';
    protected $description = 'Re-engagement leads command';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $companies = Companies::getByCustomFieldBuilder(CompanyConfigurationEnum::REENGAGEMENT_LEAD_TIME->value, null)->get();
        foreach ($companies as $company) {
            $app = Apps::getById((int) $appId);
            $leads = LeadsRepository::getActiveLeadByCompany($company);
            foreach ($leads as $lead) {
                foreach ($lead->socialChannels as $channel) {
                }
            }
        }
    }
}
