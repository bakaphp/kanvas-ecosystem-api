<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Mailgun;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Connectors\Mailgun\Actions\DisconnectAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Actions\ProvisionAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class ProvisionAgentMailboxCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:mailgun:provision-agent-mailbox
        {--agent= : Agent ID (required)}
        {--access=restricted : restricted (teammates + known contacts) or open (anyone)}
        {--disconnect : Remove the mailbox instead of creating it}';

    protected $description = 'Give an agent its own email address on the company Mailgun domain, or take it away.';

    public function handle(): int
    {
        $agentId = (int) $this->option('agent');

        if ($agentId === 0) {
            $this->error('--agent is required');

            return self::FAILURE;
        }

        /** @var Agent $agent */
        $agent = Agent::query()->notDeleted()->findOrFail($agentId);

        // Bouncer scope and the container-bound app both leak from whatever ran before; the agent's
        // own app has to be bound before anything below touches a scoped model.
        $this->overwriteAppService($agent->app);

        try {
            if ($this->option('disconnect')) {
                new DisconnectAgentMailboxAction($agent)->execute();
                $this->info('Mailbox removed for agent ' . $agent->name);

                return self::SUCCESS;
            }

            $access = MailboxAccessEnum::tryFrom((string) $this->option('access'));

            if ($access === null) {
                $this->error('--access must be "restricted" or "open"');

                return self::FAILURE;
            }

            $mailbox = new ProvisionAgentMailboxAction($agent, $access)->execute();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Agent ' . $agent->name . ' now answers at ' . $mailbox['address']);
        $this->line('Route:    ' . $mailbox['route_id']);
        $this->line('Forwards: ' . $mailbox['receiver_url']);

        if (! $mailbox['contact_email_set']) {
            $this->warn(
                'The agent user already had a different contact_email; left as is. '
                . 'Kanvas notifications will not reach the mailbox.'
            );
        }

        return self::SUCCESS;
    }
}
