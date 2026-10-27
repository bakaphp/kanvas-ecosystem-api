<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Enums;

use Baka\Support\Str;

enum ChannelTypeEnum: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';
    case MMS = 'mms';
    case EMAIL = 'email';
    case SLACK = 'slack';
    case IN_APP = 'in_app';
    case VOICE = 'voice';

    public function isAddressedByPhone(): bool
    {
        return in_array($this, [self::WHATSAPP, self::SMS, self::MMS, self::VOICE], true);
    }

    public function normalizeAddress(string $address): ?string
    {
        return Str::trimToNull(match (true) {
            $this->isAddressedByPhone() => Str::toE164($address),
            $this === self::EMAIL => strtolower($address),
            default => $address,
        });
    }
}
