<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Support;

use Kanvas\Exceptions\ValidationException;

/**
 * SOQL has no bound-parameter support, so every value we interpolate has to be guarded here.
 * Identifiers (object and field names) can't be escaped at all — they are accepted or rejected.
 */
final class Soql
{
    public static function assertValidIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new ValidationException("Invalid Salesforce object/field name: {$identifier}");
        }

        return $identifier;
    }

    public static function escapeLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
