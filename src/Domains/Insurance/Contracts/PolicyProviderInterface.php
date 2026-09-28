<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\PolicyResult;
use Kanvas\Souk\Orders\Models\Order;

interface PolicyProviderInterface
{
    public function emit(Order $order): PolicyResult;

    /**
     * Re-read the policy from the insurer. Pay + emit often complete out of band,
     * so this is what a polling workflow calls to catch up.
     */
    public function syncPolicy(Order $order): PolicyResult;
}
