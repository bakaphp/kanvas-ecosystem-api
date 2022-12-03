<?php
declare(strict_types=1);
namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\Users\Models\Users;

class AssignAction
{
    /**
     * __construct
     * @param $entity
     * @param string $role
     * @return void
     */
    public function __construct(
        public string|Users $entity,
        public string $role,
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        Bouncer::assign($this->role)->to($this->entity);
    }
}
