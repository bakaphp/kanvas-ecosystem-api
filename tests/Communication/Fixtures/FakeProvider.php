<?php

declare(strict_types=1);

namespace Tests\Communication\Fixtures;

use Carbon\CarbonInterval;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * A provider that, unlike Twilio, keeps reporting after `delivered` — so the tests can tell a
 * delivered message from one the provider has finished talking about.
 */
final class FakeProvider implements CommunicationProvider
{
    public static function slug(): string
    {
        return 'fake';
    }

    public static function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::KANVAS;
    }

    public static function transports(): array
    {
        return [ChannelTypeEnum::SMS->value => FakeTransport::class];
    }

    /**
     * The same shape Twilio persists, so provisioning can be driven with the integration's config.
     */
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        return array_map(
            fn (array $number): AccountSelection => new AccountSelection(
                key: $number['phone_number'],
                label: $number['phone_number'],
                isDefault: (bool) ($number['is_default'] ?? false),
            ),
            $integrationCompany->config['phone_numbers'] ?? []
        );
    }

    public static function onboardable(): bool
    {
        return true;
    }

    public static function eventRetention(): Retention
    {
        return new Retention(CarbonInterval::hours(72), ['failed', 'read']);
    }

    /**
     * Per tenant unless a test asks otherwise; read from config so it resets with the app.
     */
    public static function sharesAppReceiver(): bool
    {
        return (bool) config('communication.fake_shares_app_receiver', false);
    }

    public static function provisioner(): string
    {
        return FakeProvisioner::class;
    }

    public static function inbound(): string
    {
        return FakeInbound::class;
    }
}
