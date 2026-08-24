<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;
use Override;

enum AppSettingsEnums implements EnumsInterface
{
    case DEFAULT_ROLE_NAME;
    case DEFAULT_COUNTRY;
    case DEFAULT_LANGUAGE;
    case SEND_WELCOME_EMAIL;
    case WELCOME_EMAIL_CONFIG;
    case SEND_CREATE_USER_EMAIL;
    case ONBOARDING_GUILD_SETUP;
    case ONBOARDING_INVENTORY_SETUP;
    case ONBOARDING_EVENT_SETUP;
    case ONBOARDING_EVENT_SETUP_TYPE;
    case ONBOARDING_ACTION_ENGINE_SETUP;
    case ONBOARDING_ACTION_ENGINE_SETUP_FROM_COMPANY;
    case ONBOARDING_ORCHESTRATOR_SETUP;
    case ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY;
    case GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY;
    case KANVAS_APP_MAIN_COMPANY_ID;
    case GLOBAL_APP_IMAGES;
    case ONE_SIGNAL_APP_ID;
    case ONE_SIGNAL_REST_API_KEY;
    case PASSWORD_STRENGTH;
    case DEFAULT_SIGNUP_ROLE;
    case INVITE_EMAIL_SUBJECT;
    case RESET_LINK_URL;
    case SOCIALITE_PROVIDER_FACEBOOK;
    case SOCIALITE_PROVIDER_GOOGLE;
    case SOCIALITE_PROVIDER_APPLE;
    case DEFAULT_USER_AVATAR;
    case DEFAULT_COMPANY_AVATAR;
    case INACTIVE_ACCOUNT_ERROR_MESSAGE;
    case INACTIVE_COMPANY_ACCOUNT_ERROR_MESSAGE;
    case RESET_PASSWORD_EMAIL_SUBJECT;
    case SEND_EMAIL_VERIFICATION;
    case EMAIL_VERIFICATION_LINK_URL;
    case EMAIL_VERIFICATION_LINK_TTL_HOURS;
    case EMAIL_VERIFICATION_EMAIL_SUBJECT;
    case FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME;
    case FILESYSTEM_MAPPER_HEADER_VALIDATION;
    case NOTIFICATION_FROM_USER_ID;
    case USE_LEGACY_ROLES;
    case DEFAULT_FILESYSTEM_UPLOAD_FILE_SIZE;
    case ALLOW_RESET_PASSWORD_WITH_DISPLAYNAME;
    case OPEN_AI_EMBEDDING_KEY;
    case ENABLE_GLOBAL_MERGE_FILESYSTEM;
    case DATE_ADK_AGENT_RESPONSES;
    case REGISTRATION_RATE_LIMIT;
    case VALIDATE_EMAIL_DNS;
    case BLOCKED_EMAIL_DOMAINS;
    case SIGNUP_PREFIX_BURST_LIMIT;
    case SIGNUP_PREFIX_BURST_WINDOW;
    case SIGNUP_MAILBOX_LIMIT;
    case SIGNUP_MAILBOX_WINDOW;
    case SIGNUP_ANOMALY_ALERT_EMAILS;
    case SIGNUP_ANOMALY_MULTIPLIER;
    case SIGNUP_ANOMALY_FLOOR;
    case SIGNUP_ANOMALY_BASELINE_DAYS;
    case SIGNUP_ANOMALY_COOLDOWN;
    case SIGNUP_ABUSE_SENTRY_ENABLED;
    case AGENT_CHAT_ASYNC;

    #[Override]
    public function getValue(): mixed
    {
        return match ($this) {
            self::DEFAULT_ROLE_NAME => 'default_admin_role',
            self::DEFAULT_COUNTRY => 'default_user_country',
            self::DEFAULT_LANGUAGE => 'language',
            self::SEND_WELCOME_EMAIL => 'send_welcome_email',
            self::WELCOME_EMAIL_CONFIG => 'welcome_email_template_config',
            self::SEND_CREATE_USER_EMAIL => 'send_create_user_email',
            self::ONBOARDING_GUILD_SETUP => 'onboarding_guild_setup',
            self::ONBOARDING_INVENTORY_SETUP => 'onboarding_inventory_setup',
            self::ONBOARDING_EVENT_SETUP => 'onboarding_event_setup',
            self::ONBOARDING_EVENT_SETUP_TYPE => 'onboarding_event_setup_type',
            self::ONBOARDING_ACTION_ENGINE_SETUP => 'onboarding_action_engine_setup',
            self::ONBOARDING_ACTION_ENGINE_SETUP_FROM_COMPANY => 'onboarding_action_engine_setup_from_company',
            self::ONBOARDING_ORCHESTRATOR_SETUP => 'onboarding_orchestrator_setup',
            self::ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY => 'admin_user_registration_assign_current_company',
            self::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY => 'global_user_registration_assign_global_company',
            self::KANVAS_APP_MAIN_COMPANY_ID => 'kanvas_app_main_company_id',
            self::GLOBAL_APP_IMAGES => 'global_app_images',
            self::ONE_SIGNAL_APP_ID => 'one_signal_app_id',
            self::ONE_SIGNAL_REST_API_KEY => 'one_signal_rest_api_key',
            self::PASSWORD_STRENGTH => 'flag_password_strength',
            self::DEFAULT_SIGNUP_ROLE => 'default_signup_role',
            self::INVITE_EMAIL_SUBJECT => 'invite_email_subject',
            self::RESET_LINK_URL => 'app_reset_link_url',
            self::SOCIALITE_PROVIDER_FACEBOOK => 'facebook_social_config',
            self::SOCIALITE_PROVIDER_GOOGLE => 'google_social_config',
            self::SOCIALITE_PROVIDER_APPLE => 'apple_social_config',
            self::DEFAULT_USER_AVATAR => 'default_user_avatar',
            self::DEFAULT_COMPANY_AVATAR => 'default_company_avatar',
            self::INACTIVE_ACCOUNT_ERROR_MESSAGE => 'inactive_account_error_message',
            self::INACTIVE_COMPANY_ACCOUNT_ERROR_MESSAGE => 'inactive_company_account_error_message',
            self::RESET_PASSWORD_EMAIL_SUBJECT => 'reset_password_email_subject',
            self::SEND_EMAIL_VERIFICATION => 'send_email_verification',
            self::EMAIL_VERIFICATION_LINK_URL => 'app_email_verification_link_url',
            self::EMAIL_VERIFICATION_LINK_TTL_HOURS => 'email_verification_link_ttl_hours',
            self::EMAIL_VERIFICATION_EMAIL_SUBJECT => 'email_verification_email_subject',
            self::FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME => 'filesystem_allow_duplicate_files_by_name',
            self::FILESYSTEM_MAPPER_HEADER_VALIDATION => 'filesystem_mapper_header_validation',
            self::NOTIFICATION_FROM_USER_ID => 'notification_from_user_id',
            self::USE_LEGACY_ROLES => 'app_use_legacy_roles',
            self::DEFAULT_FILESYSTEM_UPLOAD_FILE_SIZE => 'default_filesystem_upload_file_size',
            self::ALLOW_RESET_PASSWORD_WITH_DISPLAYNAME => 'allow_reset_password_with_displayname',
            self::OPEN_AI_EMBEDDING_KEY => 'open_ai_embedding_key',
            self::ENABLE_GLOBAL_MERGE_FILESYSTEM => 'enable_global_merge_filesystem',
            self::DATE_ADK_AGENT_RESPONSES => 'date_adk_agent_responses',
            self::REGISTRATION_RATE_LIMIT => 'registration_rate_limit',
            self::VALIDATE_EMAIL_DNS => 'validate_email_dns',
            self::BLOCKED_EMAIL_DOMAINS => 'blocked_email_domains',
            self::SIGNUP_PREFIX_BURST_LIMIT => 'signup_prefix_burst_limit',
            self::SIGNUP_PREFIX_BURST_WINDOW => 'signup_prefix_burst_window',
            self::SIGNUP_MAILBOX_LIMIT => 'signup_mailbox_limit',
            self::SIGNUP_MAILBOX_WINDOW => 'signup_mailbox_window',
            self::SIGNUP_ANOMALY_ALERT_EMAILS => 'signup_anomaly_alert_emails',
            self::SIGNUP_ANOMALY_MULTIPLIER => 'signup_anomaly_multiplier',
            self::SIGNUP_ANOMALY_FLOOR => 'signup_anomaly_floor',
            self::SIGNUP_ANOMALY_BASELINE_DAYS => 'signup_anomaly_baseline_days',
            self::SIGNUP_ANOMALY_COOLDOWN => 'signup_anomaly_cooldown',
            self::SIGNUP_ABUSE_SENTRY_ENABLED => 'signup_abuse_sentry_enabled',
            self::AGENT_CHAT_ASYNC => 'agent_chat_async',
        };
    }
}
