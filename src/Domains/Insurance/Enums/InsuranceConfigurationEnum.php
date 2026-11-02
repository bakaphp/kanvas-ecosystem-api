<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceConfigurationEnum: string
{
    case REMINDER_DAYS = 'insurance_reminder_days';
    case REMINDER_CHANNELS = 'insurance_reminder_channels';
    case PAYMENT_PROCESSORS = 'insurance_payment_processors';
}
