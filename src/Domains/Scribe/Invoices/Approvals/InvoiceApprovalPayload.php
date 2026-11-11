<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Approvals;

use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\BuyerTaxId;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * What the approver is shown, frozen into the request payload: for an e-CF, exactly what DGII will
 * receive. The generic approvals domain does not stop the draft being edited between submission and
 * sign-off, so the handler re-hashes the draft and refuses to issue one that no longer matches.
 */
final class InvoiceApprovalPayload
{
    public const string HASH_KEY = 'draft_hash';

    /**
     * @return array<string, mixed>
     */
    public static function for(Invoice $invoice, EcfIssuanceService $ecf = new EcfIssuanceService()): array
    {
        $frozen = self::frozenFields($invoice, $ecf);

        return [
            ...$frozen,
            'customer' => $invoice->billable_display_name ?? $invoice->customer?->name,
            self::HASH_KEY => self::hash($frozen),
        ];
    }

    public static function currentHash(Invoice $invoice, EcfIssuanceService $ecf = new EcfIssuanceService()): string
    {
        return self::hash(self::frozenFields($invoice, $ecf));
    }

    /**
     * @return array<string, mixed>
     */
    private static function frozenFields(Invoice $invoice, EcfIssuanceService $ecf): array
    {
        return [
            'document_type' => $invoice->document_type->value,
            'customer_organization_id' => $invoice->customer_organization_id,
            'customer_tax_id' => BuyerTaxId::of($invoice),
            'ecf_type' => $ecf->ecfTypeFor($invoice)?->code(),
            'currency' => $invoice->currency,
            'tax_native' => round((float) $invoice->tax_native, 4),
            'total_native' => round((float) $invoice->total_native, 4),
            'line_count' => $invoice->lines()->count(),
        ];
    }

    /**
     * @param array<string, mixed> $frozen
     */
    private static function hash(array $frozen): string
    {
        return hash('sha256', (string) json_encode($frozen));
    }
}
