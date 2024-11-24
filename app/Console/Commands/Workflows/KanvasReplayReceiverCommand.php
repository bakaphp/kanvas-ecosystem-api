<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class KanvasReplayReceiverCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:replay-receiver-workflow {receiver_id} {start_date?}';

    /**
     * Handle the command execution.
     */
    public function handle(): void
    {
        $receiverId = $this->argument('receiver_id');
        $receiver = ReceiverWebhook::getById($receiverId);
        $this->overwriteAppService($receiver->app);

        $failedReceiverCalls = ReceiverWebhookCall::where('receiver_webhook_id', $receiver->getId())
            ->where('status', StatusEnum::FAILED->value)
            ->when($this->argument('start_date'), function ($query, $startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->get();

        $totalCalls = $failedReceiverCalls->count();

        if ($totalCalls === 0) {
            $this->info('No failed receiver calls found.');

            return;
        }

        $this->info("Replaying {$totalCalls} failed receiver calls...");

        // Initialize the progress bar
        $progressBar = $this->output->createProgressBar($totalCalls);
        $progressBar->start();

        foreach ($failedReceiverCalls as $failedReceiverCall) {
            // Perform HTTP request to the receiver URL
            $receiverUrl = str_replace('http://', 'https://', $receiver->url);
            Http::post($receiverUrl, $failedReceiverCall->payload);

            // Advance the progress bar
            $progressBar->advance();
        }

        // Finish the progress bar
        $progressBar->finish();
        $this->info("\nReplay process completed.");
    }
}
