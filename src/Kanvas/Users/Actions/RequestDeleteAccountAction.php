<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\RequestDeletedAccount;
use Kanvas\Users\Models\Users;

class RequestDeleteAccountAction
{
    public function __construct(
        public Apps $app,
        public Users $user,
    ) {
    }

    public function execute(): bool
    {
        RequestDeletedAccount::create([
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'email' => $this->user->email,
            'request_date' => date('Y-m-d H:i:s'),
        ]);

        return true;

    }
}
