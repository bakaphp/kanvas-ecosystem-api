<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Enums;

enum OnboardingStatusEnum: string
{
    case STARTED = 'started';
    case PROFILED = 'profiled';
    case AREAS_SELECTED = 'areas_selected';
    case PROVISIONING = 'provisioning';
    case TEAM_READY = 'team_ready';
    case COMPLETED = 'completed';
    case SKIPPED = 'skipped';

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::SKIPPED;
    }
}
