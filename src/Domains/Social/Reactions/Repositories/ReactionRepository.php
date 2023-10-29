<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\Repositories;

use Kanvas\Social\Reactions\Models\Reaction as ReactionModel;

class ReactionRepository
{
    public function getByNameOrIcon(string $icon): ?ReactionModel
    {
        return ReactionModel::where('icon', $icon)
                            ->orWhere('name', $icon)
                            ->firstOrFail();
    }
}
