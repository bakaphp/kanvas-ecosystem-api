<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Services\MailgunReceiverService;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Takes the agent off email: the Mailgun route goes, the receiver is deactivated but kept (its URL
 * is what any route the customer built by hand still points at), and the address is cleared so
 * outbound stops claiming a From it no longer owns.
 */
class DisconnectAgentMailboxAction
{
    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    public function execute(): bool
    {
        $routeId = (string) ($this->agent->get(CustomFieldEnum::ROUTE_ID->value) ?? '');

        if ($routeId !== '') {
            new Client($this->agent->app)->deleteRoute($routeId);
        }

        if ($this->agent->get(CustomFieldEnum::RECEIVER_ID->value) !== null) {
            $receiver = new MailgunReceiverService()->forAgent($this->agent);
            $receiver->is_active = false;
            $receiver->saveOrFail();
        }

        $address = (string) ($this->agent->get(CustomFieldEnum::MAILBOX_ADDRESS->value) ?? '');
        $user = $this->agent->user;

        if ($address !== '' && strtolower(trim((string) $user->get('contact_email'))) === strtolower($address)) {
            $user->del('contact_email');
        }

        $this->agent->del(CustomFieldEnum::ROUTE_ID->value);
        $this->agent->del(CustomFieldEnum::MAILBOX_ADDRESS->value);
        $this->agent->del(CustomFieldEnum::MAILBOX_ACCESS->value);

        return true;
    }
}
