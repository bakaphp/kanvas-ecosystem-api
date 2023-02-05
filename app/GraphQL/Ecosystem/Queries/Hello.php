<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries;

use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;

class Hello
{
    public function __invoke() : string
    {
        return 'Kanvas Ecosystem!';
    }
}
