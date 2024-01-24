<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Contracts;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;

interface WorkflowActivityInterface
{
    /**
     *  @no-named-arguments
     */
    public function execute(Model $entity, AppInterface $app, array $params): array;
}
