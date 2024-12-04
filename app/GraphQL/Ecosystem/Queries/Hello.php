<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries;

class Hello
{
    public function resolveHello($rootValue, array $args): string
    {
        return 'Kanvas Ecosystem!';
    }

    public function resolve2025($rootValue, array $args): string
    {
        return 'Hello Ecosystem 2025-01!';
    }
}
