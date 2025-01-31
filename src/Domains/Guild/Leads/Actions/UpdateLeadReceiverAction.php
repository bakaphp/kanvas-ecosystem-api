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
        protected int $id,
        protected readonly LeadReceiver $leadReceiver,
    ) {
    }

    public function execute(): ModelsLeadReceiver
    {
        return ModelsLeadReceiver::updateOrCreate([
            'id' => $this->id,
            'companies_branches_id' => $this->leadReceiver->branch->getId(),
            'companies_id' => $this->leadReceiver->branch->company->getId(),
            'apps_id' => $this->leadReceiver->app->getId(),
        ], [
            'name' => $this->leadReceiver->name,
            'users_id' => $this->leadReceiver->user->getId(),
            'agents_id' => $this->leadReceiver->agent->getId(),
            'is_default' => (int) $this->leadReceiver->isDefault,
            'rotations_id' => $this->leadReceiver->rotation ? $this->leadReceiver->rotation->getId() : 0,
            'source_name' => $this->leadReceiver->source,
        ]);
    }
}
