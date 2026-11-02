<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\DataTransferObject\OnboardingState;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Enums\ProvisionStatusEnum;
use Kanvas\Companies\Onboarding\Enums\ResearchStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;

final class OnboardingStateService
{
    public function __construct(
        private readonly OnboardingSectionsService $sections = new OnboardingSectionsService(),
        private readonly OnboardingRequirementsService $requirements = new OnboardingRequirementsService(),
    ) {
    }

    /**
     * @param array<string, mixed> $data written with the row when it is created, never merged into an existing one
     */
    public function forCompany(
        Apps $app,
        Companies $company,
        Users $user,
        array $data = [],
    ): CompanyOnboarding {
        $row = CompanyOnboarding::forCompany($app, $company);
        if ($row !== null) {
            return $row;
        }

        $row = CompanyOnboarding::query()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'status' => OnboardingStatusEnum::STARTED->value,
            'current_step' => null,
            'data' => $data,
            'started_at' => Carbon::now(),
        ]);
        $row->emitLedgerEvent('onboarding.started', actorType: 'User', actorId: $user->getId());

        return $row;
    }

    public function state(CompanyOnboarding $row): OnboardingState
    {
        $app = $row->app;
        $company = $row->company;
        $sections = $this->sections->forApp($app);
        $selected = array_column((array) $row->dataGet('areas_selected', []), 'slug');
        $items = $this->items($sections, $selected, $row);
        $requirements = $this->requirements->forItems($row, $items);

        foreach ($items as &$item) {
            $own = [];
            foreach ((array) $item['template']['requirements'] as $slug) {
                if (isset($requirements[$slug])) {
                    $own[] = $requirements[$slug];
                }
            }
            $item['requirements'] = $own;
            $item['ready'] = $item['status'] === strtoupper(ProvisionStatusEnum::READY->value)
                && array_all($own, fn (array $requirement): bool => ! $requirement['required'] || $requirement['status'] !== 'MISSING');
        }
        unset($item);

        $research = (array) $row->dataGet('research', []);
        $planId = (int) $row->dataGet('setup_plan_id', 0);

        return new OnboardingState(
            row: $row,
            id: (int) $row->getKey(),
            uuid: (string) $row->uuid,
            status: strtoupper($row->status),
            current_step: $row->current_step,
            company: $company,
            profile: [
                'name' => (string) $company->name,
                'website' => $company->website ?: null,
                'offering' => $row->dataGet('profile.offering'),
                'job_role' => $row->dataGet('profile.job_role'),
                'industry' => $company->get('company_profile')['industry'] ?? null,
                'summary' => $company->get('company_profile')['summary'] ?? null,
                'facts' => $company->get('company_profile'),
            ],
            research: [
                'status' => strtoupper((string) ($research['status'] ?? ResearchStatusEnum::NOT_STARTED->value)),
                'website' => $research['website'] ?? null,
                'pages_found' => (int) ($research['pages_found'] ?? 0),
                'pages_indexed' => (int) ($research['pages_indexed'] ?? 0),
                'started_at' => $research['started_at'] ?? null,
                'finished_at' => $research['finished_at'] ?? null,
                'error' => $research['error'] ?? null,
            ],
            areas: $sections->map(fn (OnboardingSection $section): array => $this->area($section, $selected))->all(),
            first_area: $row->dataGet('first_area'),
            items: $items,
            requirements: array_values($requirements),
            setup_plan: $planId > 0 ? Plan::query()->whereKey($planId)->first() : null,
            setup_session_id: $row->dataGet('setup_session_id'),
            knowledge_count: (int) ($research['knowledge_count'] ?? 0),
            observations_count: Event::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->where('source_domain', 'Onboarding')
                ->count(),
            started_at: $row->started_at,
            completed_at: $row->completed_at,
        );
    }

    /**
     * @param list<string> $selected
     * @return array<string, mixed>
     */
    private function area(OnboardingSection $section, array $selected): array
    {
        $config = $section->config();

        return [
            'slug' => $section->slug,
            'label' => $section->label,
            'title' => $section->title,
            'description' => (string) $section->description,
            'icon' => $section->icon,
            'available' => (bool) $section->is_available,
            'selected' => in_array($section->slug, $selected, true),
            'capabilities' => $config->capabilities,
            'setup_steps' => $config->setupSteps,
            'items' => array_map(fn (array $item): array => $this->template($item), $config->items()),
        ];
    }

    /**
     * @param Collection<int, OnboardingSection> $sections
     * @param list<string> $selected
     * @return list<array<string, mixed>>
     */
    private function items(Collection $sections, array $selected, CompanyOnboarding $row): array
    {
        $provisioning = (array) $row->dataGet('provisioning', []);
        $items = [];

        foreach ($sections as $section) {
            if (! in_array($section->slug, $selected, true)) {
                continue;
            }
            foreach ($section->config()->items() as $item) {
                $slug = (string) $item['slug'];
                $state = (array) ($provisioning[$slug] ?? []);
                $status = ProvisionStatusEnum::tryFrom((string) ($state['status'] ?? '')) ?? ProvisionStatusEnum::WAITING;
                $agentId = (int) ($state['agent_id'] ?? 0);

                $items[] = [
                    'template' => $this->template($item),
                    'area' => $section->slug,
                    'agent' => $agentId > 0 ? Agent::query()->whereKey($agentId)->first() : null,
                    'status' => strtoupper($status->value),
                    'ready' => false,
                    'requirements' => [],
                    'error' => $state['error'] ?? null,
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function template(array $item): array
    {
        return [
            'slug' => (string) $item['slug'],
            'kind' => (string) $item['kind'],
            'name' => (string) ($item['name'] ?? $item['slug']),
            'description' => (string) ($item['description'] ?? ''),
            'requirements' => array_values(array_map('strval', (array) ($item['requirements'] ?? []))),
        ];
    }
}
