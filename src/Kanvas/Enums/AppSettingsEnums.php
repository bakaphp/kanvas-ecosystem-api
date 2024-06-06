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
    case SEND_CREATE_USER_EMAIL;
    case ONBOARDING_GUILD_SETUP;
    case ONBOARDING_INVENTORY_SETUP;
    case ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY;
    case GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY;
    case ONE_SIGNAL_APP_ID;
    case ONE_SIGNAL_REST_API_KEY;
    case PASSWORD_STRENGTH;
    case DEFAULT_SIGNUP_ROLE;
    case INVITE_EMAIL_SUBJECT;
    case RESET_LINK_URL;
    case SOCIALITE_PROVIDER_FACEBOOK;
    case SOCIALITE_PROVIDER_GOOGLE;
    case SOCIALITE_PROVIDER_APPLE;

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
            self::SEND_CREATE_USER_EMAIL => 'send_create_user_email',
            self::ONBOARDING_GUILD_SETUP => 'onboarding_guild_setup',
            self::ONBOARDING_INVENTORY_SETUP => 'onboarding_inventory_setup',
            self::ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY => 'admin_user_registration_assign_current_company',
            self::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY => 'global_user_registration_assign_global_company',
            self::ONE_SIGNAL_APP_ID => 'one_signal_app_id',
            self::ONE_SIGNAL_REST_API_KEY => 'one_signal_rest_api_key',
            self::PASSWORD_STRENGTH => 'flag_password_strength',
            self::DEFAULT_SIGNUP_ROLE => 'default_signup_role',
            self::INVITE_EMAIL_SUBJECT => 'invite_email_subject',
            self::RESET_LINK_URL => 'app_reset_link_url',
            self::SOCIALITE_PROVIDER_FACEBOOK => 'facebook_social_config',
            self::SOCIALITE_PROVIDER_GOOGLE => 'google_social_config',
            self::SOCIALITE_PROVIDER_APPLE => 'apple_social_config',
        };
    }
}
