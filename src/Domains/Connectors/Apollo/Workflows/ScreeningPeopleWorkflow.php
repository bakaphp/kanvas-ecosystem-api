<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Connectors\Apollo\Workflows\Activities\ScreeningPeopleActivity;
use Kanvas\Guild\Customers\Models\People;
use Workflow\ActivityStub;
use Workflow\Workflow;

class ScreeningPeopleWorkflow extends Workflow
{
    public function execute(AppInterface $app, People $people, array $params): Generator
    {
        return yield ActivityStub::make(ScreeningPeopleActivity::class, $app, $people, $params);
    }
}
