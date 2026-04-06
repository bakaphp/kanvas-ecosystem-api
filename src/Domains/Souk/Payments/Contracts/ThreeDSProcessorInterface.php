<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Contracts;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\DataTransferObject\ThreeDSResult;
use Kanvas\Souk\Payments\Models\Payments;

interface ThreeDSProcessorInterface
{
    /**
     * Initiate the 3DS challenge flow.
     * Returns device data collection URL, access token, and reference ID
     * that the client needs to complete the browser challenge.
     */
    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult;

    /**
     * Finalize the 3DS challenge after the browser step completes.
     * Validates enrollment result and advances to authorization.
     */
    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult;
}
