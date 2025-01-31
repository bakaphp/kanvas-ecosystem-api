<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\DataTransferObject\LeadsReceiver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver as ModelsLeadReceiver;

class UpdateLeadReceiverAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected ModelsLeadReceiver $leadReceiver,
        protected readonly LeadReceiver $leadReceiverDto,
    ) {
    }

    public function execute(): ModelsLeadReceiver
    {
        $this->leadReceiver->update([
            'name' => $this->leadReceiverDto->name,
            'users_id' => $this->leadReceiverDto->user->getId(),
            'agents_id' => $this->leadReceiverDto->agent->getId(),
            'is_default' => (int) $this->leadReceiverDto->isDefault,
            'rotations_id' => $this->leadReceiverDto->rotation ? $this->leadReceiverDto->rotation->getId() : 0,
            'source_name' => $this->leadReceiverDto->source,
            'leads_sources_id' => $this->leadReceiverDto->lead_sources_id,
            'lead_types_id' => $this->leadReceiverDto->lead_types_id,
            'template' => $this->leadReceiver->template,
        ]);
        return $this->leadReceiver;
    }
}
