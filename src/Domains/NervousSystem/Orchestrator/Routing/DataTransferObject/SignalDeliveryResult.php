<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\SignalDeliveryStatusEnum;

final readonly class SignalDeliveryResult
{
    public function __construct(
        public SignalDeliveryStatusEnum $status,
        public string $reason,
        public ?Agent $agent = null,
        public ?int $messageId = null,
    ) {
    }
}
