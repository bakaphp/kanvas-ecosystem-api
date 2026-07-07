<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

/**
 * Custom-field keys where an agent stores its (single) WhatsApp connection — the agent is the
 * owner (1 agent ↔ 1 session), so update/remove read these off the agent via get().
 */
enum ConnectionFieldEnum: string
{
    case SESSION_ID = 'whatsapp_session_id';
    case PHONE_NUMBER = 'whatsapp_phone_number';
    case RECEIVER_ID = 'whatsapp_receiver_id';
}
