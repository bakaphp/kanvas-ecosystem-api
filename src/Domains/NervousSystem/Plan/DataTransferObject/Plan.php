<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan as PlanModel;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Plan extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $title,
        public readonly string $planType,
        public readonly ?Agent $agent = null,
        public readonly ?Users $user = null,
        public readonly ?PlanModel $parentPlan = null,
        public readonly ?string $entityNamespace = null,
        public readonly ?int $entityId = null,
        public readonly ?string $description = null,
        public readonly PlanStatusEnum $status = PlanStatusEnum::DRAFT,
        public readonly int $priority = 0,
        public readonly ?Carbon $deadlineAt = null,
        public readonly ?array $input = null,
        public readonly ?array $output = null,
        public readonly ?float $confidenceScore = null,
        public readonly bool $requiresHumanApproval = false,
    ) {
    }
}
