<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Actions\ProvisionOnboardingItemAction;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Enums\ProvisionStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingProvisioningItemsService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Provisions the selected areas one item at a time, writing each result to the row as it lands so
 * the screen advances card by card. Failures are per item: the run always ends in TEAM_READY, with
 * the failed cards saying why.
 */
final class ProvisionOnboardingItemsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'onboarding';

    private const int LOCK_SECONDS = 600;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $row = CompanyOnboarding::forCompany($this->app, $this->company);
        if ($row === null) {
            return;
        }

        // Replicated workers: a second run for the same row while one is active would clone twice.
        $lock = Cache::lock(self::lockKey($row), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->provision($row);
        } finally {
            $lock->release();
        }
    }

    /**
     * The worker died mid-run: whatever was CREATING is marked failed with the reason, and the row
     * leaves PROVISIONING so the screen stops waiting.
     */
    public function failed(Throwable $exception): void
    {
        $this->overwriteAppService($this->app);

        $row = CompanyOnboarding::forCompany($this->app, $this->company);
        if ($row === null) {
            return;
        }

        foreach ((array) $row->dataGet('provisioning', []) as $slug => $state) {
            if (($state['status'] ?? null) === ProvisionStatusEnum::CREATING->value) {
                $row->dataSet("provisioning.{$slug}", [
                    'status' => ProvisionStatusEnum::FAILED->value,
                    'agent_id' => null,
                    'error' => 'Provisioning stopped: ' . $exception->getMessage(),
                ]);
            }
        }

        $row->advanceTo(OnboardingStatusEnum::TEAM_READY);
    }

    public static function lockKey(CompanyOnboarding $row): string
    {
        return 'onboarding:provision:' . $row->getKey();
    }

    private function provision(CompanyOnboarding $row): void
    {
        $ready = 0;
        $failed = 0;
        foreach (new OnboardingProvisioningItemsService()->forRow($row) as $item) {
            $slug = (string) $item['slug'];
            if ($row->dataGet("provisioning.{$slug}.status") === ProvisionStatusEnum::READY->value) {
                $ready++;

                continue;
            }

            $row->dataSet("provisioning.{$slug}.status", ProvisionStatusEnum::CREATING->value);
            $result = new ProvisionOnboardingItemAction(
                $this->app,
                $this->company,
                $this->user,
                $item,
            )->execute();
            $row->dataSet("provisioning.{$slug}", $result);

            $result['status'] === ProvisionStatusEnum::READY->value ? $ready++ : $failed++;
        }

        $row->advanceTo(OnboardingStatusEnum::TEAM_READY);

        $row->emitLedgerEvent(
            'onboarding.provisioned',
            payload: ['ready' => $ready, 'failed' => $failed],
            actorType: 'User',
            actorId: $this->user->getId(),
        );
    }
}
