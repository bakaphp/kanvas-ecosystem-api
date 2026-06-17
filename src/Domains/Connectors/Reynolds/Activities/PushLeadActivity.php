<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Kanvas\Connectors\SalesAssist\Activities\PushLeadActivity as SalesAssistPushLeadActivity;
use Kanvas\Workflow\Attributes\WorkflowAction;

#[WorkflowAction]
class PushLeadActivity extends SalesAssistPushLeadActivity
{
}
