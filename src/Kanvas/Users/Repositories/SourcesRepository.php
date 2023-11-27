<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Sources;

class SourcesRepository
{
    public static function getByTitle(string $title): Sources
    {
        /**
         * @var Sources
         */
        return Sources::where('title', $title)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }
}
