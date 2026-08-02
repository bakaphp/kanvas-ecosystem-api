<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Sessions\Actions\GenerateChannelTitleAction;
use Kanvas\Social\Channels\Models\Channel;

/**
 * Auto-titles an agent chat channel from its opening exchange, ChatGPT-style. Runs async so title
 * generation (an LLM round-trip) never delays the chat reply the user is waiting on.
 */
final class GenerateChannelTitleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Channel $channel,
        public readonly string $userMessage,
        public readonly string $assistantResponse,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new GenerateChannelTitleAction(
            $this->channel,
            $this->userMessage,
            $this->assistantResponse,
        )->execute();
    }
}
