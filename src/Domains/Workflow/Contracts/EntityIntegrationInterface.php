<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

interface EntityIntegrationInterface
{
    public function getId(): mixed;

    public function integrationsHistory(): HasMany;
}
