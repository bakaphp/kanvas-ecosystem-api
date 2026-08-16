<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Enums;

enum PostStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case PRIVATE = 'private';
    case PUBLISH = 'publish';
    case FUTURE = 'future';

    public static function tryFromMixed(mixed $value): ?self
    {
        return is_string($value) && $value !== ''
            ? self::tryFrom(strtolower($value))
            : null;
    }
}
