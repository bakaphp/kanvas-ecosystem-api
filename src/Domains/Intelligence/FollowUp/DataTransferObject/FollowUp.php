<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Spatie\LaravelData\Data;

/**
 * @deprecated v1 follow-up engine reads stage config via FollowUpConfig DTO
 *             — see docs/intelligence/follow-up-deprecation-spec.md kill list.
 */
class FollowUp extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Pipeline $pipeline,
        public readonly string $name,
        public readonly FollowUpTypeEnum $follow_up_type,
        public readonly ?array $config = null,
    ) {
    }
}
