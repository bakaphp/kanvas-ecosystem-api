<?php

declare(strict_types=1);

namespace Kanvas\Companies\Contracts;

/**
 * Storefront data: a shopper who is not a member of the store reads it at the company the
 * X-Kanvas-Location header names. Everything else is scoped to a company the user belongs to.
 */
interface ReadableAtLocation
{
}
