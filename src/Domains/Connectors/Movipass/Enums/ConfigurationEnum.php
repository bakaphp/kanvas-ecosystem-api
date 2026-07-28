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
    case CORPORATE_EXISTING_ACCOUNT_TEMPLATE = 'movipass_corporate_existing_account_template';
    case CORPORATE_INVITE_LINK_BASE = 'movipass_corporate_invite_link_base';
    case CORPORATE_ENABLE_LINK = 'movipass_corporate_enable_link';

    case EXPIRING_RESERVATION_MIN = '5';
    case EXPIRING_RESERVATION_MAX = '15';
    case NOTIFICATION_PUSH_TEMPLATE = 'expiring_reservation_push';
    case NOTIFICATION_EMAIL_TEMPLATE = 'expiring_reservation_email';

    case LOW_BALANCE_PUSH_TEMPLATE = 'low_balance_push';
    case LOW_BALANCE_EMAIL_TEMPLATE = 'low_balance_email';
}
