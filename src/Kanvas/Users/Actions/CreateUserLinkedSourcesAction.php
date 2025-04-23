<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Users\Models\Sources;
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
        protected Users $user,
        protected AppInterface $app,
        protected Sources $source,
        protected string $source_users_id_text,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): UserLinkedSources
    {
        return UserLinkedSources::updateOrCreate([
            'users_id'             => $this->user->getId(),
            'source_id'            => $this->source->getId(),
            'source_users_id_text' => $this->source_users_id_text,
        ], [
            'source_users_id' => $this->user->getId(),
            'source_username' => $this->user->displayname.' '.$this->source->title,
            'apps_id'         => $this->app->getId(),
            'is_deleted'      => 0,
        ]);
    }
}
