<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\PolicyResult;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Opt-in: an insurer we can read a policy back from. Pay + emit often complete out
 * of band, so this is what a polling workflow calls to catch up — including for an
 * insurer that never emits through us at all.
 */
interface PolicySyncProviderInterface
{
    public function syncPolicy(Order $order): PolicyResult;
}
