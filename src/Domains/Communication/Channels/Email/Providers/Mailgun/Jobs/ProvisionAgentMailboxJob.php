<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\ProvisionAgentMailboxAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Gives a newly created internal agent its address off the request path.
 *
 * Queued on purpose: two Mailgun round-trips inside agent creation would make a Mailgun outage look
 * like Kanvas failing to create agents. A missed mailbox is recoverable — the agent's own tool, the
 * mutation, or the command all provision it later.
 */
class ProvisionAgentMailboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Agent $agent,
        public readonly MailboxAccessEnum $access = MailboxAccessEnum::RESTRICTED,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->agent->app);

        // Re-checked here, not just at dispatch: the queue runs later, and by then another path may
        // have given this agent its one address.
        if (! new AgentMailboxService()->shouldAutoProvision($this->agent)) {
            return;
        }

        try {
            new ProvisionAgentMailboxAction($this->agent, $this->access)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
