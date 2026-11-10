<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Services\FactoryServeClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\ReportFactoryProblemsAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * Looks at the factory Mac once a minute for as long as a coding chat is open, turns and the gaps between
 * them alike: a turn's poller stops when the turn does, and what dies after that is what nobody sees.
 *
 * Unique per session until it starts, so every place that starts a watch can call it without stacking a
 * second chain — and so it can re-queue itself from `handle()`.
 */
class WatchCodingChatJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int INTERVAL_SECONDS = 60;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly Apps $app,
        public readonly int $sessionId,
    ) {
        $this->onQueue('coding');
    }

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('id', $this->sessionId)->fromApp($this->app)->first();

        if ($session === null || ! $session->isChat() || ! $session->isLive()) {
            return;
        }

        $machine = $session->machine;
        $agent = $session->agent;

        if ($machine === null || $agent === null) {
            return;
        }

        try {
            $health = new FactoryServeClient($machine, CodingChatSettingEnum::factoryDirFor($agent))
                ->health(ReportFactoryProblemsAction::since($session));

            new ReportFactoryProblemsAction($session, $health)->execute();
        } catch (HarnessTransportException) {
            // An unreachable Mac is not news between turns, and during one the poller reports it.
        }

        self::dispatch($this->app, $this->sessionId)->delay(Carbon::now()->addSeconds(self::INTERVAL_SECONDS));
    }
}
