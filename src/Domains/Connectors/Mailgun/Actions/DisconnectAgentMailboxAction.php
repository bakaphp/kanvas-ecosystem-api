<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Communication\Connections\Actions\RefreshAgentConnectionsAction;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Takes the agent off email: the address is cleared so outbound stops claiming a From it no longer
 * owns, and reconciling retires the address's connection, whose cleanup removes the Mailgun route.
 */
class DisconnectAgentMailboxAction
{
    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    public function execute(): bool
    {
        $address = (string) ($this->agent->get(CustomFieldEnum::MAILBOX_ADDRESS->value) ?? '');
        $user = $this->agent->user;

        if ($address !== '' && strtolower(trim((string) $user->get('contact_email'))) === strtolower($address)) {
            $user->del('contact_email');
        }

        $this->agent->del(CustomFieldEnum::MAILBOX_ADDRESS->value);
        $this->agent->del(CustomFieldEnum::MAILBOX_ACCESS->value);

        new RefreshAgentConnectionsAction($this->agent)->execute();

        return true;
    }
}
