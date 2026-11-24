<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * What the `CompanyOnboarding` GraphQL type renders. Lighthouse reads the public properties; the
 * nested arrays carry the keys the schema names. Enum-typed fields hold the GraphQL case name.
 *
 * @param array<string, mixed> $profile
 * @param array<string, mixed> $research
 * @param list<array<string, mixed>> $areas
 * @param list<array<string, mixed>> $items
 * @param list<array<string, mixed>> $requirements
 */
final readonly class OnboardingState
{
    public function __construct(
        public CompanyOnboarding $row,
        public int $id,
        public string $uuid,
        public string $status,
        public ?string $current_step,
        public Companies $company,
        public array $profile,
        public array $research,
        public array $areas,
        public ?string $first_area,
        public array $items,
        public array $requirements,
        public ?Plan $setup_plan,
        public ?string $setup_session_id,
        public int $knowledge_count,
        public int $observations_count,
        public Carbon $started_at,
        public ?Carbon $completed_at,
    ) {
    }
}
