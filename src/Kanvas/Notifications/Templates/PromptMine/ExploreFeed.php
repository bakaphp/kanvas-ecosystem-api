<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates\PromptMine;

use Kanvas\Notifications\Notification;
use Kanvas\Users\Models\Users;

/**
 * @deprecated version 2 , move to DynamicKanvasNotification
 */
class ExploreFeed extends Notification
{
    public ?string $templateName = 'explore-feed';
}
