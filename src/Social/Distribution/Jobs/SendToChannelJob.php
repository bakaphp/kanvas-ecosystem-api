<?php

declare(strict_types=1);

namespace Kanvas\Social\Distribution\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Distribution\Distribution;
use Kanvas\Social\Messages\Models\Message;
use Illuminate\Database\Eloquent\Collection;

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
        foreach($this->channels as $channel) {
            Distribution::sentToChannelFeed($channel, $this->message);
        }
    }
}
