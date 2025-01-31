<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\CreateLeadRotationAction;
use Kanvas\Guild\Leads\Actions\UpdateLeadRotationAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadRotation;
use Kanvas\Guild\Leads\Models\LeadRotation as LeadRotationModel;
use Kanvas\Apps\Models\Apps;

class LeadRotationManagementMutation
{
    public function create(mixed $root, array $request): LeadRotationModel
    {
        $dto = LeadRotation::from([
            'app' => app(Apps::class),
            'company' => auth()->user()->getCurrentCompany(),
            'name' => $request['input']['name'],
            'leads_rotations_email' => key_exists('leads_rotations_email', $request['input']) ? $request['input']['leads_rotations_email'] : null,
            'hits' => $request['input']['hits'],
            'agents' => key_exists('agents', $request['input']) ? $request['input']['agents'] : []
        ]);
        return (new CreateLeadRotationAction($dto))->execute();
    }

    public function update(mixed $root, array $request): LeadRotationModel
    {
        $dto = LeadRotation::from([
            'app' => app(Apps::class),
            'company' => auth()->user()->getCurrentCompany(),
            'name' => $request['input']['name'],
            'leads_rotations_email' => key_exists('leads_rotations_email', $request['input']) ? $request['input']['leads_rotations_email'] : null,
            'hits' => $request['input']['hits'],
            'agents' => key_exists('agents', $request['input']) ? $request['input']['agents'] : []
        ]);
        $leadRotation = LeadRotationModel::getById($request['id'], app(Apps::class));
        return (new UpdateLeadRotationAction($leadRotation, $dto))->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $leadRotation = LeadRotationModel::getById($request['id'], app(Apps::class));
        return $leadRotation->delete();
    }
}
