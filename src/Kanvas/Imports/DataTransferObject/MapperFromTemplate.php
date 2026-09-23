<?php

declare(strict_types=1);

namespace Kanvas\Imports\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Spatie\LaravelData\Data;

class MapperFromTemplate extends Data
{
    public function __construct(
        public readonly ImportTemplate $template,
        public readonly AppInterface $app,
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly array $options = [],
    ) {
    }
}
