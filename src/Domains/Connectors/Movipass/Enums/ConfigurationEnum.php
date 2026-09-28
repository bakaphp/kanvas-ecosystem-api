<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum ConfigurationEnum: string
{
    case EXPIRING_RESERVATION_MIN_FIELD = 'expiringReservationMin';
    case EXPIRING_RESERVATION_MAX_FIELD = 'expiringReservationMax';
    case NOTIFICATION_PUSH_TEMPLATE_FIELD = 'notificationPushTemplate';
    case NOTIFICATION_EMAIL_TEMPLATE_FIELD = 'notificationEmailTemplate';
    case LOW_BALANCE_PUSH_TEMPLATE_FIELD = 'lowBalancePushTemplate';
    case LOW_BALANCE_EMAIL_TEMPLATE_FIELD = 'lowBalanceEmailTemplate';
    case GRACE_PERIOD_DAYS = 'movipass_order_grace_period_days';
    case QR_CODE_HOST = 'movipass_qr_code_host';
    case CORPORATE_RECEIVER_ID = 'movipass_corporate_receiver_id';
    case CORPORATE_AUTO_APPROVE = 'movipass_corporate_auto_approve';
    case CORPORATE_WELCOME_TEMPLATE = 'movipass_corporate_welcome_template';
    case CORPORATE_NEEDS_REVIEW_TEMPLATE = 'movipass_corporate_needs_review_template';
    case CORPORATE_INVITE_LINK_BASE = 'movipass_corporate_invite_link_base';
    case ROADSIDE_MAX_RESCHEDULES = 'movipass_roadside_max_reschedules';

    case ROADSIDE_PROVIDER_BASE_URL = 'movipass_roadside_provider_base_url';
    case ROADSIDE_PROVIDER_API_TOKEN = 'movipass_roadside_provider_api_token';
    case ROADSIDE_PROVIDER_AUTH_HEADER = 'movipass_roadside_provider_auth_header';
    case ROADSIDE_PROVIDER_AUTH_SCHEME = 'movipass_roadside_provider_auth_scheme';
    case ROADSIDE_PROVIDER_CATALOG_CACHE_TTL = 'movipass_roadside_provider_catalog_cache_ttl';
    case ROADSIDE_PROVIDER_POLL_INTERVAL = 'movipass_roadside_provider_poll_interval_seconds';
    case ROADSIDE_PROVIDER_STATE_MAP = 'movipass_roadside_provider_state_map';

    case ROADSIDE_PROVIDER_DEFAULT_AUTH_HEADER = 'Authorization';
    case ROADSIDE_PROVIDER_DEFAULT_AUTH_SCHEME = 'Bearer';
    case ROADSIDE_PROVIDER_DEFAULT_CATALOG_CACHE_TTL = '3600';
    case ROADSIDE_PROVIDER_DEFAULT_POLL_INTERVAL = '120';

    case EXPIRING_RESERVATION_MIN = '5';
    case EXPIRING_RESERVATION_MAX = '15';
    case NOTIFICATION_PUSH_TEMPLATE = 'expiring_reservation_push';
    case NOTIFICATION_EMAIL_TEMPLATE = 'expiring_reservation_email';

    case LOW_BALANCE_PUSH_TEMPLATE = 'low_balance_push';
    case LOW_BALANCE_EMAIL_TEMPLATE = 'low_balance_email';
}
