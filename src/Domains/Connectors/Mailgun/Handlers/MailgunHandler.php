<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Handlers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BaseIntegration;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\CarbonInterval;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Communication\Connections\Concerns\ProvisionsConnections;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\EmailTransport;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\MailgunInbound;
use Kanvas\Connectors\Mailgun\MailgunProvisioner;
use Kanvas\Connectors\Mailgun\Services\AccountingInboxService;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

/**
 * Email over Mailgun. The signing key, the domain and the address the company communicates from
 * are kept on the company; the integration_companies row only records that the integration was
 * completed. The company's address and each agent mailbox have their own connections.
 */
class MailgunHandler extends BaseIntegration implements CommunicationProvider
{
    use ProvisionsConnections;

    #[Override]
    public function setup(): bool
    {
        $webhookSigningKey = $this->data['webhook_signing_key'] ?? null;
        $apiKey = (string) ($this->data['api_key'] ?? '');
        $domain = strtolower(trim((string) ($this->data['domain'] ?? '')));
        $fromAddress = strtolower(trim((string) ($this->data['from_address'] ?? '')));

        if (empty($webhookSigningKey)) {
            throw new ValidationException('Webhook signing key is required for Mailgun.');
        }

        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, $webhookSigningKey);

        if ($apiKey !== '') {
            $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);
        }

        if ($domain !== '') {
            // Rejecting a domain that isn't on the account here is the difference between a clear
            // setup error and agent mailboxes that silently never receive anything.
            new Client($this->app)->getDomain($domain);

            $this->company->set(ConfigurationEnum::DOMAIN->value, $domain);
        }

        if ($fromAddress !== '') {
            $sendingDomain = $domain !== '' ? $domain : self::sendingDomain($this->app, $this->company);

            if ($sendingDomain === null || Str::after($fromAddress, '@') !== $sendingDomain) {
                throw new ValidationException('The from address must be on the Mailgun domain ' . ($sendingDomain ?? '') . '.');
            }

            $this->company->set(ConfigurationEnum::FROM_ADDRESS->value, $fromAddress);
        }

        return $this->company->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value) === $webhookSigningKey;
    }

    #[Override]
    public static function slug(): string
    {
        return IntegrationsEnum::MAILGUN->value;
    }

    #[Override]
    public static function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::MAILGUN;
    }

    #[Override]
    public static function transports(): array
    {
        return [ChannelTypeEnum::EMAIL->value => EmailTransport::class];
    }

    /**
     * The address the company communicates from on its Mailgun domain (the one it set up, else its
     * own from email when that is on the domain), and the mailbox of every agent that has one. Keyed
     * by the address, because that is what an inbound route names (`recipient`) and what routes a
     * reply to the connection. A mailbox carries its agent, which is who answers mail sent to it.
     */
    #[Override]
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        $app = $integrationCompany->app;

        // The domain belongs to the app's Mailgun account, so a row saved before integrations
        // recorded their app has nothing to name: ResolveIntegrationAppAction leaves it unresolved.
        if ($app === null) {
            return [];
        }

        $companyAddress = self::sendingAddress($app, $integrationCompany->company);
        $accounts = [];

        if ($companyAddress !== null) {
            $accounts[$companyAddress] = new AccountSelection(
                key: $companyAddress,
                label: $companyAddress,
                isDefault: true,
                attributes: ['domain' => Str::after($companyAddress, '@')]
            );
        }

        $mailboxes = new AgentMailboxService();

        foreach (self::agentMailboxes($integrationCompany, $mailboxes) as $address => $agent) {
            $accounts[$address] = new AccountSelection(
                key: $address,
                label: $address . ' (' . $agent->name . ')',
                isDefault: $address === $companyAddress,
                attributes: [
                    'domain' => Str::after($address, '@'),
                    'agent_id' => $agent->getId(),
                    Connection::PERSONAL => $mailboxes->accessFor($agent)->isPersonal(),
                ]
            );
        }

        // The accounting inbox turns attached PDFs into bills on its own receiver. It is not a
        // conversation: as a connection its route would be moved here and the invoices filed as mail.
        $accountingInbox = new AccountingInboxService()->storedAddress($app, $integrationCompany->company);
        unset($accounts[strtolower((string) $accountingInbox)]);

        return array_values($accounts);
    }

    /**
     * @return array<string, Agent> address => the agent whose mailbox it is; the oldest agent keeps
     *                              an address two of them were given
     */
    private static function agentMailboxes(IntegrationsCompany $integrationCompany, AgentMailboxService $mailboxes): array
    {
        $owners = [];

        $agents = Agent::query()->ofIntegration($integrationCompany)->get();

        foreach ($agents as $agent) {
            $address = $mailboxes->addressFor($agent);

            if ($address !== null) {
                $owners[$address] ??= $agent;
            }
        }

        return $owners;
    }

    public static function sendingAddress(AppInterface $app, CompanyInterface $company): ?string
    {
        $domain = self::sendingDomain($app, $company);

        if ($domain === null) {
            return null;
        }

        $configured = strtolower(trim((string) $company->get(ConfigurationEnum::FROM_ADDRESS->value)));

        if ($configured !== '') {
            return Str::after($configured, '@') === $domain ? $configured : null;
        }

        $fromEmail = new SmtpRuntimeConfiguration($app, $company)
            ->getFromEmail()['address'] ?? '';
        $fromEmail = strtolower(trim((string) $fromEmail));

        return $fromEmail !== '' && Str::after($fromEmail, '@') === $domain ? $fromEmail : null;
    }

    public static function sendingDomain(AppInterface $app, CompanyInterface $company): ?string
    {
        try {
            return new AgentMailboxService()->domainForTenant($app, $company);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * The Mailgun domain a connection sends and receives on: the one its account was selected
     * with, which a connection made before accounts carried one reads off its address.
     */
    public static function domainOf(Connection $connection): string
    {
        return (string) ($connection->config['domain'] ?? Str::after($connection->account_key, '@'));
    }

    #[Override]
    public static function onboardable(): bool
    {
        return true;
    }

    /**
     * Mailgun retries a failing delivery for up to 8 hours and reports an open whenever the mail is
     * read; only a permanent failure ends the story.
     */
    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.mailgun', 168)),
            terminalEvents: ['failed']
        );
    }

    /**
     * The signing key a webhook is checked against is read for the receiver's company
     * (`ConfigurationEnum::signingKeyFor()`).
     */
    #[Override]
    public static function sharesAppReceiver(): bool
    {
        return false;
    }

    #[Override]
    public static function provisioner(): string
    {
        return MailgunProvisioner::class;
    }

    #[Override]
    public static function inbound(): string
    {
        return MailgunInbound::class;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::WEBHOOK_SIGNING_KEY->value,
            ConfigurationEnum::DOMAIN->value,
            ConfigurationEnum::FROM_ADDRESS->value,
        ];
    }
}
