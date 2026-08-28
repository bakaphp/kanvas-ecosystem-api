<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

enum ConfigurationEnum: string
{
    // App-level config naming which client's Credit Request Form layout to parse (a CreditRequestFormClientEnum value). Defaults to NZXT when unset.
    case CREDIT_REQUEST_FORM_CLIENT = 'credit-request-form-client';
}
