<?php

declare(strict_types=1);

namespace Kanvas\Sessions\Observers;

use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Models\RequestDeletedAccount;

class SessionObserver
{
    public function created(Sessions $session)
    {
        RequestDeletedAccount::where('users_id', $session->users_id)
                ->where('apps_id', $session->apps_id)
                ->update(['is_deleted' => 1]);
    }
}
