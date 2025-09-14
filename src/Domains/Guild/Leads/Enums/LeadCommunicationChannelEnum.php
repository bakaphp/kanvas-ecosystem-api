<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadCommunicationChannelEnum: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';
    case EMAIL = 'email';
}
