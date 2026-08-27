<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\FollowUp;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Actions\PullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Exceptions\FollowUpException;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpLog;
use Kanvas\Intelligence\PipelinesStages\Actions\FollowUpEngagementAction;
use Kanvas\Intelligence\PipelinesStages\Actions\ManlyHondaFollowUpEngagementAction;
use Kanvas\Intelligence\PipelinesStages\Contracts\FollowUpTimeGateOverridable;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadClosingStatusAction;
use Kanvas\Services\DailyReportService;

/**
 * @deprecated v1 follow-up engine ships its own command —
 *             {@see \App\Console\Commands\Lead\DispatchLeadFollowUpsCommand}
 *             (hourly cron) and {@see \App\Console\Commands\Lead\LeadFollowUpDailySummaryCommand}
 *             (daily rollup). Slated for deletion — see
 *             docs/intelligence/follow-up-deprecation-spec.md kill list.
 */
class FollowUpEngagementCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:notification-engagement {apps*} {--company_id=} {--date=} {--lead_id=} {--ignore-time=0} {--template=0}';

    protected $description = 'Refresh the content of a session by its ID';

    public function handle(): void
    {
        $apps = $this->argument('apps');

        $leadId = $this->option('lead_id') !== null ? (int) $this->option('lead_id') : null;
        $ignoreTime = (bool) $this->option('ignore-time');

        // Single-lead manual trigger: pin the scan to the lead's current stage so
        // we don't cursor every stage in the app just to reach one lead.
        $targetStageId = $leadId ? Lead::find($leadId)?->pipeline_stage_id : null;

        $stages = PipelineStage::join('pipelines', 'pipelines.id', '=', 'pipelines_stages.pipelines_id')
            ->whereIn('pipelines.apps_id', $apps)
            ->when($this->option('company_id'), function (Builder $query) {
                return $query->where('pipelines.companies_id', '=', $this->option('company_id'));
            })
            ->when($targetStageId, function (Builder $query) use ($targetStageId) {
                return $query->where('pipelines_stages.id', '=', $targetStageId);
            })
            ->select('pipelines_stages.*')
            ->cursor();

        $whereNotIn = [];

        foreach ($stages as $stage) {
            $config = $stage->config;

            $stageCompany = Companies::getById((int) $stage->pipeline->companies_id);
            $isManlyHonda = (bool) $stageCompany->get(CompanyConfigurationEnum::MANLY_HONDA->value);

            $followUpDay = FollowUpDay::where('pipeline_stages_id', $stage->getId())->first();
            if (! $followUpDay && ! $isManlyHonda) {
                continue;
            }

            $leads = Lead::where('pipeline_stage_id', '=', $stage->id)
                ->where('leads_status_id', '<=', 2) // only open leads
                ->where('is_deleted', '=', 0)
                ->when(
                    $leadId,
                    fn (Builder $query) => $query->where('id', '=', $leadId),
                    fn (Builder $query) => $query->where('created_at', '>=', $this->option('date')),
                )
                ->whereNotIn('id', $whereNotIn)
                ->orderBy('id', 'ASC')
                ->cursor();

            $this->info('Processing stage ID ' . $stage->id . ' - ' . $stage->name . ' for leads ' . count($leads->toArray()));
            foreach ($leads as $lead) {
                $this->overwriteAppService($lead->app);
                // $this->reSyncLead($lead);
                $lead->refresh();
                new ApplyLeadClosingStatusAction($lead)->execute();

                $this->info('Processing lead ID ' . $lead->id . ' - ' . $lead->people->name);

                // Create initial log entry at command level
                $log = FollowUpLog::create([
                    'apps_id' => $lead->apps_id,
                    'companies_id' => $lead->companies_id,
                    'leads_id' => $lead->getId(),
                    'pipeline_stages_id' => $lead->stage->getId(),
                    'metadata' => [
                        'command_started_at' => now()->toDateTimeString(),
                        'stage_name' => $stage->name,
                    ],
                ]);

                if (! $lead->isAiFollowUpEnabled()) {
                    $this->info('Skipping lead ID ' . $lead->id . ' - ' . $lead->people->name . ' because ai_follow_up is not enabled.');

                    DailyReportService::track(
                        $lead->app,
                        $lead->company,
                        'ai_follow_up_engagement_skipped'
                    );
                    DailyReportService::track(
                        $lead->app,
                        $lead->company,
                        'ai_follow_up_engagement_skip_reason_ai_follow_up_not_enabled'
                    );

                    $log->update([
                        'metadata' => array_merge(
                            $log->metadata ?? [],
                            [
                                'skipped' => true,
                                'skip_reason' => 'ai_follow_up_not_enabled',
                                'ai_follow_up_value' => $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
                            ]
                        ),
                    ]);

                    continue;
                }

                //how do we avoid sending notifications for leads that haven'b been contacted
                try {
                    $this->info('Executing FollowUpEngagementAction for lead ID ' . $lead->id . ' - ' . $lead->people->name);
                    $followUpClass = match (true) {
                        $isManlyHonda => ManlyHondaFollowUpEngagementAction::class,
                        default => FollowUpEngagementAction::class,
                    };
                    $followUpAction = new $followUpClass($lead, $log);
                    if ($ignoreTime && $followUpAction instanceof FollowUpTimeGateOverridable) {
                        $followUpAction->withIgnoreTimeGate(true);
                    }
                    if ($this->option('template') != null && $this->option('template')) {
                        $followUpAction->setTemplate($this->option('template'));
                    }
                    $result = $followUpAction->execute();
                } catch (FollowUpException $e) {
                    $this->info('Skipping lead ID ' . $lead->id . ': ' . $e->getMessage());

                    // Log the exception
                    $log->update([
                        'error_message' => $e->getMessage(),
                        'metadata' => array_merge(
                            $log->metadata ?? [],
                            [
                                'exception_type' => 'FollowUpException',
                            ]
                        ),
                    ]);

                    continue;
                } catch (Exception $e) {
                    $this->error('Error processing lead ID ' . $lead->id . ': ' . $e->getMessage());
                    report($e);

                    // Log the exception
                    $log->update([
                        'error_message' => $e->getMessage(),
                        'metadata' => array_merge(
                            $log->metadata ?? [],
                            [
                                'exception_type' => get_class($e),
                                'exception_trace' => $e->getTraceAsString(),
                            ]
                        ),
                    ]);

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
