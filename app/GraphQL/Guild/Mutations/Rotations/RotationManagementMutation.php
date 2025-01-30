<?php
declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Rotations;

use Kanvas\Users\Models\Users;
use Kanvas\Guild\Rotations\DataTransferObject\Rotation as RotationDto;
use Kanvas\Guild\Rotations\Models\Rotation;
use Kanvas\Guild\Rotations\Actions\CreateRotationAction;
use Kanvas\Guild\Rotations\Actions\UpdateRotationAction;
use Kanvas\Apps\Models\Apps;

class RotationManagementMutation
{

    public function create(mixed $root, array $req): Rotation
    {
        $input = $req['input'];
        $rotationDto = RotationDto::from([
            'company' => auth()->user()->getCurrentCompany(),
            'user' => auth()->user(),
            'name' => $input['name'],
            'users' => $input['users']
        ]);
        return (new CreateRotationAction($rotationDto))->execute();
    }

    public function update(mixed $root, array $req): Rotation
    {
        $input = $req['input'];
        $rotationDto = RotationDto::from([
            'id' => $input['id'],
            'company' => auth()->user()->getCurrentCompany(),
            'user' => auth()->user(),
            'name' => $input['name'],
            'users' => $input['users']
        ]);
        return (new UpdateRotationAction($rotationDto))->execute();
    }

    public function delete(mixed $root, array $req): Rotation
    {
        $input = $req['input'];
        $rotation = Rotation::getById($input['id'], app(Apps::class));
        $rotation->delete();
        return $rotation;
    }
}
