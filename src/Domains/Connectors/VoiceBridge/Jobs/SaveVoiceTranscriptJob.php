<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\VoiceBridge\Actions\SaveVoiceTranscriptAction;
use Kanvas\Connectors\VoiceBridge\Client;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

class SaveVoiceTranscriptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const MAX_ATTEMPTS = 22;
    public const POLL_INTERVAL_SECONDS = 30;

    public function __construct(
        protected Lead $lead,
        protected string $sessionId,
        protected int $attempt = 0,
    ) {
    }

    public function handle(): void
    {
        $app = $this->lead->app;
        $this->overwriteAppService($app);

        try {
            $stream = Client::getInstance($app)->getTranscriptStream($this->sessionId);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($stream['is_active'] ?? true) {
            if ($this->attempt < self::MAX_ATTEMPTS) {
                self::dispatch($this->lead, $this->sessionId, $this->attempt + 1)
                    ->delay(now()->addSeconds(self::POLL_INTERVAL_SECONDS));
            }

            return;
        }

        $transcript = $stream['transcript'] ?? [];

        if (empty($transcript)) {
            return;
        }

        new SaveVoiceTranscriptAction(
            $this->lead,
            $transcript,
            $this->sessionId,
        )->execute();
    }
}
