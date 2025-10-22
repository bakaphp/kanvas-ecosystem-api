<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\PipelinesStages\Actions\FollowUpEngagementAction;

class FollowUpEngagementCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:notification-engagement {apps*}';

    protected $description = 'Refresh the content of a session by its ID';

    public function handle(): void
    {
        $apps = $this->argument('apps');
        $stages = PipelineStage::join('pipelines', 'pipelines.id', '=', 'pipelines_stages.pipelines_id')
            ->whereNotNull('pipelines_stages.config')
            ->whereIn('pipelines.apps_id', $apps)
            ->select('pipelines_stages.*')
            ->cursor();

        $whereNotIn = [];
        foreach ($stages as $stage) {
            $config = $stage->config;

            if (isset($config['notification_engagement_rules']) && $config['notification_engagement_rules']) {
                $leads = Lead::where('pipeline_stage_id', '=', $stage->id)
                ->where('leads_status_id', '<=', 2) // only open leads
                ->whereIn('id', [525873,525867,509766,513064,513546])
                ->whereNotIn('id', $whereNotIn)
                ->cursor();

                foreach ($leads as $lead) {
                    $shouldSkip = $lead->get(ConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value) === null;
                    if ($shouldSkip) {
                        continue;
                    }

                    //how do we avoid sending notifications for leads that haven'b been contacted
                    $result = new FollowUpEngagementAction($lead)->execute();
                    $whereNotIn[] = $lead->id;
                    if ($result === null || empty($result)) {
                        continue;
                    }
                    $this->info('Processed lead ID: ' . $lead->id);
                }
            }
        }
    }
}
