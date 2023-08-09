<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\Actions;

use Kanvas\Social\Reactions\DataTransferObject\Reaction as ReactionDto;
use Kanvas\Social\Reactions\Models\Reaction;

class CreateReactionAction
{
    public function __construct(
        protected ReactionDto $reactionDto
    ) {
    }

    public function execute(): Reaction
    {
        $reaction = Reaction::create([
            'apps_id' => $this->reactionDto->apps->getId(),
            'companies_id' => $this->reactionDto->companies->getId(),
            'name' => $this->reactionDto->name,
            'icon' => $this->reactionDto->icon,
        ]);

        return $reaction;
    }
}
