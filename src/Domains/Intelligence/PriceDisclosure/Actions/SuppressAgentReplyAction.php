<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PriceDisclosure\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Enums\HandOffTypeEnum;
use Kanvas\Intelligence\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Intelligence\PriceDisclosure\Enums\PriceDisclosureReasonEnum;

/**
 * Fail closed: the disclosure is mandatory, so a missing input hands the lead to a human with a
 * machine-readable reason and marks the turn so the reply gate drops whatever the model wrote.
 */
class SuppressAgentReplyAction
{
    public function __construct(
        private readonly Lead $lead,
        private readonly PriceDisclosureReasonEnum $reason,
        private readonly string $detail,
    ) {
    }

    public function execute(): array
    {
        $handoff = new HandOffAction(
            lead: $this->lead,
            app: $this->lead->app,
            params: [
                'handoff_type' => HandOffTypeEnum::HUMAN->value,
                'reason_code' => $this->reason->value,
                'conversation_summary' => 'Price disclosure suppressed: ' . $this->detail,
            ],
        )->execute();

        $this->lead->set(PriceDisclosureConfigurationEnum::REPLY_SUPPRESSED->value, $this->reason->value);

        return $handoff;
    }
}
