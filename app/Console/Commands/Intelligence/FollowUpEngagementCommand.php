<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\PullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Intelligence\PipelinesStages\Actions\FollowUpEngagementAction;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;

class FollowUpEngagementCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:notification-engagement {apps*} {--company_id=} {--date=} {--ignore-have-follow-up=0}';

    protected $description = 'Refresh the content of a session by its ID';

    public function handle(): void
    {
        $apps = $this->argument('apps');
        $stages = PipelineStage::join('pipelines', 'pipelines.id', '=', 'pipelines_stages.pipelines_id')
            ->whereNotNull('pipelines_stages.config')
            ->whereIn('pipelines.apps_id', $apps)
            ->when($this->option('company_id'), function (Builder $query) {
                return $query->where('pipelines.companies_id', '=', $this->option('company_id'));
            })
            ->select('pipelines_stages.*')
            ->cursor();

        $whereNotIn = [];

        foreach ($stages as $stage) {
            $config = $stage->config;

            if (isset($config['notification_engagement_rules']) && $config['notification_engagement_rules']) {
                $leads = Lead::where('pipeline_stage_id', '=', $stage->id)
                    ->where('leads_status_id', '<=', 2) // only open leads
                    ->where('is_deleted', '=', 0)
                    // ->whereIn('id', [525873,525867,509766,513064,513546])
                    ->where('created_at', '>=', $this->option('date'))
                    ->whereNotIn('id', $whereNotIn)
                    ->cursor();

                foreach ($leads as $lead) {
                    $this->overwriteAppService($lead->app);
                    $this->reSyncLead($lead);
                    $lead->refresh();

                    $shouldSkip = $lead->get(ConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value) === null
 || ($lead->get(EnumsConfigurationEnum::MUTE_AI_AGENT->value) && (int) $lead->get(EnumsConfigurationEnum::MUTE_AI_AGENT->value) === 0) || $lead->get(ConfigurationEnum::FIRST_MESSAGE->value) === null
                                    || $lead->isActive() === false || $lead->hasBeenContacted();

                    $haveCompanyFollowUp = $lead->company->get(CompanyConfigurationEnum::HAVE_FOLLOW_UP->value);

                    $ignoreFollowUp = (bool)$this->option('ignore-have-follow-up');

                    if ($shouldSkip) {
                        continue;
                    } elseif ($haveCompanyFollowUp && ! $ignoreFollowUp) {
                        break;
                    }

                    $hoursTool = new CompanyWorkHoursTool($lead)->execute();
                    if ($hoursTool['status'] !== 'work_hours') {
                        $this->info('Skipping lead ID ' . $lead->id . ' ' . $lead->people->name . '  - outside work hours');
                        $this->line('  Status: ' . $hoursTool['status']);
                        $this->line('  Day: ' . $hoursTool['weekday']);
                        $this->line('  Hours: ' . $hoursTool['opens_at_local'] . ' - ' . $hoursTool['closes_at_local']);
                        $this->line('  Next open: ' . $hoursTool['next_open_human']);

                        continue;
                    }

                    //how do we avoid sending notifications for leads that haven'b been contacted
                    try {
                        $result = new FollowUpEngagementAction($lead)->execute();
                    } catch (Exception $e) {
                        $this->error('Error processing lead ID ' . $lead->id . ': ' . $e->getMessage());
                        report($e);

                        continue;
                    }

                    $whereNotIn[] = $lead->id;
                    sleep(2); // to avoid hitting rate limits

                    if ($result === null || empty($result)) {
                        continue;
                    }
                    $this->info('Processed lead ID: ' . $lead->id);
                }
            }
        }
    }

    protected function reSyncLead(Lead $lead): array
    {
        $company = $lead->company;
        $app = $lead->app;
        $leadId = $lead->get(CustomFieldEnum::LEAD_ID->value) ?? $lead->get(EnumsCustomFieldEnum::LEADS->value);
        $user = $lead->owner;

        if ($leadId === null || $user === null) {
            return [];
        }

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )

        if ($isElead) {
            return new PullLeadAction(
                $app,
                $company,
                $user
            )->execute([], $leadId);
        } elseif ($isVinSolutions) {
            return new ActionsPullLeadAction(
                $app,
                $company,
                $user
            )->execute(
                lead: $lead,
                leadId: (int) $leadId,
            );
        }

        return [];
    }
}
