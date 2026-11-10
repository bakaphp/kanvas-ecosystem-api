<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Regional\Validators\DominicanRepublic;

use Kanvas\Scribe\Regional\DataTransferObject\NcfValidation;

/**
 * The NCF on a supplier's bill to us. Format first — `B` + type + 8 digits (paper series) or `E` + type
 * + 10 digits (e-NCF) — then whether the type is one a supplier issues to a business buyer. A consumer
 * invoice (B02/E32) carries no ITBIS credit, credit and debit notes are not bills, and B11/B13/E41/E43/
 * E47 are issued by the buyer itself, so each of those asks the supplier for a corrected invoice.
 */
class NcfValidator
{
    /** Types a supplier issues to a business: crédito fiscal, régimen especial, gubernamental. */
    public const array BILLABLE_TYPES = ['01', '14', '15', '31', '44', '45'];

    private const array TYPE_NAMES = [
        '02' => 'a consumer invoice (no ITBIS credit)',
        '32' => 'a consumer invoice (no ITBIS credit)',
        '03' => 'a debit note, not an invoice',
        '33' => 'a debit note, not an invoice',
        '04' => 'a credit note, not an invoice',
        '34' => 'a credit note, not an invoice',
        '11' => 'a purchase voucher the buyer issues',
        '41' => 'a purchase voucher the buyer issues',
        '13' => 'a minor-expense voucher the buyer issues',
        '43' => 'a minor-expense voucher the buyer issues',
        '47' => 'a payments-abroad voucher the buyer issues',
    ];

    public function validate(?string $ncf): NcfValidation
    {
        $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', (string) $ncf));

        if ($normalized === '') {
            return new NcfValidation(
                ncf: null,
                series: null,
                type: null,
                code: NcfValidation::MISSING,
                reason: 'The invoice shows no NCF, so it cannot support an ITBIS credit or the 606 report.',
            );
        }

        if (! preg_match('/^(?:(B)(\d{2})\d{8}|(E)(\d{2})\d{10})$/', $normalized, $matches)) {
            return new NcfValidation(
                ncf: $normalized,
                series: null,
                type: null,
                code: NcfValidation::BAD_FORMAT,
                reason: "\"{$normalized}\" is not a valid NCF (B + 2-digit type + 8 digits, or E + 2-digit type + 10 digits).",
            );
        }

        $series = ($matches[1] ?? '') !== '' ? 'B' : 'E';
        $type = $series === 'B' ? $matches[2] : $matches[4];

        if (! in_array($type, self::BILLABLE_TYPES, true)) {
            $what = self::TYPE_NAMES[$type] ?? 'not a type a supplier issues to a business';

            return new NcfValidation(
                ncf: $normalized,
                series: $series,
                type: $type,
                code: NcfValidation::WRONG_TYPE,
                reason: "NCF {$normalized} is type {$type}, {$what}. Ask the supplier for a crédito fiscal invoice ({$series}" . ($series === 'B' ? '01' : '31') . ').',
            );
        }

        return new NcfValidation(ncf: $normalized, series: $series, type: $type);
    }
}
