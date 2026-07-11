<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * One line of an Acumatica GL batch (dbo.GLTran, joined to Account for AccountCD + Sub for SubCD).
 * Account / subaccount resolution (code → Kanvas id) happens in PullJournalEntriesAction.
 */
class AcumaticaImportJournalEntryLine extends Data
{
    public function __construct(
        public readonly string $accountCd,
        public readonly ?string $subCode,
        public readonly float $debitNative,
        public readonly float $creditNative,
        public readonly float $debitBase,
        public readonly float $creditBase,
        public readonly string $currency,
        public readonly ?string $memo,
    ) {
    }
}
