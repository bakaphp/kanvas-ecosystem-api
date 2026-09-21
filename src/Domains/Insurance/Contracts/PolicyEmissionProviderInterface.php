<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\PolicyResult;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Opt-in: an insurer that issues the policy over the wire.
 *
 * Split from reading one back because they are genuinely separate capabilities, not
 * two halves of one. Humano's intermediary API quotes auto and serves the resulting
 * policy but emits only travel, so a single PolicyProviderInterface would have
 * forced it to declare an emit() that throws — a contract that only tells the truth
 * at runtime, after the customer has paid.
 */
interface PolicyEmissionProviderInterface
{
    public function emit(Order $order): PolicyResult;
}
