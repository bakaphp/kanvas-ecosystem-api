<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\CarbonInterval;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Client;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\EmailTransport;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\MailgunInbound;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\MailgunProvisioner;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\AgentMailboxService;
use Kanvas\Communication\Connections\Concerns\ProvisionsConnections;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

/**
 * Email over Mailgun. The signing key, the domain and the address the company communicates from
 * are kept on the company; the integration_companies row only records that the integration was
 * completed. Agent mailboxes stay their own routes: the connection is the company's address.
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
     * The one address the company communicates from on its Mailgun domain: the one it set up, else
     * its own from email when that is on the domain. Keyed by the address, because that is what an
     * inbound route names (`recipient`) and what routes a reply to the connection.
     */
    #[Override]
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        $address = self::sendingAddress($integrationCompany->app, $integrationCompany->company);

        if ($address === null) {
            return [];
        }

        return [new AccountSelection(
            key: $address,
            label: $address,
            isDefault: true,
            attributes: ['domain' => Str::after($address, '@')]
        )];
    }

    public static function sendingAddress(AppInterface $app, CompanyInterface $company): ?string
    {
        $domain = self::sendingDomain($app, $company);

        if ($domain === null) {
            return null;
        }

        $configured = strtolower((string) Str::trimToNull((string) $company->get(ConfigurationEnum::FROM_ADDRESS->value)));

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
     * Mailgun signs with the signing key the receiver's company stored.
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
