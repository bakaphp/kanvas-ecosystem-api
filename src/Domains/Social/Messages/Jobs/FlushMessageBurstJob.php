<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Services\MessageBurstService;
use Kanvas\Social\Messages\Support\BurstHandler;

/**
 * Closes one burst and hands it to the channel's handler.
 *
 * Debounced rather than scheduled: every message in the burst re-arms the token and dispatches a
 * fresh delayed copy, so only the last one survives to do the work. That is the whole reason a
 * flurry of inbound messages produces one reply instead of one reply each.
 */
class FlushMessageBurstJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * @param class-string<BurstHandler> $handlerClass
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Channel $channel,
        public readonly int $burstHeadId,
        public readonly string $token,
        public readonly string $handlerClass,
        public readonly array $params = [],
    ) {
    }

    /**
     * The token every message in a burst overwrites. Whoever still matches when the delay expires
     * is the one that closes it.
     */
    public static function cacheKey(int $burstHeadId): string
    {
        return 'message-burst:' . $burstHeadId;
    }

    public function handle(): void
    {
        // The worker is long-lived and Bouncer auto-scopes Role/Ability queries to whatever the
        // previous job left bound.
        $this->overwriteAppService($this->app);

        if (Cache::get(self::cacheKey($this->burstHeadId)) !== $this->token) {
            return;
        }

        // Spent here, not on the way out. With `$tries = 2` a throw after the agent has answered
        // re-enters with the token still valid and files a second reply. Deleting only on a match
        // still leaves the burst armed for the winner when a part loses.
        Cache::forget(self::cacheKey($this->burstHeadId));

        $messages = MessageBurstService::messagesFor($this->burstHeadId);

        if ($messages->isEmpty()) {
            return;
        }

        new $this->handlerClass(
            $this->app,
            $this->channel,
            $messages
        )->execute($this->params);
    }
}
