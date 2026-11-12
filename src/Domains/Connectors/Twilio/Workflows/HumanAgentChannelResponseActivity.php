<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Workflows;

use Kanvas\Communication\Workflows\HumanAgentChannelResponseActivity as CurrentHumanAgentChannelResponseActivity;

/**
 * @deprecated moved to {@see CurrentHumanAgentChannelResponseActivity}. Rules saved before the move name this class in `actions.model_name`, and workflow runs in flight carry it.
 */
class HumanAgentChannelResponseActivity extends CurrentHumanAgentChannelResponseActivity
{
}
