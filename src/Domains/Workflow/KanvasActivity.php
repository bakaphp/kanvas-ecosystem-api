<?php

declare(strict_types=1);

namespace Kanvas\Workflow;

use Baka\Traits\KanvasJobsTrait;
use Closure;
use DateTimeInterface;
use Kanvas\Workflow\Traits\ActivityIntegrationTrait;
use Workflow\Activity;

class KanvasActivity extends Activity
{
    use KanvasJobsTrait;
    use ActivityIntegrationTrait;

    public $queue = 'workflow';
    public $maxExceptions = 10;
    public ?Closure $onUnlock = null;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }
}
