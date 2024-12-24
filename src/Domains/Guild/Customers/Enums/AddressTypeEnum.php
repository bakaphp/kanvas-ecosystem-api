<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

enum AddressTypeEnum: string
{
    case HOME = 'Home';
    case PREVIOUS_HOME = 'PreviousHome';
    case EMPLOYER = 'Employer';
    case PREVIOUS_EMPLOYER = 'PreviousEmployer';
    case BILLING = 'Billing';
    case SHIPPING = 'Shipping';
    case OTHER = 'Other';
}
