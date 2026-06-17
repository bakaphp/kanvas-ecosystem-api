<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject;

use Carbon\Carbon;

/**
 * Outbound (Kanvas → Reynolds) standalone note projection for the
 * `Record.Note` section of an Update Sales Lead (USL) transaction.
 *
 * Reynolds USL has four sub-flows (Activity / Appointment / Note / Consent).
 * This DTO covers the Note sub-flow only.
 */
class Note
{
    public function __construct(
        public readonly string $prospectNote,
        public readonly Carbon $noteInsertedOn,
        public readonly ?Carbon $noteUpdatedOn = null,
    ) {
    }

    /**
     * Build the `<Note>` XML section payload.
     */
    public function toRecord(): array
    {
        $inserted = $this->noteInsertedOn->format('Y-m-d\TH:i:s');
        $updated = ($this->noteUpdatedOn ?? $this->noteInsertedOn)->format('Y-m-d\TH:i:s');

        return [
            'NoteInsertedOn' => $inserted,
            'ProspectNote' => $this->prospectNote,
            'NoteUpdatedOn' => $updated,
        ];
    }
}
