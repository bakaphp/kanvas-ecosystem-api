<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\DailyLearning\Services\CycleWindowResolverService;

trait ResolvesReviewWindow
{
    protected function resolveReviewWindow(?string $since, ?string $until, int $maxRangeDays): array
    {
        try {
            $window = CycleWindowResolverService::resolveRange(
                $this->app,
                $this->company,
                $since,
                $until,
            );
        } catch (ValidationException $e) {
            return $this->invalidArgs($e->getMessage());
        }

        if ($window['since']->gt($window['until'])) {
            return $this->invalidArgs('until is before since.');
        }

        $spanDays = (int) $window['since']->diffInDays($window['until']) + 1;

        if ($spanDays > $maxRangeDays) {
            return $this->invalidArgs("The range spans {$spanDays} days; it cannot exceed {$maxRangeDays} days.");
        }

        return $window;
    }

    protected function resolveReviewLimit(?int $limit, int $default, int $max): int
    {
        return max(1, min($limit ?? $default, $max));
    }
}
