<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\UpdateLeadReceiverAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadReceiver as LeadReceiverModel;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Rotations\Models\Rotation;
use Kanvas\Guild\Leads\Models\LeadRotation;

class LeadReceiverManagement
{
    public function create(mixed $root, array $request): LeadReceiverModel
    {
        $dto = LeadReceiver::from([
            'branch' => auth()->user()->getCurrentBranch(),
            'app' => app(Apps::class),
            'name' => $request['input']['name'],
            'user' => auth()->user(),
            'agent' => Users::getById($request['input']['agents_id'], app(Apps::class)),
            'isDefault' => $request['input']['is_default'],
            'rotation' => LeadRotation::getById($request['input']['rotations_id']),
            'source' => $request['input']['source_name']
        ]);
        return (new CreateLeadReceiverAction($dto))->execute();
    }

    public function update(mixed $root, array $request): LeadReceiverModel
    {
        $dto = LeadReceiver::from([
            'branch' => auth()->user()->getCurrentBranch(),
            'app' => app(Apps::class),
            'name' => $request['input']['name'],
            'user' => auth()->user(),
            'agent' => Users::getById($request['input']['agents_id'], app(Apps::class)),
            'isDefault' => $request['input']['is_default'],
            'rotation' => LeadRotation::getById($request['input']['rotations_id']),
            'source' => $request['input']['source_name']
        ]);
        return (new UpdateLeadReceiverAction((int)$request['id'], $dto))->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $leadReceiver = LeadReceiverModel::getById($request['id'], app(Apps::class));
        return $leadReceiver->delete();
    }
}
