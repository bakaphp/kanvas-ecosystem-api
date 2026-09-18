<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum ParkingApplicationFieldTypeEnum: string
{
    case STRING = 'string';
    case TEXT = 'text';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case BOOLEAN = 'boolean';
    case ENUM = 'enum';
    case EMAIL = 'email';
    case DATE_TIME = 'date_time';
    case SCHEDULE = 'schedule';
    case CLOSURES = 'closures';
    case PAYMENT_METHODS = 'payment_methods';
}
