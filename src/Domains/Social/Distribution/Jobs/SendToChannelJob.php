<?php

declare(strict_types=1);

namespace Kanvas\Social\Distribution\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Channels\Services\DistributionMessageService;
use Kanvas\Social\Messages\Models\Message;

class SendToChannelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected array|Collection $channels,
        protected Message $message
    ) {
        $this->onQueue('kanvas-social');
    }

    public function handle()
    {
        foreach ($this->channels as $channel) {
            DistributionMessageService::sentToChannelFeed($channel, $this->message);
        }
    }
}
