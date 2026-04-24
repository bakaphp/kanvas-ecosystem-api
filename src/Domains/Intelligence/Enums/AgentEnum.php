<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

enum AgentEnum: string
{
    case VOICE_OUTREACH = 'voiceOutreachAgent';
    case FIRST_MESSAGE_ENGAGER = 'firstMessageEngagerAgent';
    case FOLLOW_UP_ENGAGER = 'FollowUpEngagerAgent';
    case LEAD_INTENT_TOOL = 'LeadIntentTool';
}
