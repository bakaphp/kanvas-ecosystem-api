<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\LeadsParticipants;

class AddLeadParticipantAction
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
     * execute.
     */
    public function execute(): LeadsParticipants
    {
        return LeadsParticipants::firstOrCreate([
            'leads_id' => $this->leadParticipant->lead->getId(),
            'peoples_id' => $this->leadParticipant->people->getId(),
        ], [
            'participants_types_id' => $this->leadParticipant->relationship ? $this->leadParticipant->relationship->id : 0,
        ]);
    }
}
