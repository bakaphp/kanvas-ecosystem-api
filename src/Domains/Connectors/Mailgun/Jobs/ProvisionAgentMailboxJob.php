<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Jobs;

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Jobs\ProvisionAgentMailboxJob as CurrentProvisionAgentMailboxJob;

/**
 * @deprecated moved to {@see CurrentProvisionAgentMailboxJob}. Queued by class name before the move: payloads serialized under this name are still waiting in Redis at deploy time.
 */
class ProvisionAgentMailboxJob extends CurrentProvisionAgentMailboxJob
{
}
