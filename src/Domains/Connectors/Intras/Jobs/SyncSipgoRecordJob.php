<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\SyncSipgoRecordAction;
use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Users\Models\Users;

/**
 * An on-demand refresh too large to run inside an agent turn. The plan is rebuilt here rather than
 * carried over: SIPGO may have moved while the job waited, and the pull is idempotent either way.
 */
final class SyncSipgoRecordJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
        public readonly string $kind,
        public readonly int $legacyId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new SyncSipgoRecordAction(
            $this->app,
            $this->company,
            $this->user,
            new SipgoReference(SipgoRecordKindEnum::from($this->kind), $this->legacyId),
        )->execute();
    }
}
