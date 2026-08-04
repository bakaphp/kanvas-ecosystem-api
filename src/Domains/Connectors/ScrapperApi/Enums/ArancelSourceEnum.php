<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Enums;

enum ArancelSourceEnum: string
{
    case CACHED = 'cached';
    case KEYWORD = 'keyword';
    case FALLBACK = 'fallback';

    public function isRefinable(): bool
    {
        return $this !== self::CACHED;
    }
}
