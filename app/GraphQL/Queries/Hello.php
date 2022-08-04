<?php
declare(strict_types=1);

namespace App\GraphQL\Queries;

class Hello
{
    public function __invoke() : string
    {
        return 'Kanvas Ecosystem!';
    }
}
