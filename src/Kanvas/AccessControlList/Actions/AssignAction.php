<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\Users\Models\Users;

class AssignAction
{
    /**
     * __construct.
     */
    public function __construct(
        public string|Users $entity,
        public string $role,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): void
    {
        Bouncer::assign($this->role)->to($this->entity);
    }
}
