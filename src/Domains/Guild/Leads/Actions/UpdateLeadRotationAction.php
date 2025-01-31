<?php
declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\DataTransferObject\LeadRotation as LeadRotationDto;
use Kanvas\Users\Repositories\UsersRepository;

class UpdateLeadRotationAction
{

    public function __construct(
        public LeadRotation $leadRotation,
        public LeadRotationDto $leadRotationDto
    ) {
    }

    public function execute(): LeadRotation
    {
        $this->leadRotation->update([
            'companies_id' => $this->leadRotationDto->company->getId(),
            'apps_id' => $this->leadRotationDto->app->getId(),
            'name' => $this->leadRotationDto->name,
            'leads_rotations_email' => $this->leadRotationDto->leadsRotationsEmail,
            'hits' => $this->leadRotationDto->hits
        ]);
        if ($this->leadRotationDto->agents) {
            $users = UsersRepository::findUsersByArray($this->leadRotationDto->agents, $this->leadRotationDto->app);
            $usersIds = $users->pluck('id');
            $this->leadRotation->agents()->sync($usersIds);
        }

        return $this->leadRotation;
    }
}
