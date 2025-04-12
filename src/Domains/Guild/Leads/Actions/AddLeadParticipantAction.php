<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Workflow\Enums\WorkflowEnum;

class AddLeadParticipantAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected readonly LeadsParticipant $leadParticipant
    ) {
    }

    /**
     * execute.
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): LeadParticipant
    {
        $participant = LeadParticipant::firstOrCreate([
            'leads_id' => $this->leadParticipant->lead->getId(),
            'peoples_id' => $this->leadParticipant->people->getId(),
        ], [
            'participants_types_id' => $this->leadParticipant->relationship ? $this->leadParticipant->relationship->id : 0,
        ]);

        if ($this->runWorkflow) {
            $participant->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true,
                [
                    'app' => $this->leadParticipant->lead->app,
                    'company' => $this->leadParticipant->lead->company,
                ]
            );
        }

        return $participant;
    }
}
