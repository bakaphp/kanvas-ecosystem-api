<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Services\MailgunReceiverService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Gives one agent its own `{slug}@{company mailgun domain}` address: a receiver it owns, a Mailgun
 * route forwarding that address into it, and the address recorded on the agent and on the agent's
 * user as `contact_email`.
 *
 * Idempotent — re-running repoints the existing route at the receiver instead of stacking a second
 * one, which is what makes it safe to expose to an agent as a tool it can call whenever it likes.
 */
class ProvisionAgentMailboxAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly MailboxAccessEnum $access = MailboxAccessEnum::RESTRICTED,
    ) {
    }

    /**
     * @return array{address: string, access: string, route_id: string, receiver_url: string, contact_email_set: bool}
     */
    public function execute(): array
    {
        $app = $this->agent->app;
        $mailboxService = new AgentMailboxService();

        $address = $mailboxService->proposedAddressFor($this->agent);
        $domain = $mailboxService->domainFor($this->agent);

        $this->assertWebhookSigningKeyIsConfigured();

        $client = new Client($app);
        // Cheap on a correct setup, and the only thing standing between a typo'd domain and a
        // mailbox that accepts nothing forever.
        $client->getDomain($domain);

        $receiver = new MailgunReceiverService()->forAgent($this->agent);
        $forwardUrl = $receiver->getUrl();
        $description = 'Kanvas agent mailbox — ' . $this->agent->name . ' (agent ' . (int) $this->agent->getId() . ')';

        $routeId = (string) ($this->agent->get(CustomFieldEnum::ROUTE_ID->value) ?? '');
        $existingRoute = $routeId !== '' ? null : $client->findRouteByRecipient($address);

        if ($routeId !== '') {
            $route = $client->updateRoute($routeId, $address, $forwardUrl, $description);
        } elseif ($existingRoute !== null) {
            // A route left behind by an earlier provision (or created by hand in the dashboard) —
            // adopt it, or Mailgun ends up with two routes racing for the same recipient.
            $route = $client->updateRoute((string) $existingRoute['id'], $address, $forwardUrl, $description);
        } else {
            $route = $client->createRoute($address, $forwardUrl, $description);
        }

        $routeId = (string) ($route['id'] ?? $routeId);

        $receiver->configuration = [
            ...$receiver->configuration,
            ReceiverConfigurationEnum::AGENT_ID->value => $this->agent->getId(),
            ReceiverConfigurationEnum::MAILBOX_ADDRESS->value => $address,
            ReceiverConfigurationEnum::CAPTURE_FILES->value => true,
        ];
        $receiver->is_active = true;
        $receiver->saveOrFail();

        $this->agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $address);
        $this->agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, $this->access->value);
        $this->agent->set(CustomFieldEnum::ROUTE_ID->value, $routeId);

        return [
            'address' => $address,
            'access' => $this->access->value,
            'route_id' => $routeId,
            'receiver_url' => $forwardUrl,
            'contact_email_set' => $this->setUserContactEmail($address, $domain),
        ];
    }

    /**
     * Every forwarded email is authenticated against this key, and a receiver without it answers
     * 401 to Mailgun forever. Failing here is the difference between a clear setup error and a
     * mailbox that looks provisioned and silently never delivers a single message.
     */
    private function assertWebhookSigningKeyIsConfigured(): void
    {
        $key = ConfigurationEnum::WEBHOOK_SIGNING_KEY->value;

        if ((string) $this->agent->app->get($key) !== '' || (string) $this->agent->company->get($key) !== '') {
            return;
        }

        throw new ValidationException(
            'The Mailgun webhook signing key is not configured for this company, so inbound email would be '
            . 'rejected. Finish the Mailgun integration setup first.'
        );
    }

    /**
     * `contact_email` is the user's alternative email: NotificationMailTrait delivers to it as well
     * as the primary, so recording the mailbox here is what makes every Kanvas notification aimed at
     * the agent land somewhere the agent can actually read and answer.
     *
     * An agent's user can be shared with a human (agents aren't guaranteed a dedicated user yet), and
     * that human's contact_email is their real fallback address for password recovery — so an
     * unrelated value is left alone and reported instead of silently replaced.
     */
    private function setUserContactEmail(string $address, string $domain): bool
    {
        $user = $this->agent->user;
        $current = strtolower(trim((string) $user->get('contact_email')));

        if ($current !== '' && ! str_ends_with($current, '@' . $domain)) {
            return false;
        }

        $user->set('contact_email', $address);

        return true;
    }
}
