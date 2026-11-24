<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;

final class OnboardingSectionsService
{
    /**
     * Global rows with the app's own rows replacing them by slug. Null app means the globals alone,
     * which is what the seed check needs before any app exists.
     *
     * @return Collection<int, OnboardingSection>
     */
    public function forApp(?Apps $app): Collection
    {
        $rows = OnboardingSection::query()
            ->when(
                $app !== null,
                fn ($query) => $query->fromAppOrGlobal($app),
                fn ($query) => $query->where('apps_id', 0),
            )
            ->where('is_active', true)
            ->notDeleted()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $bySlug = [];
        foreach ($rows as $row) {
            $current = $bySlug[$row->slug] ?? null;
            if ($current === null || (int) $current->apps_id === 0) {
                $bySlug[$row->slug] = $row;
            }
        }

        return collect(array_values($bySlug))
            ->sortBy([fn (OnboardingSection $a, OnboardingSection $b): int => [$a->display_order, $a->id] <=> [$b->display_order, $b->id]])
            ->values();
    }

    public function bySlug(?Apps $app, string $slug): ?OnboardingSection
    {
        return $this->forApp($app)->first(fn (OnboardingSection $section): bool => $section->slug === $slug);
    }

    /**
     * Every requirement declared across the app's sections. An app's own declaration beats the
     * platform's for the same slug (the same override rule cards follow); among equals the first by
     * display order wins.
     *
     * @return array<string, array<string, mixed>>
     */
    public function requirementDefinitions(?Apps $app): array
    {
        $sections = $this->forApp($app)
            ->sortBy([fn (OnboardingSection $a, OnboardingSection $b): int => [(int) $a->apps_id === 0, $a->display_order, $a->id] <=> [(int) $b->apps_id === 0, $b->display_order, $b->id]])
            ->values();

        $definitions = [];
        foreach ($sections as $section) {
            foreach ($section->config()->requirements as $requirement) {
                $slug = (string) ($requirement['slug'] ?? '');
                if ($slug !== '' && ! isset($definitions[$slug])) {
                    $definitions[$slug] = $requirement;
                }
            }
        }

        return $definitions;
    }
}
