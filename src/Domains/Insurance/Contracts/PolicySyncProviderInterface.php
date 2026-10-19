<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\PolicyResult;
use Kanvas\Souk\Orders\Models\Order;

interface PolicySyncProviderInterface
{
    public function syncPolicy(Order $order): PolicyResult;
}
