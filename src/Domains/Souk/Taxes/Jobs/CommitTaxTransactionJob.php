<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;
use Throwable;

class CommitTaxTransactionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 60, 300, 900, 1800];

    public function __construct(
        public readonly OrderTaxSnapshot $snapshot,
        public readonly Apps $app,
    ) {
    }

    public function uniqueId(): string
    {
        return 'commit-tax-' . $this->snapshot->uuid;
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $lock = Cache::lock('tax-snapshot:' . $this->snapshot->uuid, 120);
        $lock->block(10);

        $snapshot = OrderTaxSnapshot::query()->find($this->snapshot->id);

        try {
            if (in_array($snapshot->status, [
                TaxStatusEnum::COMMITTED->value,
                TaxStatusEnum::VOIDED->value,
                TaxStatusEnum::REVERSED->value,
            ])) {
                return;
            }

            if ($snapshot->status !== TaxStatusEnum::COMMITTING->value) {
                return;
            }

            $provider = new TaxProviderResolver($this->app)->resolveFromSnapshot($snapshot);
            $provider->commit($snapshot);

            $snapshot->transition(TaxStatusEnum::COMMITTING->value, TaxStatusEnum::COMMITTED->value);
        } catch (Throwable $e) {
            report($e);
            $snapshot->status = TaxStatusEnum::NEEDS_RECONCILE->value;
            $snapshot->saveOrFail();
        } finally {
            $lock->release();
        }
    }

    public function queue(): string
    {
        return 'souk-tax';
    }
}
