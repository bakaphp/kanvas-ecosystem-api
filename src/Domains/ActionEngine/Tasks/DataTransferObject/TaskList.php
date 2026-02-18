<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class TaskList extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?array $config = null,
    ) {
    }
}
