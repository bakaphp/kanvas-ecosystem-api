<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Credit700;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Credit700\Enums\CustomFieldEnum;
use Kanvas\Connectors\Credit700\Services\CreditScoreService;
use Kanvas\Guild\Leads\Models\Lead;

class RegenerateLeadsLinkCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:credit700-regenerate-leads-link {app_id} {companies_id}';

    protected $description = 'Regenerate leads link for 700 credit';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('companies_id'));

        $query = Lead::getByCustomFieldBuilder(CustomFieldEnum::LEAD_PULL_CREDIT_HISTORY->value, null, $company);
        $cursor = $query->cursor();
        $totalLeads = $query->count();

        $this->output->progressStart($totalLeads);

        $creditScore = new CreditScoreService($app);
        foreach ($cursor as $lead) {
            $creditScore->regenerateLeadCreditHistoryUrl($lead);

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        return;
    }
}
