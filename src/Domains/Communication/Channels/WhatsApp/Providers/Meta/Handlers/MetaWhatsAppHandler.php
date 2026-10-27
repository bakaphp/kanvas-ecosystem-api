<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers;

use Baka\Support\Str;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Client;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\MetaInbound;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\MetaProvisioner;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\WhatsAppTransport;
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
 * WhatsApp Cloud API (Meta). The number and its token are kept on the company; the
 * integration_companies row only records that the integration was completed.
 */
class MetaWhatsAppHandler extends BaseIntegration implements CommunicationProvider
{
    use ProvisionsConnections;

    #[Override]
    public function setup(): bool
    {
        $phoneNumberId = Str::trimToNull($this->data['phone_number_id'] ?? null);
        $accessToken = Str::trimToNull($this->data['access_token'] ?? null);

        if ($phoneNumberId === null || $accessToken === null) {
            throw new ValidationException('The WhatsApp phone number id and access token are required.');
        }

        $phoneNumber = $this->fetchPhoneNumber($phoneNumberId, $accessToken);

        $this->company->set(ConfigurationEnum::PHONE_NUMBER_ID->value, $phoneNumberId);
        $this->company->setEncrypted(ConfigurationEnum::ACCESS_TOKEN->value, $accessToken);

        // Cleared when absent, so re-saving with another number leaves nothing of the old one.
        $optional = [
            ConfigurationEnum::WABA_ID->value => Str::trimToNull($this->data['waba_id'] ?? null),
            ConfigurationEnum::DISPLAY_PHONE_NUMBER->value => Str::trimToNull($phoneNumber['display_phone_number'] ?? null),
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
        return 'meta';
    }

    #[Override]
    public static function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::META_WHATSAPP;
    }

    #[Override]
    public static function transports(): array
    {
        return [ChannelTypeEnum::WHATSAPP->value => WhatsAppTransport::class];
    }

    /**
     * The one number the company set up. Keyed by the number itself, in E.164, because that is
     * what a webhook names (`metadata.display_phone_number`) and what routes it to the connection.
     */
    #[Override]
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        $company = $integrationCompany->company;
        $phoneNumberId = Str::trimToNull((string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        $number = Str::toE164((string) $company->get(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value));

        if ($phoneNumberId === null || $number === '') {
            return [];
        }

        return [new AccountSelection(
            key: $number,
            label: $number,
            isDefault: true,
            attributes: array_filter([
                'phone_number_id' => $phoneNumberId,
                'waba_id' => Str::trimToNull((string) $company->get(ConfigurationEnum::WABA_ID->value)),
            ])
        )];
    }

    #[Override]
    public static function onboardable(): bool
    {
        return true;
    }

    /**
     * Meta retries a webhook it could not deliver for up to 7 days, and `read` can follow
     * `delivered` at any point inside that.
     */
    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.meta', 168)),
            terminalEvents: ['failed', 'read']
        );
    }

    /**
     * Each WABA takes one callback override, so every app's numbers post to the one URL.
     */
    #[Override]
    public static function sharesGlobalReceiver(): bool
    {
        return true;
    }

    #[Override]
    public static function provisioner(): string
    {
        return MetaProvisioner::class;
    }

    #[Override]
    public static function inbound(): string
    {
        return MetaInbound::class;
    }

    /**
     * @return array{id: string, display_phone_number?: string, verified_name?: string}
     */
    protected function fetchPhoneNumber(string $phoneNumberId, string $accessToken): array
    {
        try {
            return Client::forApp($this->app, $accessToken)->phoneNumber($phoneNumberId);
        } catch (InvalidArgumentException) {
            throw new ValidationException('The WhatsApp phone number id must be numeric.');
        } catch (RequestException $exception) {
            throw new ValidationException(
                'Meta rejected the WhatsApp phone number id or access token: '
                . ($exception->response->json('error.message') ?? $exception->getMessage())
            );
        }
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::PHONE_NUMBER_ID->value,
            ConfigurationEnum::ACCESS_TOKEN->value,
            ConfigurationEnum::WABA_ID->value,
            ConfigurationEnum::DISPLAY_PHONE_NUMBER->value,
        ];
    }
}
