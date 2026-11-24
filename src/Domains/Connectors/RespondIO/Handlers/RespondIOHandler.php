<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Handlers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BaseIntegration;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\CarbonInterval;
use Kanvas\Communication\Connections\Concerns\ProvisionsConnections;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Connectors\RespondIO\RespondIOInbound;
use Kanvas\Connectors\RespondIO\RespondIOProvisioner;
use Kanvas\Connectors\RespondIO\WhatsAppTransport;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

/**
 * Respond.io: one bearer token is one workspace, and the workspace is the account. Which of its
 * channels a message leaves on is Respond.io's choice (the contact's last one), so Kanvas addresses
 * a contact by phone and never names a channel.
 */
class RespondIOHandler extends BaseIntegration implements CommunicationProvider
{
    use ProvisionsConnections;

    /**
     * Carried on the integration row by the hand-over from the old responder rule, for a workspace
     * no agent has claimed by number yet.
     */
    public const string LEGACY_AGENT = 'agent_id';

    #[Override]
    public function setup(): bool
    {
        $bearerToken = (string) ($this->data['bearer_token'] ?? '');

        if ($bearerToken === '') {
            throw new ValidationException('RespondIO bearer token is required');
        }

        $validated = Client::validateCredentials($bearerToken);

        if (! $validated) {
            throw new ValidationException('Failed to validate RespondIO connection');
        }

        $this->company->set(ConfigurationEnum::BEARER_TOKEN->value, $bearerToken);

        // Cleared when absent, so re-saving without one leaves nothing of the old value.
        $optional = [
            ConfigurationEnum::PHONE_NUMBER->value => Str::trimToNull(Str::toE164((string) ($this->data['phone_number'] ?? ''))),
            ConfigurationEnum::WEBHOOK_SIGNING_KEY->value => Str::trimToNull((string) ($this->data['webhook_signing_key'] ?? '')),
        ];

        foreach ($optional as $key => $value) {
            if ($value === null) {
                $this->company->del($key);

                continue;
            }

            $this->company->set($key, $value);
        }

        return true;
    }

    #[Override]
    public static function slug(): string
    {
        return 'respondio';
    }

    #[Override]
    public static function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::RESPOND_IO;
    }

    /**
     * Filed as WhatsApp: that is the channel every workspace on Kanvas runs through Respond.io, and
     * the one a lead's conversation is addressed on (a phone number).
     */
    #[Override]
    public static function transports(): array
    {
        return [ChannelTypeEnum::WHATSAPP->value => WhatsAppTransport::class];
    }

    /**
     * The workspace, as one account. The agent that answers is the one whose WhatsApp number is the
     * workspace's; until one claims it, the agent the old responder rule named.
     */
    #[Override]
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        $app = $integrationCompany->app;

        // The key of a workspace with no stated number is built from its app, so a row saved before
        // integrations recorded theirs has nothing to name.
        if ($app === null) {
            return [];
        }

        $key = self::accountKey($app, $integrationCompany->company);
        $agentId = Agent::owningWhatsAppNumber($integrationCompany, $key)?->getId()
            ?? (int) ($integrationCompany->config[self::LEGACY_AGENT] ?? 0);

        return [new AccountSelection(
            key: $key,
            label: 'Respond.io ' . $key,
            isDefault: true,
            attributes: array_filter(['agent_id' => $agentId])
        )];
    }

    /**
     * The workspace's WhatsApp number when the tenant stated it. A webhook never names our side, so
     * a workspace that has not is keyed by a number no phone can have: unique per tenant, and in
     * the E.164 shape an inbound identity on a phone channel is matched in.
     */
    public static function accountKey(AppInterface $app, CompanyInterface $company): string
    {
        $number = Str::toE164((string) $company->get(ConfigurationEnum::PHONE_NUMBER->value));

        return $number !== ''
            ? $number
            : sprintf('+000%05d%08d', $app->getId(), $company->getId());
    }

    /**
     * Respond.io has no API to create a webhook: the tenant pastes the receiver URL into its
     * workspace. Everything else about the connection is still set up for them.
     */
    #[Override]
    public static function onboardable(): bool
    {
        return false;
    }

    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.respondio', 168)),
            terminalEvents: ['failed', 'read']
        );
    }

    /**
     * Each workspace is given its own receiver URL, which is what names the tenant.
     */
    #[Override]
    public static function sharesAppReceiver(): bool
    {
        return false;
    }

    #[Override]
    public static function provisioner(): string
    {
        return RespondIOProvisioner::class;
    }

    #[Override]
    public static function inbound(): string
    {
        return RespondIOInbound::class;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::BEARER_TOKEN->value,
            ConfigurationEnum::PHONE_NUMBER->value,
            ConfigurationEnum::WEBHOOK_SIGNING_KEY->value,
        ];
    }
}
