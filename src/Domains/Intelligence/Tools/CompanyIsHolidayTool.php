<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Kanvas\Companies\Services\CompanyHolidayService;
use Override;

class CompanyIsHolidayTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        return new CompanyHolidayService($this->entity->company)->check();
    }
}
