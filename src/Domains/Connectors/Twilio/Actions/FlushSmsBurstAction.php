<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions\FlushSmsBurstAction as CurrentFlushSmsBurstAction;

/**
 * @deprecated moved to {@see CurrentFlushSmsBurstAction}. Queued by class name before the move: payloads serialized under this name are still waiting in Redis at deploy time.
 */
class FlushSmsBurstAction extends CurrentFlushSmsBurstAction
{
}
