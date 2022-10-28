<?php
declare(strict_types=1);
namespace Kanvas\Users\Invites\Actions;

use \Kanvas\Users\Invites\Repository\UsersInvite;

class DeleteInviteAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        int $id
    ) {
        $this->id = $id;
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        $invite = UsersInvite::getById($this->id);
        $invite->delete();
    }
}
