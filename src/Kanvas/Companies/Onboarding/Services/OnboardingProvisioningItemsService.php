<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;

/**
 * What a provisioning run works through, in order: the setup agent first, because it is the one
 * that drives the rest, then every item of every selected area in catalog order. The setup agent is
 * not a catalog item, so it is declared here rather than in a section.
 */
final class OnboardingProvisioningItemsService
{
    public const string SETUP_AGENT_SLUG = 'ginny';

    public function __construct(
        private readonly OnboardingSectionsService $sections = new OnboardingSectionsService(),
        private readonly OnboardingAgentTemplateService $templates = new OnboardingAgentTemplateService(),
    ) {
    }

    /**
     * @return list<array<string, mixed>> SectionConfig items, each with `slug` and `kind`
     */
    public function forRow(CompanyOnboarding $row): array
    {
        $app = $row->app;
        $items = [$this->templates->setupAgentItem($app)];

        $selected = array_column((array) $row->dataGet('areas_selected', []), 'slug');
        foreach ($this->sections->forApp($app) as $section) {
            if (! in_array($section->slug, $selected, true)) {
                continue;
            }
            foreach ($section->config()->items() as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
