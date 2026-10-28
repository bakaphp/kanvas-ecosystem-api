<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Models\BaseModel;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;

/**
 * One row per company: where the person is in onboarding and everything the flow recorded
 * (picks, provisioning, requirements, research). Status and dates are columns so the funnel is
 * queryable; the rest is one JSON whose shape may change without a migration.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $status
 * @property string|null $current_step
 * @property array|null $data
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $skipped_at
 */
class CompanyOnboarding extends BaseModel
{
    use UuidTrait;
    use EmitsLedgerEventsForEntity;

    private const int LOCK_SECONDS = 10;

    protected $table = 'companies_onboarding';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => Json::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'skipped_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public static function forCompany(Apps $app, Companies $company): ?self
    {
        return static::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->first();
    }

    public function statusEnum(): OnboardingStatusEnum
    {
        return OnboardingStatusEnum::from($this->status);
    }

    public function isTerminal(): bool
    {
        return $this->statusEnum()->isTerminal();
    }

    public function transitionTo(OnboardingStatusEnum $status): void
    {
        $this->status = $status->value;
        $this->saveOrFail();
    }

    /**
     * A finished company adding an area later keeps its terminal status; only an onboarding still
     * in progress moves forward.
     */
    public function advanceTo(OnboardingStatusEnum $status): void
    {
        if (! $this->isTerminal()) {
            $this->transitionTo($status);
        }
    }

    public function dataGet(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->data ?? [], $path, $default);
    }

    /**
     * The provisioning job and the screen's mutations write different keys of the same JSON at the
     * same time; a plain read-modify-write would drop whichever landed second.
     */
    public function dataSet(string $path, mixed $value): void
    {
        Cache::lock("onboarding:data:{$this->getKey()}", self::LOCK_SECONDS)->block(5, function () use ($path, $value): void {
            // The row lock only holds inside a transaction; outside one it releases on the read.
            $data = $this->getConnection()->transaction(function () use ($path, $value): array {
                $fresh = static::query()->lockForUpdate()->findOrFail($this->getKey());
                $data = $fresh->data ?? [];
                Arr::set($data, $path, $value);
                $fresh->data = $data;
                $fresh->saveOrFail();

                return $data;
            });

            // Keep this instance clean: a later save() must write only what it changed, never
            // the whole JSON from a snapshot a concurrent writer has since moved past.
            $this->setRawAttributes(['data' => $this->castAttribute('data', $data)] + $this->getAttributes(), false);
            $this->data = $data;
            $this->syncOriginalAttribute('data');
        });
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Onboarding';
    }
}
