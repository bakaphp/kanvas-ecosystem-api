<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\Sessions\Actions\GenerateChannelTitleAction;
use Kanvas\Social\Channels\Models\Channel;

final class GenerateChannelTitleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Channel $channel,
        public readonly string $userMessage,
        public readonly string $assistantResponse,
        public readonly bool $refine = false,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->channel->app);

        new GenerateChannelTitleAction(
            $this->channel,
            $this->userMessage,
            $this->assistantResponse,
            $this->refine,
        )->execute();
    }
}
