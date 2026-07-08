<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Messages\Actions\ParseMessageMentionsAction;
use Kanvas\Social\Messages\Models\Message;

final class ProcessMessageMentionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Message $message,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->message->app);

        new ParseMessageMentionsAction($this->message)->execute();
    }
}
