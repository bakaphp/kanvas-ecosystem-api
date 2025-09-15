<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class CompanyWorkHoursTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        return [
            'work_hours' => $this->entity->company->get(ConfigurationEnum::WORKING_HOURS->value) ?? null,
            'working_days' => $this->entity->company->get(ConfigurationEnum::WORKING_DAYS->value) ?? null,
        ];
    }
}
