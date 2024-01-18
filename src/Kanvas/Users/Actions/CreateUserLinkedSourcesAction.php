<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Users\Models\UserLinkedSources;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\Sources;

class CreateUserLinkedSourcesAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        private Users $user,
        private Sources $source,
        private string $source_users_id_text,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): UserLinkedSources
    {
        return UserLinkedSources::updateOrCreate([
            'users_id' => $this->user->getId(),
            'source_id' => $this->source->getId(),
            'source_users_id_text' => $this->source_users_id_text,
        ], [
            'source_users_id' => $this->user->getId(),
            'source_username' => $this->user->displayname . ' ' . $this->source->title,
            'is_deleted' => 0,
        ]);
    }
}
