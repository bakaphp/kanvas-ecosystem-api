<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Smtp;

use Baka\Support\Str;
use Carbon\CarbonInterval;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Contracts\BuiltInCarrier;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Override;

/**
 * Email on the SMTP server the company (or its app) is configured with, whoever hosts it. What every
 * company sends through until it connects an email provider of its own.
 */
final class SmtpCarrier implements BuiltInCarrier
{
    #[Override]
    public static function slug(): string
    {
        return 'smtp';
    }

    #[Override]
    public static function transports(): array
    {
        return [ChannelTypeEnum::EMAIL->value => SmtpTransport::class];
    }

    /**
     * An SMTP server reports nothing itself. The window is for the host that does report on what it
     * relayed (Mailgun's events find the delivery by its Message-ID).
     */
    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.smtp', 168)),
            terminalEvents: ['failed']
        );
    }

    #[Override]
    public static function account(Apps $app, Companies $company): ?AccountSelection
    {
        $address = Str::trimToNull((string) (new SmtpRuntimeConfiguration($app, $company)->getFromEmail()['address'] ?? ''));

        if ($address === null) {
            return null;
        }

        $address = strtolower($address);

        return new AccountSelection(key: $address, label: $address, isDefault: true);
    }
}
