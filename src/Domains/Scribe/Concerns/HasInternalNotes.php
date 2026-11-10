<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Concerns;

use Illuminate\Support\Carbon;

/**
 * Timestamped, append-only staff notes on a Scribe document (`internal_notes`). Does not save: the caller
 * decides when the write lands, usually alongside its own change.
 */
trait HasInternalNotes
{
    public function appendInternalNote(string $note): string
    {
        $stamped = '[' . Carbon::now()->toDateTimeString() . '] ' . $note;

        $this->internal_notes = $this->internal_notes !== null && $this->internal_notes !== ''
            ? $this->internal_notes . "\n" . $stamped
            : $stamped;

        return $stamped;
    }
}
