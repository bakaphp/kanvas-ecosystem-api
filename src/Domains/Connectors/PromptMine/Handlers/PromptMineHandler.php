<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;

class PromptMineHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $promptMineKey = $this->data['prompt_mine_key'] ?? null;

        return $promptMineKey !== null && $promptMineKey !== '';
    }
}
