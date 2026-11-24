<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Communication\Connections\Actions\EnsureProviderIntegrationAction;
use Kanvas\Communication\Connections\Actions\RefreshAgentConnectionsAction;
use Kanvas\Communication\Support\ProviderRollout;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Gives one agent its own `{slug}@{company mailgun domain}` address: the address and who may write
 * to it are recorded on the agent, the address on the agent's user as `contact_email`, and the
 * company's connections are reconciled so the mailbox gets its connection and its route.
 *
 * Idempotent — the mailbox is an account of the company's Mailgun integration, and reconciling it
 * again finds the same connection and the same route, which is what makes it safe to expose to an
 * agent as a tool it can call whenever it likes.
 */
class ProvisionAgentMailboxAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly MailboxAccessEnum $access = MailboxAccessEnum::RESTRICTED,
    ) {
    }

    /**
     * @return array{address: string, access: string, route_id: string, receiver_url: string, connected: bool, contact_email_set: bool}
     */
    public function execute(): array
    {
        // A customer line is the company's own address, which an agent answers; on an address
        // named after the agent it would open leads and send in the company's name from a mailbox.
        if (! $this->access->isPersonal()) {
            throw new ValidationException(
                'An agent mailbox is restricted or open. A customer line is the company\'s address, '
                . 'which is not provisioned on an agent.'
            );
        }

        $mailboxService = new AgentMailboxService();

        $address = $mailboxService->proposedAddressFor($this->agent);
        $domain = $mailboxService->domainFor($this->agent);

        $this->assertWebhookSigningKeyIsConfigured();

        // Cheap on a correct setup, and the only thing standing between a typo'd domain and a
        // mailbox that accepts nothing forever.
        new Client($this->agent->app)->getDomain($domain);

        // A company on an app-wide Mailgun never integrated it; the mailbox is what gives it the
        // integration its connection hangs off.
        $integration = new EnsureProviderIntegrationAction(
            $this->agent->app,
            $this->agent->company,
            $this->agent->user,
            MailgunHandler::class
        )->execute();

        if ($integration === null || new ProviderRollout()->providerFor($integration, $this->agent->app) === null) {
            throw new ValidationException(
                'This company cannot be given a Mailgun integration, so the mailbox would never receive or '
                . 'send. Finish the Mailgun integration setup first.'
            );
        }

        $this->agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $address);
        $this->agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, $this->access->value);
        $contactEmailSet = $this->setUserContactEmail($address, $domain);

        // The mailbox is an account of the company's Mailgun integration: reconciling it is what
        // makes the connection and points the address's route at the communication receiver.
        new RefreshAgentConnectionsAction($this->agent)->execute();

        return [
            ...$mailboxService->statusFor($this->agent->refresh()),
            'contact_email_set' => $contactEmailSet,
        ];
    }

    /**
     * Every forwarded email is authenticated against this key, and a receiver without it answers
     * 401 to Mailgun forever. Failing here is the difference between a clear setup error and a
     * mailbox that looks provisioned and silently never delivers a single message.
     */
    private function assertWebhookSigningKeyIsConfigured(): void
    {
        if (ConfigurationEnum::signingKeyFor($this->agent->app, $this->agent->company) !== '') {
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
