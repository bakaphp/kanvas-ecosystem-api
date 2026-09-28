<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\Connectors\Mailgun\Actions\ProvisionAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Lets an agent set itself up with its own email address on the company's Mailgun domain, so people
 * can write to it directly and it answers with everything else it can do.
 *
 * The address is derived from the agent's own slug — an agent that could name its own mailbox could
 * claim `billing@` and quietly intercept the company's mail — and only a company administrator in
 * the conversation can trigger it.
 */
#[AgentTool(name: 'Provision My Email Inbox', category: 'system')]
class ProvisionMyEmailInboxTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    public function __construct(
        private readonly ?Agent $agent = null,
    ) {
        parent::__construct(
            name: 'provision_my_email_inbox',
            description: 'Give yourself your own email address on this company\'s domain, so people can email you '
                . 'directly and you answer in this same conversation. You get exactly one address, derived from your '
                . 'name — you cannot choose it or have a second one. If you already have one this simply tells you '
                . 'what it is. Pass access="open" only if you are meant to be handed out publicly as a contact '
                . 'address; the default "restricted" answers only teammates and people already in the CRM.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'access',
                type: PropertyType::STRING,
                description: 'Who may email you: "restricted" (teammates and known CRM contacts, the default) or '
                    . '"open" (anyone, capturing unknown senders as new contacts).',
                required: false,
                enum: [
                    MailboxAccessEnum::RESTRICTED->value,
                    MailboxAccessEnum::OPEN->value,
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $access = null): array
    {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'This tool can only run inside an agent.'];
        }

        $mailboxService = new AgentMailboxService();
        $existing = $mailboxService->addressFor($this->agent);

        // An agent has exactly one address, and it outlives renames — so once there is one, this
        // tool is a lookup, not a write. No admin check and no Mailgun call: reading back your own
        // address is not privileged, and re-running the provision would be pure noise on the API.
        if ($existing !== null) {
            return [
                'status' => 'success',
                'address' => $existing,
                'access' => $mailboxService->accessFor($this->agent)->value,
                'already_provisioned' => true,
                'message' => 'You already have an email address: ' . $existing
                    . '. It is the only one you get — use it, do not provision another.',
            ];
        }

        $denied = $this->requireRequestingAdminOrError();
        if ($denied !== null) {
            return ['status' => 'error', 'message' => $denied['message']];
        }

        $accessMode = MailboxAccessEnum::tryFrom(strtolower(trim((string) $access)))
            ?? MailboxAccessEnum::RESTRICTED;

        try {
            $mailbox = new ProvisionAgentMailboxAction($this->agent, $accessMode)->execute();
        } catch (ValidationException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'I could not set up the mailbox right now. The Mailgun integration may not be configured.',
            ];
        }

        return [
            'status' => 'success',
            'address' => $mailbox['address'],
            'access' => $mailbox['access'],
            'already_provisioned' => false,
            'message' => 'Email to ' . $mailbox['address'] . ' now reaches me. This is my only address.',
        ];
    }
}
