<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Enums;

/**
 * kanvas: the tenant is an associated company under our Alanube main company; we call with our token and
 * scope every request with its company id. own: the tenant's own Alanube account and token.
 */
enum AccountModeEnum: string
{
    case KANVAS = 'kanvas';
    case OWN = 'own';
}
