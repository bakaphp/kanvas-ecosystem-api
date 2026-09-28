<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

// Which client's Credit Request Form layout an app uses. Add a case here + a matching arm in CreditRequestFormParserFactory when a new client's format needs support — never branch inside an existing client's parser.
enum CreditRequestFormClientEnum: string
{
    case NZXT = 'nzxt';
}
