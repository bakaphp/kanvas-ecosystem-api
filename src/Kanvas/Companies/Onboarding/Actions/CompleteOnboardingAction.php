<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Concerns\FlipsWelcomeFlag;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingAgentTemplateService;
use Kanvas\Companies\Onboarding\Services\OnboardingPromptBlockService;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Jobs\WakeAgentInSessionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Services\UserAgentChannelService;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;

/**
 * Landing: the setup checklist for the first area as a plan on Ginny, her standing DM with the
 * person, and the opening turn queued so the mutation never waits on a model.
 */
final class CompleteOnboardingAction
{
    use FlipsWelcomeFlag;

    public const string PLAN_TYPE = 'onboarding';

    private const int LOCK_SECONDS = 60;

    public function __construct(
        private readonly CompanyOnboarding $row,
        private readonly Users $user,
    ) {
    }

    public function execute(): CompanyOnboarding
    {
        $lock = Cache::lock('onboarding:complete:' . $this->row->getKey(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new ValidationException('Onboarding completion is already in progress');
        }

        try {
            return $this->complete();
        } finally {
            $lock->release();
        }
    }

    private function complete(): CompanyOnboarding
    {
        $this->row->refresh();

        $allowed = [OnboardingStatusEnum::AREAS_SELECTED, OnboardingStatusEnum::PROVISIONING, OnboardingStatusEnum::TEAM_READY];
        if (! in_array($this->row->statusEnum(), $allowed, true)) {
            throw new ValidationException(sprintf(
                'Onboarding cannot be completed from status "%s"; pick the areas first',
                $this->row->status,
            ));
        }

        $app = $this->row->app;
        $company = $this->row->company;
        $section = $this->firstSection($app);
        $ginny = $this->ginny($app, $company);

        $plan = $this->existingPlan($app, $company) ?? $this->createPlan(
            $app,
            $company,
            $section,
            $ginny,
        );
        $session = new UserAgentChannelService()->resolveSession(
            human: $this->user,
            agent: $ginny,
            app: $app,
            company: $company,
            entity: $this->user,
        );

        $this->row->dataSet('setup_plan_id', $plan->getId());
        $this->row->dataSet('setup_session_id', (string) $session->uuid);
        $this->row->completed_at = Carbon::now();
        $this->row->transitionTo(OnboardingStatusEnum::COMPLETED);

        $this->flipWelcome($this->user, $app);
        OnboardingPromptBlockService::forget($app, $company);

        $this->row->emitLedgerEvent(
            'onboarding.completed',
            payload: ['plan_id' => $plan->getId(), 'session_uuid' => (string) $session->uuid, 'first_area' => $section->slug],
            actorType: 'User',
            actorId: $this->user->getId(),
        );

        WakeAgentInSessionJob::dispatch(
            $app,
            $ginny,
            $session,
            $this->openingInstruction($company, $section, $plan),
            $this->user,
            'onboarding-welcome',
        );

        return $this->row->refresh();
    }

    private function firstSection(Apps $app): OnboardingSection
    {
        $sections = new OnboardingSectionsService();
        $selected = array_column((array) $this->row->dataGet('areas_selected', []), 'slug');
        $first = (string) ($this->row->dataGet('first_area') ?? ($selected[0] ?? ''));

        return $sections->bySlug($app, $first)
            ?? throw new ValidationException('No onboarding area selected to set up');
    }

    /**
     * Ginny must exist even if provisioning never ran; the one-item provisioning path builds her
     * from the platform template exactly as the job would.
     */
    private function ginny(Apps $app, Companies $company): Agent
    {
        $templates = new OnboardingAgentTemplateService();
        $name = $templates->setupAgentName($app);

        $existing = DefaultAgentEnum::named($app, $company, $name);
        if ($existing !== null) {
            return $existing;
        }

        $result = new ProvisionOnboardingItemAction(
            $app,
            $company,
            $company->user ?? $this->user,
            ['slug' => 'ginny', 'kind' => 'agent', 'name' => $name, 'source_agent' => $name, 'requirements' => []],
        )->execute();

        $agent = ! empty($result['agent_id']) ? Agent::query()->whereKey((int) $result['agent_id'])->first() : null;

        return $agent ?? throw new ValidationException(
            (string) ($result['error'] ?? "Setup agent \"{$name}\" could not be provisioned"),
        );
    }

    /**
     * A retry after a failure past plan creation must land on the plan that already exists.
     */
    private function existingPlan(Apps $app, Companies $company): ?Plan
    {
        $planId = (int) $this->row->dataGet('setup_plan_id', 0);
        if ($planId <= 0) {
            return null;
        }

        return Plan::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->whereKey($planId)
            ->first();
    }

    private function createPlan(
        Apps $app,
        Companies $company,
        OnboardingSection $section,
        Agent $ginny,
    ): Plan {
        $steps = $section->config()->setupSteps;
        if ($steps === []) {
            $steps = ['Finish setting up ' . $section->title];
        }

        $tasks = [];
        foreach ($steps as $index => $step) {
            $tasks[] = new TaskData(plan: null, title: $step, sequence: $index + 1);
        }

        return new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Set up ' . $section->title,
                planType: self::PLAN_TYPE,
                agent: $ginny,
                user: $this->user,
                description: $section->description,
                status: PlanStatusEnum::ACTIVE,
                input: ['onboarding_id' => $this->row->getId(), 'area' => $section->slug],
            ),
            $tasks,
        )->execute();
    }

    private function openingInstruction(Companies $company, OnboardingSection $section, Plan $plan): string
    {
        $profile = (array) ($company->get('company_profile') ?? []);
        $summary = trim((string) ($profile['summary'] ?? ''));
        $selected = array_column((array) $this->row->dataGet('areas_selected', []), 'label');

        return sprintf(
            'The company "%s" just finished onboarding.%s They picked: %s. Start with "%s" using the setup checklist (plan %d). '
            . 'Greet them by first name, say what you will set up together, and walk them through the checklist one task at a time, '
            . 'explaining each piece as you go. Mark a task done only when they confirm.',
            $company->name,
            $summary !== '' ? ' Profile: ' . $summary : '',
            $selected === [] ? 'nothing yet' : implode(', ', $selected),
            $section->title,
            $plan->getId(),
        );
    }
}
