<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

use Kanvas\Companies\Models\Companies;

enum ConfigurationEnum: string
{
    case SEND_NEW_LEAD_NOTIFICATION = 'guild_send_new_lead_notification';
    case SEND_NEW_LEAD_TO_OWNER_NOTIFICATION = 'guild_send_new_lead_to_owner_notification';
    case AGENT_COMMUNICATION_CHANNEL = 'guild_agent_communication_channel';
    case FIRST_MESSAGE = 'guild_first_message';
    case SENT_FIRST_MESSAGE_AT = 'guild_first_message_sent_at';
    case IS_ENGAGEMENT = 'guild_is_engagement';
    case CONTACTED = 'guild_lead_contacted';
    case FOLLOW_UP_LEAD_TYPE = 'guild_follow_up_lead_type';
    case PREFERRED_CHANNEL = 'guild_preferred_channel';
    case IS_FROM_WHATSAPP = 'guild_is_from_whatsapp';
    case LAST_CHANNEL_USED = 'guild_last_channel_used';
    case GUILD_PREFERRED_CHANNEL_UUID = 'guild_prefered_channel_uuid';
    case AI_MODE = 'lead_ai_mode';
    case HAS_FOLLOW_UP = 'lead_has_follow_up';
    case FOLLOW_UP_SOURCE_SYNCED_AT = 'guild_follow_up_source_synced_at';
    case AI_MODE_IS_MANUAL = 'lead_ai_mode_is_manual';
    case AI_FOLLOW_UP_IS_MANUAL = 'ai_follow_up_manual';
    case CONVERTED_TO_DEAL_ID = 'converted_to_deal_id';
    case OPEN_LEADS_STATUS_IDS = 'guild_open_leads_status_ids';
    case DAILY_LEADS_DIGEST_ENABLED = 'guild_daily_leads_digest_enabled';
    case DAILY_LEADS_DIGEST_HOURS = 'guild_daily_leads_digest_hours';
    case DAILY_LEADS_DIGEST_EXCLUDED_COMPANIES = 'guild_daily_leads_digest_excluded_companies';
    case DAILY_LEADS_DIGEST_EXCLUDED_EMAILS = 'guild_daily_leads_digest_excluded_emails';
    case DAILY_LEADS_DIGEST_RECIPIENTS = 'guild_daily_leads_digest_recipients';
    case DAILY_LEADS_DIGEST_COMBINED = 'guild_daily_leads_digest_combined';
    case DAILY_LEADS_DIGEST_SUMMARY_FIELDS = 'guild_daily_leads_digest_summary_fields';
    case AGENT_EMAIL_CC_ALLOWED_DOMAINS = 'guild_agent_email_cc_allowed_domains';
    case EMAIL_THREAD_SUBJECT = 'title_email_follow_up';
    case CRM_DOWNLOAD_SERVICE_LEADS = 'guild_crm_download_service_leads';

    // App settings named when Twilio was the only carrier; the keys stay so saved values still apply.
    case SMS_MEDIA_BATCH_SIZE = 'twilio-mms-batch-size';
    case SMS_MEDIA_MAX_TOTAL = 'twilio-mms-max-total-media';

    /**
     * A dealer CRM files service-department visits as leads of type SERVICE (VinSolutions) or
     * Service (eLeads). The downloads leave them out unless the company turns the setting on.
     */
    public static function skipsCrmServiceLead(Companies $company, ?string $leadTypeName): bool
    {
        return in_array(strtolower(trim((string) $leadTypeName)), ['service', 'services'], true)
            && ! $company->getBool(self::CRM_DOWNLOAD_SERVICE_LEADS->value);
    }
}
