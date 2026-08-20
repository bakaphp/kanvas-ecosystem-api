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
use Kanvas\Workflow\Rules\Models\Rule;
use Spatie\LaravelData\Data;

class EntityIntegrationHistory extends Data
{
    /**
     * `integrationCompany` is null only for a misconfigured tenant — no default region, or no
     * integration wired for it. `company` / `integration` carry what that row would have supplied,
     * so the failure still lands in the history instead of vanishing.
     */
    public function __construct(
        public AppInterface $app,
        public ?IntegrationsCompany $integrationCompany,
        public Status $status,
        public EntityIntegrationInterface|Model $entity,
        public ?Rule $rule,
        public mixed $response = null,
        public mixed $exception = null,
        public ?int $workflowId = null,
        public ?CompanyInterface $company = null,
        public ?Integrations $integration = null
    ) {
    }
}
