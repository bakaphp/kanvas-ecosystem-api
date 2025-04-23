<?php

declare(strict_types=1);

namespace Kanvas\Workflow;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Workflow\Traits\ActivityIntegrationTrait;
use Workflow\Activity;

/**
 * hate this , but since the parent class is final we
 * cant extend , need to contact the package owner.
 */
class KanvasActivity extends Activity
{
    use KanvasJobsTrait;
    use ActivityIntegrationTrait;

    //public $tries = 3;
    //public $timeout = 60;
    public $queue = 'workflow';
}
