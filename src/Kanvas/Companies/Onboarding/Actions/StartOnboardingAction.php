<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Actions;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Enums\OnboardingSettingEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Self-serve app creation: the person's own app with its owner company, created from the platform
 * app with a user token. createApp stays app-key only; this wraps the same action and adds the
 * guards self-serve needs.
 */
final class StartOnboardingAction
{
    use KanvasJobsTrait;

    private const int LOCK_SECONDS = 60;

    public function __construct(
        private readonly Apps $platformApp,
        private readonly Users $user,
        private readonly string $appName,
        private readonly ?string $companyName = null,
    ) {
    }

    /**
     * @return array{app: Apps, app_key: string, company: Companies, onboarding: CompanyOnboarding}
     */
    public function execute(): array
    {
        if (! $this->platformApp->getBool(OnboardingSettingEnum::PLATFORM->value)) {
            throw new ValidationException('Onboarding can only be started from the platform app');
        }

        if (! (bool) $this->user->getAppProfile($this->platformApp)->is_verified) {
            throw new ValidationException('Verify your email before starting onboarding');
        }

        $lock = Cache::lock('onboarding:start:' . $this->user->getId(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new ValidationException('Onboarding is already starting for this user');
        }

        try {
            return $this->existingWorkspace() ?? $this->createWorkspace();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{app: Apps, app_key: string, company: Companies, onboarding: CompanyOnboarding}
     */
    private function createWorkspace(): array
    {
        $app = new CreateAppsAction(
            AppInput::from([
                'name' => $this->appName,
                'description' => $this->appName,
                'domain' => $this->uniqueDomain(),
                'is_actived' => 1,
                'ecosystem_auth' => 0,
                'payments_active' => 0,
                'is_public' => 0,
                'domain_based' => 0,
            ]),
            $this->user,
        )->execute();

        // CreateAppsAction assigns Owner in the caller's Bouncer scope, which is the platform app's;
        // the person must own the app they just created.
        Bouncer::scope()->onceTo(
            RolesEnums::getScope($app),
            fn () => $this->user->assign(RolesEnums::OWNER->value),
        );

        $company = Companies::getById((int) $this->user->default_company);
        if ($this->companyName !== null && trim($this->companyName) !== '') {
            $company->name = trim($this->companyName);
            $company->saveOrFail();
        }

        // The marker travels with the create: a crash between the two would leave an app no retry can find.
        $onboarding = $this->inApp($app, fn (): CompanyOnboarding => new OnboardingStateService()->forCompany(
            $app,
            $company,
            $this->user,
            ['platform_apps_id' => $this->platformApp->getId()],
        ));

        return $this->workspace($app, $company, $onboarding);
    }

    /**
     * Two companies named alike must not share a domain: apps.domain is only indexed, not unique.
     */
    private function uniqueDomain(): string
    {
        $base = Str::slug($this->appName) ?: 'workspace';
        $domain = $base . '.kanvas.app';
        while (Apps::query()->where('domain', $domain)->exists()) {
            $domain = $base . '-' . substr(uniqid(), -4) . '.kanvas.app';
        }

        return $domain;
    }

    /**
     * @return array{app: Apps, app_key: string, company: Companies, onboarding: CompanyOnboarding}|null
     */
    private function existingWorkspace(): ?array
    {
        $row = CompanyOnboarding::query()
            ->where('users_id', $this->user->getId())
            ->where('data->platform_apps_id', $this->platformApp->getId())
            ->notDeleted()
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->workspace(
            $row->app,
            $row->company,
            $row,
        );
    }

    /**
     * @return array{app: Apps, app_key: string, company: Companies, onboarding: CompanyOnboarding}
     */
    private function workspace(Apps $app, Companies $company, CompanyOnboarding $onboarding): array
    {
        return [
            'app' => $app,
            'app_key' => (string) $app->keys()->orderBy('created_at')->firstOrFail()->client_secret_id,
            'company' => $company,
            'onboarding' => $onboarding,
        ];
    }

    /**
     * The row and its ledger event belong to the new app; bind it for the write and put the platform
     * app back, whatever happens.
     */
    private function inApp(Apps $app, callable $work): mixed
    {
        $this->overwriteAppService($app);

        try {
            return $work();
        } finally {
            $this->overwriteAppService($this->platformApp);
        }
    }
}
