<?php

declare(strict_types=1);

namespace Kanvas\Activities\Contracts;

use Illuminate\Support\Collection;

interface ActivityLogInterface
{
    public function getActivityLogName(): String;

    public function getActivities(): Collection;
}
