<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Users\Models\UserLinkedSources;
use Kanvas\Users\Models\Users;

class CreateUserLinkedSourcesAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        private Users $user,
        private int $source_id,
        private string $source_users_id,
        private string $source_users_id_text,
        private string $source_username,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): UserLinkedSources
    {
        $userLinkedSouce = new UserLinkedSources();
        $userLinkedSouce->users_id = $this->user->getId();
        $userLinkedSouce->source_id = $this->source_id;
        $userLinkedSouce->source_users_id = $this->source_users_id;
        $userLinkedSouce->source_users_id_text = $this->source_users_id_text;
        $userLinkedSouce->source_username = $this->source_username;
        $userLinkedSouce->created_at = date('Y-m-d H:i:s');
        $userLinkedSouce->is_deleted = 0;
        $userLinkedSouce->saveOrFail();

        return $userLinkedSouce;
    }
}
