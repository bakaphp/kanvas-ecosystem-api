<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Mailgun;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Mailgun\Actions\DisconnectAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Actions\ProvisionAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Models\Agent;

class MailgunAgentMailboxResolver
{
    use ResolvesActingContext;

    /**
     * @return array<string, mixed>
     */
    public function provision(mixed $root, array $request): array
    {
        return new ProvisionAgentMailboxAction(
            $this->agent($request),
            MailboxAccessEnum::tryFrom((string) ($request['access'] ?? '')) ?? MailboxAccessEnum::RESTRICTED,
        )->execute();
    }

    public function disconnect(mixed $root, array $request): bool
    {
        return new DisconnectAgentMailboxAction($this->agent($request))->execute();
    }

    /**
     * @return array<string, mixed>|null null when the agent has no mailbox
     */
    public function mailbox(mixed $root, array $request): ?array
    {
        return new AgentMailboxService()->statusFor($this->agent($request));
    }

    private function agent(array $request): Agent
    {
        $ctx = $this->actingContext();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $ctx->company, $ctx->app);

        return $agent;
    }
}
