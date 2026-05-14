<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Events\AppModuleMessageCreatedEvent;
use Kanvas\Social\Messages\Models\AppModuleMessage;

class AppModuleMessageObserver
{
    public function created(AppModuleMessage $appModuleMessage): void
    {
        AppModuleMessageCreatedEvent::dispatch($appModuleMessage);
    }
}
