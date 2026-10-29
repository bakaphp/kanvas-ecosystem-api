<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\WakeAgentInSessionAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Exceptions\RunInFlightException;

/**
 * A private instruction turn on an existing session, off the request. Retries exist only for a
 * busy thread, which releases without throwing; a real failure ends the job on the first exception
 * so a turn that already ran tools is never run twice.
 */
final class WakeAgentInSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 1;
    public int $backoff = 60;

    public function __construct(
        public readonly Apps $app,
        public readonly Agent $agent,
        public readonly Session $session,
        public readonly string $instruction,
        public readonly Users $user,
        public readonly string $verb = 'scheduled-agent-reply',
    ) {
        $this->onQueue('agent-chat');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        try {
            new WakeAgentInSessionAction(
                agent: $this->agent,
                session: $this->session,
                instruction: $this->instruction,
                user: $this->user,
                verb: $this->verb,
            )->execute();
        } catch (RunInFlightException) {
            $this->release($this->backoff);
        }
    }
}
