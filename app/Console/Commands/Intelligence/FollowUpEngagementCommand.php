<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
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
use Kanvas\Intelligence\FollowUp\Exceptions\FollowUpException;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\PipelinesStages\Actions\FollowUpEngagementAction;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Services\DailyReportService;

class FollowUpEngagementCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:notification-engagement {apps*} {--company_id=} {--date=} {--ignore-have-follow-up=0} {--ignore-first-message=0}';

    protected $description = 'Refresh the content of a session by its ID';

    public function handle(): void
    {
        $apps = $this->argument('apps');

        $stages = PipelineStage::join('pipelines', 'pipelines.id', '=', 'pipelines_stages.pipelines_id')
            ->whereIn('pipelines.apps_id', $apps)
            ->when($this->option('company_id'), function (Builder $query) {
                return $query->where('pipelines.companies_id', '=', $this->option('company_id'));
            })
            ->select('pipelines_stages.*')
            ->cursor();

        $whereNotIn = [];

        foreach ($stages as $stage) {
            $config = $stage->config;

            $followUpDay = FollowUpDay::where('pipeline_stages_id', $stage->getId())->first();
            if (! $followUpDay) {
                continue;
            }

            $leads = Lead::where('pipeline_stage_id', '=', $stage->id)
                ->where('leads_status_id', '<=', 2) // only open leads
                ->where('is_deleted', '=', 0)
                // ->whereIn('id', [525873,525867,509766,513064,513546])
                ->where('created_at', '>=', $this->option('date'))
                ->whereNotIn('id', $whereNotIn)
                ->orderBy('id', 'ASC')
                ->cursor();

            $this->info('Processing stage ID ' . $stage->id . ' - ' . $stage->name . ' for leads ' . count($leads->toArray()));
            foreach ($leads as $lead) {
                $this->overwriteAppService($lead->app);
                $this->reSyncLead($lead);
                $lead->refresh();

                $this->info('Processing lead ID ' . $lead->id . ' - ' . $lead->people->name);

                //$noAgentChannel = $lead->get(ConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value) === null;
                $muteAiAgent = $lead->isAiMuted();
                $ignoreFirstMessage = (bool) $this->option('ignore-first-message');
                $noFirstMessage = ! $ignoreFirstMessage && $lead->get(ConfigurationEnum::FIRST_MESSAGE->value) === null;
                $notActive = $lead->isActive() === false;
                $hasBeenContacted = $lead->hasBeenContacted();
                $leadTypes = $lead->company->get(ConfigurationEnum::FOLLOW_UP_LEAD_TYPE->value) ?? ['internet'];
                $notInternet = ! in_array(strtolower($lead->type?->name ?? ''), $leadTypes);

                /*      $this->line('  - No Agent Channel: ' . ($noAgentChannel ? 'true' : 'false'));
                     $this->line('  - Mute AI Agent: ' . ($muteAiAgent ? 'true' : 'false'));
                     $this->line('  - No First Message: ' . ($noFirstMessage ? 'true' : 'false'));
                     $this->line('  - Not Active: ' . ($notActive ? 'true' : 'false'));
                     $this->line('  - Has Been Contacted: ' . ($hasBeenContacted ? 'true' : 'false'));
                     $this->line("  - Not INTERNET type ({$lead->type?->name}): " . ($notInternet ? 'true' : 'false')); */

                $shouldSkip = $muteAiAgent || $noFirstMessage || $notActive || $hasBeenContacted || $notInternet;

                $haveCompanyFollowUp = $lead->company->get(CompanyConfigurationEnum::HAVE_FOLLOW_UP->value);

                $ignoreFollowUp = (bool)$this->option('ignore-have-follow-up');

                if ($shouldSkip) {
                    $this->info('Skipping lead ID ' . $lead->id . ' - ' . $lead->people->name . ' due to skip conditions.');
                    DailyReportService::track(
                        $lead->app,
                        $lead->company,
                        'ai_follow_up_engagement_skipped'
                    );

                    $key = match (true) {
                        $muteAiAgent => 'mute_ai_agent',
                        $noFirstMessage => 'no_first_message',
                        $notActive => 'not_active',
                        $hasBeenContacted => 'has_been_contacted',
                        default => 'not_internet_type',
                    };
                    DailyReportService::track(
                        $lead->app,
                        $lead->company,
                        'ai_follow_up_engagement_skip_reason_' . $key
                    );
                    $this->line('  - Skip Reason: ' . $key);

                    continue;
                } elseif ($haveCompanyFollowUp && ! $ignoreFollowUp) {
                    $this->info('Skipping lead ID ' . $lead->id . ' - ' . $lead->people->name . ' because company has follow up enabled.');

                    continue;
                } elseif ($haveCompanyFollowUp && ! $ignoreFollowUp) {
                    $this->info('Skipping lead ID ' . $lead->id . ' - ' . $lead->people->name . ' because company has follow up enabled.');

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
                    $this->info('Executing FollowUpEngagementAction for lead ID ' . $lead->id . ' - ' . $lead->people->name);
                    $result = new FollowUpEngagementAction($lead)->execute();
                } catch (FollowUpException $e) {
                    $this->info('Skipping lead ID ' . $lead->id . ': ' . $e->getMessage());

                    continue;
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
                $date = Carbon::now($lead->company->timezone)->format('Y-m-d H:i:s');
                $this->info('Processed lead ID: ' . $lead->id . "Date $date");
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
            )->execute([
                'entity_id' => (int) $leadId > 0 ? (int) $leadId : null,
            ], $lead);
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
