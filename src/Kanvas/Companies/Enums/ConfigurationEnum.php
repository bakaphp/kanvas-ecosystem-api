<?php

declare(strict_types=1);

namespace Kanvas\Companies\Enums;

enum ConfigurationEnum: string
{
    case WORKING_HOLIDAY_DAYS = 'working_holiday_days';
    case WORKING_HOURS = 'work_hours';
    case WORKING_DAYS = 'working_days';
    case SPECIAL_DAYS = 'special_days';
    case COUNTRY_CODE = 'country_code';
    case HAVE_FOLLOW_UP = 'have_follow_up';
    case MESSAGE_MINUTES_INTERVAL = 'message_minutes_interval';
    case MAPPING_STATUS_CRM = 'mapping_status_crm';
    case UN_RESPONDED_SALESPERSON_MESSAGES = 'un_responded_salesperson_messages';
    case AI_MODE = 'ai_mode';
    case ALLOW_CALL_APPOINTMENTS = 'allow_call_appointments';
    case GIF_DURATION_SECONDS = 'engagement_gif_duration_seconds';
    case ENABLE_VIDEO_GIF_GENERATION = 'engagement_enable_video_gif_generation';
    case DATE_ADK_AGENT_RESPONSES = 'date_adk_agent_responses';
    case CHANNEL_ORDER = 'guild_channel_order';
}
