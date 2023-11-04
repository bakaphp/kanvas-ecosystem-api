<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\Actions;

use Kanvas\Social\Reactions\DataTransferObject\UserReaction as ReactionDto;
use Kanvas\Social\Reactions\Models\UserReaction;

class ReactToEntityAction
{
    public function __construct(
        public ReactionDto $reactionDto
    ) {
    }

    public function execute(): bool
    {
        $userReaction = UserReaction::where('users_id', $this->reactionDto->users->id)
            ->where('reactions_id', $this->reactionDto->reactions->id)
            ->where('entity_id', $this->reactionDto->entity_id)
            ->where('entity_namespace', $this->reactionDto->system_modules->model_name)
            ->first();
        if ($userReaction) {
            $userReaction->delete();

            return false;
        }
        $userReaction = UserReaction::create([
            'users_id' => $this->reactionDto->users->id,
            'reactions_id' => $this->reactionDto->reactions->id,
            'entity_id' => $this->reactionDto->entity_id,
            'entity_namespace' => $this->reactionDto->system_modules->model_name,
        ]);

        return (bool) $userReaction->id;
    }
}
