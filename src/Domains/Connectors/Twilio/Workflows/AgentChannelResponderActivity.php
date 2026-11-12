<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Workflows;

use Kanvas\Communication\Channels\Sms\Providers\Twilio\Workflows\AgentChannelResponderActivity as CurrentAgentChannelResponderActivity;

/**
 * @deprecated moved to {@see CurrentAgentChannelResponderActivity}. Rules saved before the move name this class in `actions.model_name`, and workflow runs in flight carry it.
 */
class AgentChannelResponderActivity extends CurrentAgentChannelResponderActivity
{
}
