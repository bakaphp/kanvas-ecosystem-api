<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\DataTransferObject\LeadRotation as LeadRotationDto;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Guild\Leads\Models\LeadRotationAgent;

class CreateLeadRotationAction
{
    public function __construct(
        public LeadRotationDto $leadRotationDto
    ) {
    }

    public function execute(): LeadRotation
    {
        $leadRotation = LeadRotation::create([
            'companies_id' => $this->leadRotationDto->company->getId(),
            'apps_id' => $this->leadRotationDto->app->getId(),
            'name' => $this->leadRotationDto->name,
            'leads_rotations_email' => $this->leadRotationDto->leadsRotationsEmail,
            'hits' => $this->leadRotationDto->hits
        ]);
        if (! empty($this->leadRotationDto->agents)) {
            foreach ($this->leadRotationDto->agents as $agent) {
                $user = Users::getById($agent['users_id'], $this->leadRotationDto->app);
                $leadRotationAgent = new LeadRotationAgent();
                $leadRotationAgent->phone = $agent['phone'];
                $leadRotationAgent->percent = $agent['percent'];
                $leadRotationAgent->users_id = $user->getId();
                $leadRotationAgent->companies_id = $this->leadRotationDto->company->getId();
                $leadRotationAgent->hits = key_exists('hits', $agent) ? $agent['hits'] : 0;
                $leadRotation->agents()->save($leadRotationAgent);
                $leadRotation->save();
            }
        }
        return $leadRotation;
    }
}
