<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Connectors\Internal\Activities\GenerateMessageSlugActivity;
use Kanvas\Social\Messages\Models\Message;
use Workflow\ActivityStub;
use Workflow\Workflow;

class GenerateMessageSlugWorkflow extends Workflow
{
    public function execute(AppInterface $app, Message $message, array $params): Generator
    {
        $result = yield ActivityStub::make(GenerateMessageSlugActivity::class, $app, $message, $params);

        return $result;
    }
}
