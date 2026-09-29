<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PriceDisclosure\Enums;

enum PriceDisclosureChannelEnum: string
{
    case SMS = 'sms';
    case EMAIL = 'email';
}
