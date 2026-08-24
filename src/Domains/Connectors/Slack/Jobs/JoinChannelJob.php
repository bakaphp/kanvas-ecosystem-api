<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * The initial sweep is only a snapshot — without this, every channel opened afterwards stays
 * invisible until someone re-runs the resync.
 */
class JoinChannelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly ReceiverWebhook $receiver,
        public readonly string $slackChannelId,
    ) {
        $this->onQueue('slack-ingest');
    }

    public function handle(): void
    {
        if ($this->slackChannelId === '') {
            return;
        }

        $this->overwriteAppService($this->app);

        Client::getInstanceByReceiver($this->receiver)->joinConversation($this->slackChannelId);
    }
}
