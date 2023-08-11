<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Reactions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Reactions\Actions\CreateReactionAction;
use Kanvas\Social\Reactions\Actions\ReactToEntityAction;
use Kanvas\Social\Reactions\DataTransferObject\Reaction as ReactionDto;
use Kanvas\Social\Reactions\DataTransferObject\UserReaction as UserReactionDto;
use Kanvas\Social\Reactions\Models\Reaction;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class ReactionManagementMutation
{
    public function create(mixed $root, array $request): Reaction
    {
        $reactionDto = new ReactionDto(
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            $request['input']['name'],
            $request['input']['icon']
        );

        $action = new CreateReactionAction($reactionDto);
        $reaction = $action->execute();

        return $reaction;
    }

    public function update(mixed $root, array $request): Reaction
    {
        $reaction = Reaction::getById($request['id']);
        $reaction->update([
            'name' => $request['input']['name'],
            'icon' => $request['input']['icon'],
        ]);

        return $reaction;
    }

    public function delete(mixed $root, array $request): bool
    {
        $reaction = Reaction::getById($request['id']);
        $reaction->delete();

        return true;
    }

    public function reactToEntity(mixed $root, array $request): bool
    {
        $systemModule = SystemModulesRepository::getByUuidOrModelName($request['input']['system_modules_uuid']);
        $reaction = Reaction::getById($request['input']['reaction_id']);
        $userReactionDto = new UserReactionDto(
            auth()->user(),
            $reaction,
            $request['input']['entity_id'],
            $systemModule
        );
        $action = new ReactToEntityAction($userReactionDto);

        return $action->execute();
    }
}
