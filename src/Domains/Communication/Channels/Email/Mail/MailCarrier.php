<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Mail;

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
 * Email through the Kanvas mailer: the company's own mail server when it set one, else its app's,
 * else the Kanvas default. What every company sends through until it connects an email provider.
 */
final class MailCarrier implements BuiltInCarrier
{
    #[Override]
    public static function slug(): string
    {
        return 'mail';
    }

    #[Override]
    public static function transports(): array
    {
        return [ChannelTypeEnum::EMAIL->value => MailTransport::class];
    }

    /**
     * A mail server reports nothing itself. The window is for the host that does report on what it
     * relayed (Mailgun's events find the delivery by its Message-ID).
     */
    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.mail', 168)),
            terminalEvents: ['failed']
        );
    }

    #[Override]
    public static function account(Apps $app, Companies $company): ?AccountSelection
    {
        $from = new SmtpRuntimeConfiguration($app, $company)->getFromEmail();
        $address = Str::trimToNull((string) ($from['address'] ?? ''));

        if ($address === null) {
            return null;
        }

        $address = strtolower($address);

        return new AccountSelection(key: $address, label: $address, isDefault: true);
    }
}
