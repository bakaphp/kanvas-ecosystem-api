<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Fixtures;

use Baka\Contracts\BaseIntegration;
use Override;

final class AccountSelectingHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        return ! ($this->data['fail'] ?? false);
    }

    #[Override]
    public function persistedConfig(): array
    {
        return ['accounts' => $this->data['accounts'] ?? []];
    }
}
