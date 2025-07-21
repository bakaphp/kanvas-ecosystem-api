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

    case EXPIRING_RESERVATION_MIN = '5';
    case EXPIRING_RESERVATION_MAX = '15';
    case NOTIFICATION_PUSH_TEMPLATE = 'expiring_reservation_push';
    case NOTIFICATION_EMAIL_TEMPLATE = 'expiring_reservation_email';

    case LOW_BALANCE_PUSH_TEMPLATE = 'low_balance_push';
    case LOW_BALANCE_EMAIL_TEMPLATE = 'low_balance_email';
}
