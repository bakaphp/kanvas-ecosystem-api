<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\AgentChannelResponderAction as CurrentAgentChannelResponderAction;

/**
 * @deprecated moved to {@see CurrentAgentChannelResponderAction}. Queued by class name before the move: payloads serialized under this name are still waiting in Redis at deploy time.
 */
class AgentChannelResponderAction extends CurrentAgentChannelResponderAction
{
}
