<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Read side of an agent's mailbox — everything that needs to know "does this agent have an email
 * address, and on what domain" without pulling in the provisioning path.
 */
class AgentMailboxService
{
    public function addressFor(Agent $agent): ?string
    {
        $address = strtolower(trim((string) $agent->get(CustomFieldEnum::MAILBOX_ADDRESS->value)));

        return $address === '' ? null : $address;
    }

    public function hasMailbox(Agent $agent): bool
    {
        return $this->addressFor($agent) !== null;
    }

    /**
     * Whether this agent should get an address the moment it is created, without anyone asking.
     *
     * Only an internal teammate does. A customer-facing persona already speaks through the company's
     * shared lead inbox, so a second public address per agent buys nothing and multiplies Mailgun
     * routes; a sub-agent is a tool another agent calls, not a correspondent. Companies that never
     * finished the Mailgun setup are skipped rather than failed — they can still provision by hand
     * once they have a domain.
     */
    public function shouldAutoProvision(Agent $agent): bool
    {
        return $agent->conversesWithUser()
            && ! $agent->is_sub_agent
            && ! $this->hasMailbox($agent)
            && $this->isConfiguredFor($agent);
    }

    public function isConfiguredFor(Agent $agent): bool
    {
        $signingKey = (string) ($agent->company->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value)
            ?: $agent->app->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value));

        if ((string) $agent->app->get(ConfigurationEnum::API_KEY->value) === '' || $signingKey === '') {
            return false;
        }

        try {
            $this->domainFor($agent);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    public function accessFor(Agent $agent): MailboxAccessEnum
    {
        return MailboxAccessEnum::tryFrom((string) $agent->get(CustomFieldEnum::MAILBOX_ACCESS->value))
            ?? MailboxAccessEnum::RESTRICTED;
    }

    /**
     * Company config wins over app config: one Mailgun account can serve many tenants, each on its
     * own sending domain, and the address an agent hands out has to be its own company's.
     */
    public function domainFor(Agent $agent): string
    {
        $domain = strtolower(trim((string) (
            $agent->company->get(ConfigurationEnum::DOMAIN->value)
            ?? $agent->app->get(ConfigurationEnum::DOMAIN->value)
        )));

        if ($domain === '') {
            throw new ValidationException(
                'No Mailgun domain is configured for this company. Set up the Mailgun integration with a '
                . 'verified domain before giving agents their own email.'
            );
        }

        return $domain;
    }

    /**
     * The agent's name is the local part — an LLM that could name its own mailbox could claim
     * `billing@` or `support@` and quietly intercept the company's real mail. Not `slug`: the agent
     * observer appends the row id to every slug, which would hand out `sofia-1284@…`.
     */
    public function localPartFor(Agent $agent): string
    {
        $localPart = trim((string) Str::slug((string) $agent->name));

        return $localPart !== '' ? $localPart : 'agent-' . (int) $agent->getId();
    }

    /**
     * The address this agent should own. Stable once provisioned: renaming an agent must not move
     * its mailbox out from under everyone who already writes to it.
     */
    public function proposedAddressFor(Agent $agent): string
    {
        $domain = $this->domainFor($agent);
        $current = $this->addressFor($agent);

        if ($current !== null && str_ends_with($current, '@' . $domain)) {
            return $current;
        }

        $localPart = $this->localPartFor($agent);
        $candidate = $localPart . '@' . $domain;

        if ($this->agentAtAddress($candidate, $agent) === null) {
            return $candidate;
        }

        // A second agent with the same name: suffix with the row id rather than fail. A Mailgun
        // route is global to the account, so two agents on one address means one of them silently
        // never hears from anyone.
        $candidate = $localPart . '-' . (int) $agent->getId() . '@' . $domain;

        if ($this->agentAtAddress($candidate, $agent) !== null) {
            throw new ValidationException('Another agent already answers at ' . $candidate . '.');
        }

        return $candidate;
    }

    /**
     * @return array{address: string, access: string, route_id: string, receiver_url: string, contact_email_set: bool}|null
     *         null when the agent has no mailbox
     */
    public function statusFor(Agent $agent): ?array
    {
        $address = $this->addressFor($agent);

        if ($address === null) {
            return null;
        }

        $receiverId = $agent->get(CustomFieldEnum::RECEIVER_ID->value);
        $receiver = $receiverId !== null ? ReceiverWebhook::getById((int) $receiverId, $agent->app) : null;

        return [
            'address' => $address,
            'access' => $this->accessFor($agent)->value,
            'route_id' => (string) ($agent->get(CustomFieldEnum::ROUTE_ID->value) ?? ''),
            'receiver_url' => (string) $receiver?->getUrl(),
            'contact_email_set' => strtolower(trim((string) $agent->user->get('contact_email'))) === $address,
        ];
    }

    /**
     * The agent, if any, that already answers at this address. A Mailgun route is global to the
     * account, so two agents on the same address means one of them silently never hears from anyone.
     */
    public function agentAtAddress(string $address, Agent $exclude): ?Agent
    {
        return $this->lookupAgentAtAddress(
            $exclude->app,
            $exclude->company,
            $address,
            $exclude->getId()
        );
    }

    /**
     * The same question asked without an agent in hand — anything wiring a shared address needs to know
     * whether it is about to take an agent's personal inbox out from under it.
     */
    public function agentAtAddressIn(AppInterface $app, CompanyInterface $company, string $address): ?Agent
    {
        return $this->lookupAgentAtAddress($app, $company, $address, null);
    }

    private function lookupAgentAtAddress(
        AppInterface $app,
        CompanyInterface $company,
        string $address,
        ?int $excludeAgentId,
    ): ?Agent {
        $query = Agent::getByCustomFieldBuilderTransactionSafe(
            CustomFieldEnum::MAILBOX_ADDRESS->value,
            strtolower(trim($address)),
            $company,
        )
            ->fromApp($app)
            ->notDeleted();

        if ($excludeAgentId !== null) {
            $query->where('id', '!=', $excludeAgentId);
        }

        /** @var Agent|null $agent */
        $agent = $query->first();

        return $agent;
    }
}
