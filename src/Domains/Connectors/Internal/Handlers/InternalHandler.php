<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;

class InternalHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        return ! empty($response['internal_key']);
    }
}
