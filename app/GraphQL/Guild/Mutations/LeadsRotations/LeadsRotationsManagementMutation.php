<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\LeadsRotations;

use Kanvas\Guild\LeadsRotations\Actions\CreateRotationAction;
use Kanvas\Guild\LeadsRotations\Actions\UpdateRotationAction;
use Kanvas\Guild\LeadsRotations\DataTransferObject\LeadRotation as RotationDto;
use Kanvas\Guild\LeadsRotations\Models\LeadRotation;

class LeadsRotationsManagementMutation
{
    public function create(mixed $root, array $req): LeadRotation
    {
        $input = $req['input'];
        $rotationDto = RotationDto::from([
            'company' => auth()->user()->getCurrentCompany(),
            'user' => auth()->user(),
            'name' => $input['name'],
            'users' => key_exists('users', $input['users']) ? $input['users'] : [],
        ]);

        return (new CreateRotationAction($rotationDto))->execute();
    }

    public function update(mixed $root, array $req): LeadRotation
    {
        $input = $req['input'];
        $rotationDto = RotationDto::from([
            'id' => $input['id'],
            'company' => auth()->user()->getCurrentCompany(),
            'user' => auth()->user(),
            'name' => $input['name'],
            'users' => key_exists('users', $input) ? $input['users'] : [],
        ]);

        return (new UpdateRotationAction($rotationDto))->execute();
    }

    public function delete(mixed $root, array $req): LeadRotation
    {
        $rotation = LeadRotation::where('id', $req['id'])
                    ->where('users_id', auth()->user()->getCurrentCompany())
                    ->firstOrFail();
        $rotation->delete();

        return $rotation;
    }
}
