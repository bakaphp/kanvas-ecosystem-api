<?php

declare(strict_types=1);

namespace Baka\Contracts;

use Kanvas\Users\Models\Users;

interface KanvasModelInterface
{
    public function isEntityOwner(Users $users): bool;
}
