<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\DataTransferObject;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Rules\Models\Rule;
use Spatie\LaravelData\Data;

class EntityIntegrationHistory extends Data
{
    public function __construct(
        public AppInterface $app,
        public IntegrationsCompany $integrationCompany,
        public Status $status,
        public EntityIntegrationInterface|Model $entity,
        public ?Rule $rule,
        public mixed $response = null,
        public mixed $exception = null,
        public ?int $workflowId = null
    ) {
    }
}
