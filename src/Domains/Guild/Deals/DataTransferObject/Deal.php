<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Deal extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?CompaniesBranches $branch = null,
        public readonly ?Lead $lead = null,
        public readonly ?People $people = null,
        public readonly ?Organization $organization = null,
        public readonly ?Users $owner = null,
        public readonly ?Pipeline $pipeline = null,
        public readonly ?PipelineStage $pipelineStage = null,
        public readonly ?int $statusId = null,
        public readonly ?int $status = null,
    ) {
    }
}
