<?php

declare(strict_types=1);

namespace Kanvas\Templates\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Templates\Models\Templates;

class TemplatesRepository
{
    /**
     * Retrieve email template by name.
     *
     * @param $name
     *
     * @return Templates
     */
    public static function getByName(string $name, AppInterface $app): Templates
    {
        /**
         * @psalm-suppress MixedReturnStatement
         */
        return Templates::fromApp()
                            ->notDeleted()
                            ->fromApp($app)
                            ->where('name', $name)
                            ->orderBy('id', 'desc')
                            ->firstOrFail();
    }
}
