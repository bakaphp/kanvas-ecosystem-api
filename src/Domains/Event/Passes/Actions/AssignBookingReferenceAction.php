<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\DataTransferObject\EventAccess;

class AssignBookingReferenceAction
{
    private const int NUMBER_PADDING = 4;

    public function __construct(
        private readonly EventAccess $access,
        private readonly EventVersion $booking,
    ) {
    }

    public function execute(): string
    {
        return DB::connection('event')->transaction(function (): string {
            Event::query()->whereKey($this->access->eventId)->lockForUpdate()->first();

            $reference = sprintf(
                '%s-%0' . self::NUMBER_PADDING . 'd',
                $this->access->referencePrefix,
                $this->lastNumber() + 1
            );

            $this->booking->forceFill(['reference' => $reference])->saveQuietly();

            return $reference;
        });
    }

    private function lastNumber(): int
    {
        $prefix = $this->access->referencePrefix;

        return (int) EventVersion::query()
            ->where('apps_id', $this->booking->apps_id)
            ->where('reference', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->max(DB::raw('CAST(SUBSTRING(reference, ' . (strlen($prefix) + 2) . ') AS UNSIGNED)'));
    }
}
