<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;

final class SelectOnboardingAreasAction
{
    /**
     * @param list<string> $slugs
     */
    public function __construct(
        private readonly CompanyOnboarding $row,
        private readonly Users $user,
        private readonly array $slugs,
        private readonly ?string $firstArea = null,
    ) {
    }

    public function execute(): CompanyOnboarding
    {
        $app = $this->row->app;
        $sections = new OnboardingSectionsService()->forApp($app)->keyBy('slug');
        $slugs = array_values(array_unique(array_map('strval', $this->slugs)));
        if ($slugs === []) {
            throw new ValidationException('Pick at least one area');
        }

        foreach ($slugs as $slug) {
            if (! $sections->has($slug)) {
                throw new ValidationException("Unknown onboarding area \"{$slug}\"");
            }
        }

        if ($this->firstArea !== null && ! in_array($this->firstArea, $slugs, true)) {
            throw new ValidationException('first_area must be one of the selected areas');
        }

        $now = Carbon::now()->toIso8601String();
        $selected = [];
        $soon = [];
        foreach ($slugs as $slug) {
            /** @var OnboardingSection $section */
            $section = $sections[$slug];
            $selected[] = [
                'slug' => $slug,
                'label' => $section->label,
                'available' => (bool) $section->is_available,
                'picked_at' => $now,
                'picked_by' => $this->user->getId(),
            ];
            if (! $section->is_available) {
                $soon[] = $slug;
            }
        }

        $this->row->dataSet('areas_shown', $sections->keys()->all());
        $this->row->dataSet('areas_selected', $selected);
        $this->row->dataSet('first_area', $this->firstArea ?? ($slugs[0] ?? null));

        if ($this->needsAreasSelectedStatus($sections, $slugs)) {
            $this->row->transitionTo(OnboardingStatusEnum::AREAS_SELECTED);
        }

        $this->row->emitLedgerEvent(
            'onboarding.areas.selected',
            payload: [
                'selected' => $slugs,
                'shown' => $sections->keys()->all(),
                'first_area' => $this->row->dataGet('first_area'),
                'soon' => $soon,
            ],
            actorType: 'User',
            actorId: $this->user->getId(),
        );

        return $this->row->refresh();
    }

    /**
     * Before provisioning every selection moves the flow forward. After it, only a pick that
     * brings an item nobody provisioned yet reopens that step; otherwise the status stays.
     *
     * @param Collection<string, OnboardingSection> $sections
     * @param list<string> $slugs
     */
    private function needsAreasSelectedStatus(Collection $sections, array $slugs): bool
    {
        $status = $this->row->statusEnum();
        if (in_array($status, [OnboardingStatusEnum::STARTED, OnboardingStatusEnum::PROFILED, OnboardingStatusEnum::AREAS_SELECTED], true)) {
            return true;
        }
        if ($status->isTerminal()) {
            return false;
        }

        $provisioned = (array) $this->row->dataGet('provisioning', []);
        foreach ($slugs as $slug) {
            foreach ($sections[$slug]->config()->items() as $item) {
                if (! isset($provisioned[$item['slug']])) {
                    return true;
                }
            }
        }

        return false;
    }
}
