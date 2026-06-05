<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\RespondIO\Traits\HasContactProcessing;
use Kanvas\Users\Models\Users;

abstract class BaseRespondIOAction
{
    use HasContactProcessing;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected array $payload,
    ) {
    }

    abstract public function execute(): array;
}
