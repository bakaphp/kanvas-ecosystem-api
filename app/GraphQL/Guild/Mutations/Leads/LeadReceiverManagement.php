<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\Actions\UpdateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadReceiver as LeadReceiverModel;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Users\Models\Users;

class LeadReceiverManagement
{
    public function create(mixed $root, array $request): LeadReceiverModel
    {
        $rotation = key_exists('rotations_id', $request['input']) ? LeadRotation::getById($request['input']['rotations_id']) : null;
        $dto = LeadReceiver::from([
            'branch' => auth()->user()->getCurrentBranch(),
            'app' => app(Apps::class),
            'name' => $request['input']['name'],
            'user' => auth()->user(),
            'agent' => Users::getById($request['input']['agents_id'], app(Apps::class)),
            'isDefault' => $request['input']['is_default'],
            'rotation' => $rotation,
            'source' => $request['input']['source_name'],
            'lead_sources_id' => $request['input']['lead_sources_id'],
            'lead_types_id' => $request['input']['lead_types_id'],
            'template' => key_exists('template', $request['input']) ? $request['input']['template'] : '',
        ]);

        return (new CreateLeadReceiverAction($dto))->execute();
    }

    public function update(mixed $root, array $request): LeadReceiverModel
    {
        $rotation = key_exists('rotations_id', $request['input']) ? LeadRotation::getById($request['input']['rotations_id']) : null;
        $dto = LeadReceiver::from([
            'branch' => auth()->user()->getCurrentBranch(),
            'app' => app(Apps::class),
            'name' => $request['input']['name'],
            'user' => auth()->user(),
            'agent' => Users::getById($request['input']['agents_id'], app(Apps::class)),
            'isDefault' => $request['input']['is_default'],
            'rotation' => $rotation,
            'source' => $request['input']['source_name'],
            'lead_sources_id' => $request['input']['lead_sources_id'],
            'lead_types_id' => $request['input']['lead_types_id'],
            'template' => key_exists('template', $request['input']) ? $request['input']['template'] : null,
        ]);
        $leadReceiver = LeadReceiverModel::getById($request['id'], app(Apps::class));

        return (new UpdateLeadReceiverAction($leadReceiver, $dto))->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $leadReceiver = LeadReceiverModel::getById($request['id'], app(Apps::class));

        return $leadReceiver->delete();
    }
}
