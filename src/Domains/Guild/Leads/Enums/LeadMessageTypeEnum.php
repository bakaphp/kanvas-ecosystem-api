<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadMessageTypeEnum: string
{
    case NOTES = 'notes';
    case AI_ASSIST = 'ai_assist';
    case INTERNAL = 'internal';
    case CONVERSATION_SUMMARY = 'summary';
}
