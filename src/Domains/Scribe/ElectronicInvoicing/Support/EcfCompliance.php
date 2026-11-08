<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Support;

use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Two halves. Before a number exists, the regime's draft choices (DR: type, export block, purchase order,
 * withholding, foreign payment) live in `regional_compliance.{cc}_ecf` on the document (`do_ecf` in the DR): mutable,
 * saved by the caller. From allocation on, the state is the entity's current `fiscal_documents` row, written only
 * through the fiscal actions. `read()` merges both into the flat shape the rest of Scribe was written against.
 */
final class EcfCompliance
{

    /**
     * What the pre-fiscal-table block kept after allocation. Never read from the JSON any more, so a block the
     * backfill has not cleaned yet cannot shadow the fiscal row.
     */
    public const array POST_ALLOCATION_KEYS = [
        'encf', 'expires_at', 'provider', 'provider_ref', 'status', 'messages', 'security_code', 'signed_at',
        'qr_url', 'qr_legends', 'references', 'burned', 'submitted_at', 'updated_at', 'poll_attempts',
        'poll_exhausted', 'action_required', 'void_error', 'submission_failed', 'submission_error', 'direction',
        'issue_date', 'vendor_rnc', 'stamp_url', 'commercial_response',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function draftSettings(Invoice|Bill|Expense $document, ?string $key = null): array
    {
        return array_diff_key(self::rawBlock($document, $key), array_flip(self::POST_ALLOCATION_KEYS));
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function writeDraftSettings(Invoice|Bill|Expense $document, array $changes): void
    {
        $key = self::key($document) ?? throw new ValidationException(
            "Company {$document->companies_id} has no electronic-invoicing regime, so the document has no fiscal choices to set."
        );

        $compliance = $document->regional_compliance ?? [];
        $compliance[$key] = [...self::rawBlock($document, $key), ...$changes];
        $document->regional_compliance = $compliance;
    }

    /**
     * The key defaults to the document's company regime; empty for a company with none.
     *
     * @return array<string, mixed>
     */
    public static function rawBlock(Invoice|Bill|Expense $document, ?string $key = null): array
    {
        $key ??= self::key($document);

        return $key !== null ? (array) (($document->regional_compliance ?? [])[$key] ?? []) : [];
    }

    public static function key(Invoice|Bill|Expense $document): ?string
    {
        $company = $document->company;
        $country = $company instanceof Companies ? FiscalRegimes::forCompany($company)?->country() : null;

        return $country !== null ? self::keyFor($country) : null;
    }

    public static function keyFor(string $country): string
    {
        return strtolower($country) . '_ecf';
    }

    public static function current(Invoice|Bill|Expense $document): ?FiscalDocument
    {
        return FiscalDocument::currentFor($document, FiscalDirectionEnum::ISSUED);
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(Invoice|Bill|Expense $document): array
    {
        $current = self::current($document);

        return $current === null
            ? self::draftSettings($document)
            : [...self::draftSettings($document), ...($current->regime_data ?? []), ...self::project($current)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function project(FiscalDocument $fiscal): array
    {
        $authority = $fiscal->authority_data ?? [];
        $latest = $fiscal->latestSubmission;

        return [
            ...array_intersect_key($authority, array_flip([
                'poll_exhausted', 'action_required', 'void_error', 'submission_failed', 'submission_error',
            ])),
            'fiscal_document_id' => $fiscal->getKey(),
            'fiscal_uuid' => $fiscal->uuid,
            'fiscal_status' => $fiscal->status->value,
            'direction' => $fiscal->direction->value,
            'environment' => $fiscal->environment,
            'encf' => $fiscal->number,
            'type' => $fiscal->document_type,
            'expires_at' => $fiscal->regimeValue('expires_at'),
            'issue_date' => $fiscal->regimeValue('issue_date') ?? $fiscal->issued_at?->toDateString(),
            'references' => $fiscal->regimeValue('references'),
            'provider' => $fiscal->provider,
            'provider_ref' => $latest?->provider_ref ?? ($authority['provider_ref'] ?? null),
            'status' => $fiscal->status->toEcfStatus()->value,
            'messages' => $authority['messages'] ?? [],
            'security_code' => $fiscal->authority_seal,
            'signed_at' => $authority['signed_at'] ?? $fiscal->signed_at?->toIso8601String(),
            'qr_url' => $authority['qr_url'] ?? null,
            'qr_legends' => $authority['qr_legends'] ?? [],
            'authority_id' => $fiscal->authority_id,
            'number_consumed' => $fiscal->authority_number_consumed,
            'burned' => $fiscal->isBurned(),
            'needs_review' => $fiscal->needs_review,
            'submitted_at' => $latest?->sent_at?->toIso8601String(),
            'poll_attempts' => (int) ($authority['poll_attempts'] ?? 0),
            'updated_at' => $fiscal->last_authority_update_at?->toIso8601String(),
        ];
    }

    /**
     * A burned number no longer counts: a bill or expense whose e-CF was burned can be issued again.
     */
    public static function hasNumber(Invoice|Bill|Expense $document): bool
    {
        $current = self::current($document);

        return $current !== null && ! $current->isBurned();
    }

    /**
     * The authority's id for the document, for ledger payloads. In the DR that is the e-NCF itself.
     */
    public static function number(Invoice|Bill|Expense $document): ?string
    {
        $current = self::current($document);

        return $current?->authority_id ?? $current?->number;
    }

    public static function status(Invoice|Bill|Expense $document): ?EcfStatusEnum
    {
        return self::current($document)?->status->toEcfStatus();
    }

    /**
     * Ledger events keep the entity in their name (scribe.invoice.ecf_accepted, scribe.bill.ecf_accepted),
     * so a reader filtering on the invoice events never sees a bill's.
     */
    public static function eventName(Invoice|Bill|Expense $document, string $event): string
    {
        $entity = match (true) {
            $document instanceof Bill => 'bill',
            $document instanceof Expense => 'expense',
            default => 'invoice',
        };

        return "scribe.{$entity}.{$event}";
    }
}
