<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;

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
    case ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY;
    case GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY;
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
    case FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME;
    case FILESYSTEM_MAPPER_HEADER_VALIDATION;
    case NOTIFICATION_FROM_USER_ID;
    case USE_LEGACY_ROLES;

    /**
     * Get value.
     */
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
            self::ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY => 'admin_user_registration_assign_current_company',
            self::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY => 'global_user_registration_assign_global_company',
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
            self::FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME => 'filesystem_allow_duplicate_files_by_name',
            self::FILESYSTEM_MAPPER_HEADER_VALIDATION => 'filesystem_mapper_header_validation',
            self::NOTIFICATION_FROM_USER_ID => 'notification_from_user_id',
            self::USE_LEGACY_ROLES => 'app_use_legacy_roles',
        };
    }
}
