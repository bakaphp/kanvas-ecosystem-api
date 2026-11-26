<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Event\Passes\Services\PassMotiveService;
use Kanvas\Users\Models\Users;
use Throwable;

class GenerateEventInviteCodesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly Users $user,
        public readonly EventVersion $eventVersion,
        public readonly int $quantity,
        public readonly ?string $prefix = null,
        public readonly int $uses = 1,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $motive = PassMotiveService::getMotive(
            company: $this->eventVersion->company,
            app: $this->app,
            userId: $this->user->getId()
        );
        $lastDayEnd = EventVersion::query()
            ->daysOfEvent((int) $this->eventVersion->event_id)
            ->reorder()
            ->max('end_at');

        for ($issued = 0; $issued < $this->quantity; $issued++) {
            try {
                new CreatePassAction(
                    $this->eventVersion->event,
                    $this->eventVersion,
                    $motive,
                    expirationDate: $lastDayEnd === null ? null : Carbon::parse($lastDayEnd),
                    format: PassFormatEnum::QR_CODE,
                    codeLength: 8,
                    prefix: $this->prefix,
                    maxUses: $this->uses,
                    eventWide: true
                )->execute();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
