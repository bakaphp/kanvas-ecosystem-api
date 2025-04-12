<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;

class IntellicheckHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $intellicheckId = $this->data['intellicheck_id'] ?? null;

        return $intellicheckId !== null && $intellicheckId !== '';
    }
}
