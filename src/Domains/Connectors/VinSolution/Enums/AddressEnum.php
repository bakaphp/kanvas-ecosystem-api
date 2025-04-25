<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Enums;

enum AddressEnum: string
{
    case HOME = 'Home';
    case PREVIOUS_HOME = 'PreviousHome';
    case EMPLOYER = 'Employer';
    case PREVIOUS_EMPLOYER = 'PreviousEmployer';
    case BILLING = 'Billing';
    case SHIPPING = 'Shipping';
    case OTHER = 'Other';
}
