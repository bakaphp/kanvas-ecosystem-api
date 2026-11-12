<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\CarbonInterval;
use Exception;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Client;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\SmsTransport;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\TwilioInbound;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\TwilioProvisioner;
use Kanvas\Communication\Connections\Concerns\ProvisionsConnections;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

/**
 * Twilio SMS and MMS. The credentials and the number the company sends from are kept on the
 * company, like every Twilio caller has always read them; the integration_companies row only
 * records that the integration was completed.
 */
class TwilioHandlers extends BaseIntegration implements CommunicationProvider
{
    use ProvisionsConnections;

    #[Override]
    public function setup(): bool
    {
        $accountSid = $this->data['account_sid'] ?? '';
        $authToken = $this->data['auth_token'] ?? '';
        $validated = $this->validateCredentials($accountSid, $authToken);
        if (! $validated) {
            throw new Exception('Failed to validate Twilio connection');
        }

        $fromNumber = Str::trimToNull($this->data['from_number'] ?? null);
        if ($fromNumber !== null) {
            $fromNumber = AgentsRepository::cleanPhoneNumber($fromNumber);
            if (! $this->ownsPhoneNumber($accountSid, $authToken, $fromNumber)) {
                throw new Exception("The number {$fromNumber} is not on this Twilio account");
            }
        }

        $previousSid = $this->company->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $this->company->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, $accountSid);
        $this->company->set(ConfigurationEnum::TWILIO_AUTH_TOKEN->value, $authToken);

        // The from number is the outbound caller id for SMS and the voice agents' fallback; one
        // left over from another account makes every call and text fail at Twilio.
        if ($fromNumber !== null) {
            $this->company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, $fromNumber);
        } elseif ($previousSid !== null && $previousSid !== $accountSid) {
            $this->company->del(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);
        }

        return true;
    }

    #[Override]
    public static function slug(): string
    {
        return 'twilio';
    }

    #[Override]
    public static function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::TWILIO;
    }

    #[Override]
    public static function transports(): array
    {
        return [
            ChannelTypeEnum::SMS->value => SmsTransport::class,
            ChannelTypeEnum::MMS->value => SmsTransport::class,
        ];
    }

    /**
     * The one number the company sends from, in the order every Twilio send has always read it. A
     * Twilio account routinely holds numbers that belong to other tenants or other uses, so "every
     * number on the account" is never the selection.
     */
    #[Override]
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        $company = $integrationCompany->company;
        $number = self::sendingNumber($company);

        if ($number === null) {
            return [];
        }

        return [new AccountSelection(
            key: $number,
            label: $number,
            isDefault: true,
            attributes: array_filter([
                'messaging_service_sid' => Str::trimToNull($company->get(ConfigurationEnum::TWILIO_MESSAGING_SERVICE_SID->value)),
            ])
        )];
    }

    public static function sendingNumber(CompanyInterface $company): ?string
    {
        $number = (string) (
            $company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ?? $company->get(ConfigurationEnum::TWILIO_PHONE_NUMBER->value)
            ?? (ConfigurationEnum::allowedFromNumbers($company)[0] ?? '')
        );

        return Str::trimToNull(Str::toE164($number));
    }

    #[Override]
    public static function onboardable(): bool
    {
        return true;
    }

    /**
     * A message is valid for at most 36 hours before Twilio gives up on it as undelivered, and a
     * status callback that fails is retried for up to another 24.
     */
    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.twilio', 72)),
            terminalEvents: ['delivered', 'undelivered', 'failed', 'canceled', 'read']
        );
    }

    /**
     * Twilio signs with each company's own auth token, over the exact receiver URL.
     */
    #[Override]
    public static function sharesAppReceiver(): bool
    {
        return false;
    }

    #[Override]
    public static function provisioner(): string
    {
        return TwilioProvisioner::class;
    }

    #[Override]
    public static function inbound(): string
    {
        return TwilioInbound::class;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::TWILIO_ACCOUNT_SID->value,
            ConfigurationEnum::TWILIO_AUTH_TOKEN->value,
            ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value,
        ];
    }

    protected function validateCredentials(string $accountSid, string $authToken): bool
    {
        return Client::validateCredentials($accountSid, $authToken);
    }

    protected function ownsPhoneNumber(string $accountSid, string $authToken, string $phoneNumber): bool
    {
        return Client::ownsPhoneNumber($accountSid, $authToken, $phoneNumber);
    }
}
