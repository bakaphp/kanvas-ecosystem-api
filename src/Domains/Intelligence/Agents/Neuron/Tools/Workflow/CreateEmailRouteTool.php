<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Point an email address at a receiver, so mail sent to it becomes work in Kanvas.
 *
 * This is the half of inbound-email setup that used to need the Mailgun dashboard: `create_company_receiver`
 * produces a URL, and until something forwards an address to that URL the receiver never fires. Closing
 * that by hand is where an otherwise complete automation sat unfinished.
 *
 * Distinct from `provision_my_email_inbox`, which gives ONE agent its own personal address. This one
 * wires a shared, purpose-built address — accounting@, support@, leads@ — into a receiver that a
 * workflow already reacts to.
 *
 * **Neither the address nor the destination is a free LLM string, and that is the point.** The model
 * chooses only the local part; the domain comes from the company's own Mailgun configuration and the
 * destination is resolved from a receiver in this company. A model that could name the forward URL
 * could be talked into forwarding a tenant's inbound mail to an attacker, and one that could name the
 * full address could claim routing for a domain the tenant does not own.
 */
#[AgentTool(name: 'Create Email Route', category: 'workflow')]
class CreateEmailRouteTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_email_route',
            description: 'Make an email address deliver into Kanvas: mail sent to it is POSTed to a receiver '
                . 'you have already created, which is what lets a workflow act on it. Admin only. Use it '
                . 'after create_company_receiver, when the work starts from someone sending an email — an '
                . 'accounting inbox, a support address, a lead address. You choose only the part before the '
                . '@; the domain is the company\'s own and you cannot set it. You cannot point the address '
                . 'anywhere except a receiver belonging to this company. Re-running with the same address '
                . 'repoints it rather than creating a second route. Do NOT use this to give yourself an '
                . 'address — that is provision_my_email_inbox.',
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
                name: 'local_part',
                type: PropertyType::STRING,
                description: 'The part before the @, e.g. "accounting" for accounting@yourcompany.com. '
                    . 'Letters, numbers, dots, dashes and underscores only.',
                required: true,
            ),
            new ToolProperty(
                name: 'receiver_id',
                type: PropertyType::INTEGER,
                description: 'The receiver mail should be delivered to — the receiver_id returned by '
                    . 'create_company_receiver. It must belong to this company.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'What this address is for, so a human reading the Mailgun dashboard later knows '
                    . 'why it exists.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $local_part, int $receiver_id, ?string $description = null): array
    {
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $denied;
        }

        if (! $this->hasTenantContext()) {
            return $this->error('This agent has no company context, so it cannot create an email route.');
        }

        $localPart = mb_strtolower(trim($local_part));

        if (! preg_match('/^[a-z0-9._-]+$/', $localPart)) {
            return $this->error(
                'The part before the @ can only contain letters, numbers, dots, dashes and underscores. '
                . 'Give just that part — not a full email address.'
            );
        }

        $domain = $this->companyDomain();

        if ($domain === '') {
            return $this->error(
                'This company has no verified Mailgun domain configured, so no address can be routed yet. '
                . 'Tell the admin to finish the Mailgun setup first, and do not retry.'
            );
        }

        $address = $localPart . '@' . $domain;

        if (($owner = new AgentMailboxService()->agentAtAddressIn($this->app, $this->company, $address)) !== null) {
            // Routes are keyed by recipient, so the idempotent path would silently repoint this agent's
            // own mail at a different receiver and its inbox would go quiet with nothing reporting why.
            return $this->error(sprintf(
                '%s is already the personal inbox of the agent "%s". Routing it somewhere else would stop '
                . 'that agent receiving its mail. Pick a different address.',
                $address,
                $owner->name
            ));
        }

        $receiver = $this->receiverFor($receiver_id);

        if ($receiver === null) {
            return $this->error(
                'Receiver ' . $receiver_id . ' does not belong to this company. Create one with '
                . 'create_company_receiver and use the receiver_id it returns.'
            );
        }

        try {
            $route = $this->route(
                $address,
                $receiver->getUrl(),
                $this->describe($description, $address)
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'Mailgun refused to set up the route: ' . $e->getMessage() . ' Tell the admin and do not retry.'
            );
        }

        return [
            'created' => true,
            'address' => $address,
            'receiver_id' => $receiver->getId(),
            'route_id' => (string) ($route['id'] ?? ''),
            'scope' => 'company:' . $this->company->name,
            'message' => sprintf(
                'Mail sent to %s now reaches Kanvas. Tell the admin the address is live and who should '
                . 'start using it — nothing happens until somebody emails it.',
                $address
            ),
        ];
    }

    /**
     * Company config wins over app config: one Mailgun account serves many tenants, each on its own
     * sending domain, and an address this company hands out has to be on the company's own.
     */
    private function companyDomain(): string
    {
        return mb_strtolower(trim((string) (
            $this->company->get(ConfigurationEnum::DOMAIN->value)
            ?? $this->app->get(ConfigurationEnum::DOMAIN->value)
        )));
    }

    private function receiverFor(int $receiverId): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->where('id', $receiverId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * Adopt an existing route for this recipient rather than adding a second — two routes matching one
     * address race, and which of them wins is not something the tenant controls.
     *
     * @return array<string, mixed>
     */
    private function route(string $address, string $forwardUrl, string $description): array
    {
        $client = new Client($this->app);
        $existing = $client->findRouteByRecipient($address);

        return $existing !== null
            ? $client->updateRoute((string) $existing['id'], $address, $forwardUrl, $description)
            : $client->createRoute($address, $forwardUrl, $description);
    }

    private function describe(?string $description, string $address): string
    {
        $given = trim((string) $description);

        return $given !== '' ? $given : 'Kanvas inbound route for ' . $address;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $message): array
    {
        return ['created' => false, 'message' => $message];
    }

    /**
     * @return list<string>
     */
    protected function requiredAbilities(): array
    {
        return [AgentAbilityEnum::MANAGE_COMPANY_WORKFLOWS->value];
    }
}
