<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

enum CustomFieldEnum: string
{
    /**
     * The phone-form JID. Only set when WhatsApp disclosed the number.
     */
    case WHATSAPP_JID = 'whatsapp_jid';

    /**
     * WhatsApp's opaque per-account id under lid addressing. In a group this is often the only
     * thing we get for a speaker — it is NOT a phone number and must never be filed as one.
     */
    case WHATSAPP_LID = 'whatsapp_lid';
}
