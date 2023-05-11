<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\LeadParticipant;

class RemoveLeadParticipantAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected readonly LeadsParticipant $leadParticipant
    ) {
    }

    /**
     * Execute.
     */
    public function execute(): bool
    {
        return LeadParticipant::where(
            'leads_id',
            $this->leadParticipant->lead->getId()
        )
        ->where('peoples_id', $this->leadParticipant->people->getId())
        ->firstOrFail()->delete();
    }
}
