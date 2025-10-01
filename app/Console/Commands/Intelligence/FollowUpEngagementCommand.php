<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
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
            ->cursor();

        foreach ($stages as $stage) {
            $config = $stage->config;
            if (isset($config['notification_engagement_rules']) && $config['notification_engagement_rules']) {
                $leads = Lead::where('pipeline_stage_id', '=', $stage->id)->cursor();
                foreach ($leads as $lead) {
                    (new FollowUpEngagementAction($lead))->execute();
                }
            }
        }
    }
}
