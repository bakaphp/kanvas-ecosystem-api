<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum IntegrationsEnum: string
{
    case SHOPIFY = 'shopify';
    case KANVAS = 'kanvas';
    case VIN_SOLUTION = 'vinsolution';
    case INTELLICHECK = 'intellicheck';
}
