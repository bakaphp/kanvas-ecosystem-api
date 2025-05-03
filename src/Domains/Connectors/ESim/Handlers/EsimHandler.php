<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Esim\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;

class EsimHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $internalKey = $this->data['internal_key'] ?? null;

        return $internalKey !== null && $internalKey !== '';
    }
}
