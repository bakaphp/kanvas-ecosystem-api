<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Observers;

use Kanvas\Intelligence\Sessions\Actions\UpdateSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;

class SessionObserver
{
    public function updated(Session $session): void
    {
        (new UpdateSessionAction($session))->execute();
    }
}
