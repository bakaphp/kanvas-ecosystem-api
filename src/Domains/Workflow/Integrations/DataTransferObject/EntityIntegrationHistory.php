<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Spatie\LaravelData\Data;

class EntityIntegrationHistory extends Data
{
    public function __construct(
        public AppInterface $app,
        public ?IntegrationsCompany $integrationCompany,
        public Status $status,
        public EntityIntegrationInterface|Model $entity,
        public ?Rule $rule,
        public mixed $input = null,
        public mixed $response = null,
        public mixed $exception = null,
        public ?int $workflowId = null,
        public ?Action $action = null,
        public ?Integrations $integration = null,
        public ?CompanyInterface $company = null,
    ) {
    }
}
