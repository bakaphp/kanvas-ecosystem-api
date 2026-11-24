<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Kanvas\Companies\Onboarding\Contracts\OnboardingSetupHookInterface;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Scribe\Setup\Onboarding\AccountingOnboardingSetupHook;
use Throwable;

/**
 * `config.setup` keys a section may declare. A fixed map in code, never a class name from the row.
 */
final class OnboardingSetupHookRegistry
{
    /** @var array<string, class-string<OnboardingSetupHookInterface>> */
    private const array HOOKS = [
        'accounting' => AccountingOnboardingSetupHook::class,
    ];

    public static function has(string $setup): bool
    {
        return isset(self::HOOKS[$setup]);
    }

    public static function resolve(string $setup): ?OnboardingSetupHookInterface
    {
        $class = self::HOOKS[$setup] ?? null;

        return $class !== null ? new $class() : null;
    }

    /**
     * Every setup the row's selected areas declare closes the tasks it can on the setup plan. Best-effort:
     * a failed sync leaves tasks open, it never fails completing onboarding.
     */
    public static function syncSetupPlan(CompanyOnboarding $row): void
    {
        $selected = array_column((array) $row->dataGet('areas_selected', []), 'slug');
        $setups = [];
        foreach (new OnboardingSectionsService()->forApp($row->app) as $section) {
            $setup = $section->config()->setup;
            if ($setup !== null && in_array($section->slug, $selected, true)) {
                $setups[$setup] = true;
            }
        }

        foreach (array_keys($setups) as $setup) {
            try {
                self::resolve($setup)?->syncSetupPlan($row);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
