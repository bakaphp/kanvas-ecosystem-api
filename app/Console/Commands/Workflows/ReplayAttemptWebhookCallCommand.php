<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\ReplayAttemptWebhookCallAction;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class ReplayAttemptWebhookCallCommand extends Command
{
    use KanvasJobsTrait;
    protected $signature = 'kanvas:replay-webhook {appsId} {--callId=} {--limit}';

    public function handle()
    {
        $appsId = $this->argument('appsId');
        $callId = $this->option('callId');
        $limit = $this->option('limit');

        $app = Apps::find($appsId);
        $this->overwriteAppService($app);

        $webhookCalls = ReceiverWebhookCall::whereRelation('receiverWebhook', 'apps_id', $appsId)
            ->when($callId, function ($query) use ($callId) {
                return $query->where('id', $callId);
            })->when($limit, function ($query) use ($limit) {
                return $query->limit($limit);
            })
            ->orderBy('id','desc')
            ->get();

        $progressBar = $this->output->createProgressBar($webhookCalls->count());

        $progressBar->start();

        foreach ($webhookCalls as $webhookCall) {
            $action = new ReplayAttemptWebhookCallAction($webhookCall);
            $action->execute();

            $progressBar->advance();

            $progressBar->finish();
        }
        $this->info('\n Webhook calls replayed successfully');
    }
}
