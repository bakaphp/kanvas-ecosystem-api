<?php
declare(strict_types=1);
namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\Users\Models\Users;

class AssignAction
{
    /**
     * __construct
     * @param Users $user
     * @param string $role
     * @return void
     */
    public function __construct(
        public Users $user,
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
