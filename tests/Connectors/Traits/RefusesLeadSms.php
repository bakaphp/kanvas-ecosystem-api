<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Models\Lead;
use Override;

trait RefusesLeadSms
{
    /**
     * The real lead send, with the SMS refused where the pre-send validation refuses it: before
     * Twilio is called, so the result carries no SID and no Twilio error code.
     */
    protected function smsRefusingSender(Lead $lead, string $reason): SendMessageToLeadAction
    {
        return new class ($lead, $reason) extends SendMessageToLeadAction {
            public function __construct(
                Lead $lead,
                private readonly string $reason,
            ) {
                parent::__construct($lead);
            }

            #[Override]
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new LeadMissingContactException($this->reason);
            }
        };
    }
}
