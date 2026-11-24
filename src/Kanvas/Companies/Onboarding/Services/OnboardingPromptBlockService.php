<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * What Ginny knows about the company's onboarding while it is still in play: the picks, the first
 * area, the open slots and the setup checklist. Empty once onboarding is over and the setup plan is
 * closed, so a finished company pays nothing for it.
 */
final class OnboardingPromptBlockService
{
    private const int TTL_SECONDS = 60;

    public static function for(Apps $app, Companies $company): string
    {
        return (string) Cache::remember(
            self::cacheKey($app, $company),
            self::TTL_SECONDS,
            fn (): string => self::render($app, $company),
        );
    }

    public static function forget(Apps $app, Companies $company): void
    {
        Cache::forget(self::cacheKey($app, $company));
    }

    private static function render(Apps $app, Companies $company): string
    {
        $row = CompanyOnboarding::forCompany($app, $company);
        if ($row === null) {
            return '';
        }

        $plan = self::openSetupPlan($row);
        if ($row->isTerminal() && $plan === null) {
            return '';
        }

        $state = new OnboardingStateService()->state($row);
        $areas = collect($state->areas);
        $selected = $areas->where('selected', true)->pluck('title')->all();
        $first = $areas->firstWhere('slug', $state->first_area);
        $open = collect($state->requirements)
            ->filter(fn (array $requirement): bool => $requirement['status'] === 'MISSING')
            ->map(fn (array $requirement): string => sprintf('%s (%s)', $requirement['label'], $requirement['slug']))
            ->all();

        $lines = [
            '## Onboarding',
            'Status: ' . strtolower($state->status) . '.',
            'They picked: ' . ($selected === [] ? 'nothing yet' : implode(', ', $selected)) . '.',
        ];
        if ($first !== null) {
            $lines[] = 'Start with: ' . $first['title'] . '.';
        }
        if ($open !== []) {
            $lines[] = 'Still to connect: ' . implode('; ', $open) . '.';
        }
        if ($plan !== null) {
            $lines[] = sprintf('Setup checklist "%s" (plan %d):', $plan->title, $plan->getId());
            foreach ($plan->tasks()->orderBy('sequence')->get() as $task) {
                $lines[] = sprintf('- [%s] %s', $task->status, $task->title);
            }
        }
        $lines[] = 'Walk them through the checklist one step at a time, explaining each piece. Mark a setup task done only when the person confirms it is done.';

        return implode("\n", $lines);
    }

    private static function openSetupPlan(CompanyOnboarding $row): ?Plan
    {
        $planId = (int) $row->dataGet('setup_plan_id', 0);
        if ($planId <= 0) {
            return null;
        }

        $plan = Plan::query()->whereKey($planId)->first();
        if ($plan === null) {
            return null;
        }

        $closed = [PlanStatusEnum::DONE->value, PlanStatusEnum::CANCELLED->value, PlanStatusEnum::FAILED->value];

        return in_array($plan->status, $closed, true) ? null : $plan;
    }

    private static function cacheKey(Apps $app, Companies $company): string
    {
        return sprintf('onboarding:prompt-block:%d:%d', $app->getId(), $company->getId());
    }
}
